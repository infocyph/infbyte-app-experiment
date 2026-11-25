<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Email\Config\SmtpConfig;
use Infocyph\TalkingBytes\Email\Config\SmtpCredentials;
use Infocyph\TalkingBytes\Email\EmailMessage;
use Infocyph\TalkingBytes\Email\Enum\SmtpAuthMechanism;
use Infocyph\TalkingBytes\Email\Enum\SmtpSecurity;
use Infocyph\TalkingBytes\Email\Transport\SmtpTransport;

function smtpFaultMessage(): EmailMessage
{
    return EmailMessage::new()->from('sender@example.test')->to('receiver@example.test')
        ->subject('Fixture settlement')->text("First line\r\n.dot-stuffed line\r\nLast line")
        ->messageDetails(messageId: 'lab-smtp-identity@example.test')->prepare();
}

function smtpFaultRecords(string $mode): array
{
    $path = getenv('LAB_SMTP_ROOT') . '/' . $mode . '.jsonl';
    if (!is_file($path)) {
        return [];
    }
    return array_map(static fn(string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($path, FILE_IGNORE_NEW_LINES));
}

function smtpUncertainWorkload(): void
{
    $message = smtpFaultMessage();
    $send = static fn(int $port) => new SmtpTransport(new SmtpConfig('127.0.0.1', $port, SmtpSecurity::None, timeoutSeconds: 3))->send($message);
    $lost = $send(18125);
    $rejected = $send(18126);
    $accepted = $send(18127);
    verify(!$lost->successful && !$rejected->successful && $accepted->successful, 'SMTP settlement status mismatch.');
    $lostRecords = smtpFaultRecords('lost');
    $successRecords = smtpFaultRecords('success');
    verify(count($lostRecords) === 1 && count($successRecords) === 1 && smtpFaultRecords('reject') === []
        && is_file(getenv('LAB_SMTP_ROOT') . '/reject.observed'), 'Origin did not observe the expected DATA boundary.');
    verify($lostRecords[0]['sha256'] === $successRecords[0]['sha256'] && $lostRecords[0]['bytes'] > 0
        && $lostRecords[0]['message_id'] === $successRecords[0]['message_id']
        && str_contains($lostRecords[0]['message_id'], 'lab-smtp-identity@example.test'), 'Stable message identity or payload mismatch.');
    verify(count(smtpFaultRecords('lost')) === 1, 'Ambiguous send automatically replayed.');
    echo "SMTP committed DATA response loss, explicit temporary rejection, confirmed send and stable identity passed\n";
}

function smtpTlsAuthWorkload(): void
{
    $root = (string) getenv('LAB_SMTP_ROOT');
    $credentials = new SmtpCredentials('lab-user', 'lab-password');
    $message = smtpFaultMessage();
    $send = static fn(int $port, SmtpSecurity $security, SmtpAuthMechanism $mechanism, string $ca) =>
        new SmtpTransport(new SmtpConfig('localhost', $port, $security, $credentials, timeoutSeconds: 3,
            authMechanism: $mechanism, captureTranscript: true, caBundle: $ca))->send($message);
    $untrusted = $send(18125, SmtpSecurity::StartTlsRequired, SmtpAuthMechanism::Plain, $root . '/foreign.pem');
    verify(!$untrusted->successful && smtpFaultRecords('tls') === [], 'Untrusted SMTP peer received delivery.');
    $downgrade = $send(18126, SmtpSecurity::StartTlsOptional, SmtpAuthMechanism::Plain, $root . '/cert.pem');
    verify(!$downgrade->successful && !is_file($root . '/plaintext-auth'), 'STARTTLS downgrade leaked credentials.');
    foreach ([
        [18125, SmtpSecurity::StartTlsRequired, SmtpAuthMechanism::Plain, 'tls'],
        [18125, SmtpSecurity::StartTlsRequired, SmtpAuthMechanism::Login, 'tls'],
        [18127, SmtpSecurity::Ssl, SmtpAuthMechanism::Plain, 'implicit'],
    ] as [$port, $security, $mechanism, $mode]) {
        $result = $send($port, $security, $mechanism, $root . '/cert.pem');
        verify($result->successful && $result->metadata['auth_mechanism'] === $mechanism->value, 'Verified SMTP authentication failed.');
        $records = smtpFaultRecords($mode);
        $record = end($records);
        verify(is_array($record) && $record['tls'] && $record['authenticated'], 'Peer did not observe authenticated TLS delivery.');
        $transcript = implode("\n", $result->metadata['smtp_transcript']);
        foreach (['lab-password', base64_encode('lab-password'), base64_encode("\0lab-user\0lab-password")] as $secret) {
            verify(!str_contains($transcript, $secret), 'SMTP transcript leaked authentication material.');
        }
    }
    verify(count(smtpFaultRecords('tls')) === 2 && count(smtpFaultRecords('implicit')) === 1
        && smtpFaultRecords('downgrade') === [], 'TLS delivery cardinality mismatch.');
    echo "SMTP verified STARTTLS/implicit TLS, PLAIN/LOGIN, untrusted CA, downgrade rejection and transcript redaction passed\n";
}
