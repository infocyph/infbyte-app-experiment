<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Email\Config\ImapConfig;
use Infocyph\TalkingBytes\Email\Config\Pop3Config;
use Infocyph\TalkingBytes\Email\Email;
use Infocyph\TalkingBytes\Email\Enum\ImapSecurity;
use Infocyph\TalkingBytes\Email\Enum\Pop3Security;
use Infocyph\TalkingBytes\Email\Exception\MailboxConnectionException;

function mailboxTlsWorkload(): void
{
    foreach ([[ImapSecurity::Ssl, 11993], [ImapSecurity::StartTlsRequired, 11430]] as [$security, $port]) {
        $client = Email::mailbox()->usingImap(new ImapConfig('localhost', 'labmail', 'fixture-only-password', security: $security, port: $port));
        $client->connect();
        try {
            $inbox = $client->folder('INBOX');
            verify($inbox->status()->messages === 1, 'TLS IMAP status mismatch.');
            $refs = $inbox->query();
            verify(count($refs) === 1 && $inbox->fetchParsed($refs[0]->uid)->subjectOrEmpty() === 'TLS fixture', 'TLS IMAP message mismatch.');
        } finally {
            $client->logout();
        }
    }
    foreach ([[Pop3Security::Ssl, 11995], [Pop3Security::StartTlsRequired, 11110]] as [$security, $port]) {
        $client = Email::mailbox()->usingPop3(new Pop3Config('localhost', port: $port, security: $security, username: 'labmail', password: 'fixture-only-password'));
        $client->connect();
        try {
            verify($client->status()->messages === 1 && $client->fetchParsed(1)->subjectOrEmpty() === 'TLS fixture', 'TLS POP3 message mismatch.');
        } finally {
            $client->logout();
        }
    }
    echo "Real IMAP TLS/STARTTLS and POP3 TLS/STLS passed\n";
}

function mailboxUntrustedWorkload(): void
{
    foreach ([[ImapSecurity::Ssl, 11993], [ImapSecurity::StartTlsRequired, 11430]] as [$security, $port]) {
        $client = Email::mailbox()->usingImap(new ImapConfig('localhost', 'labmail', 'fixture-only-password', security: $security, port: $port));
        expectFailure($client->connect(...), MailboxConnectionException::class, 'Untrusted IMAP TLS accepted.');
    }
    foreach ([[Pop3Security::Ssl, 11995], [Pop3Security::StartTlsRequired, 11110]] as [$security, $port]) {
        $client = Email::mailbox()->usingPop3(new Pop3Config('localhost', port: $port, security: $security, username: 'labmail', password: 'fixture-only-password'));
        expectFailure($client->connect(...), MailboxConnectionException::class, 'Untrusted POP3 TLS accepted.');
    }
}

function mailboxIdleWorkload(): void
{
    $client = Email::mailbox()->usingImap(new ImapConfig('localhost', 'labmail', 'fixture-only-password', security: ImapSecurity::Ssl, port: 11993, timeoutSeconds: 15));
    $client->connect();
    try {
        $inbox = $client->folder('INBOX');
        verify($inbox->status()->messages === 1, 'IDLE initial count mismatch.');
        verify(touch(getenv('LAB_MAIL_ROOT') . '/watch-ready'), 'IDLE fixture readiness unavailable.');
        $received = false;
        $inbox->watch(static function (string $line) use (&$received): void {
            if (preg_match('/^\* 2 EXISTS\b/i', $line) === 1) {
                $received = true;
            }
        }, 20, static function () use (&$received): bool { return $received; });
        verify($received && $inbox->status()->messages === 2, 'Actual IDLE arrival event missing or DONE failed.');
        verify(count($inbox->query()) === 2, 'Session unusable after IDLE.');
    } finally {
        $client->logout();
    }
    echo "Real IMAP IDLE delivery notification and DONE/session reuse passed\n";
}
