<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Email\Config\ImapConfig;
use Infocyph\TalkingBytes\Email\Config\Pop3Config;
use Infocyph\TalkingBytes\Email\Email;
use Infocyph\TalkingBytes\Email\Enum\ImapSecurity;
use Infocyph\TalkingBytes\Email\Enum\Pop3Security;
use Infocyph\TalkingBytes\Email\Exception\MailboxAuthenticationException;
use Infocyph\TalkingBytes\Email\Mailbox\MailboxSearch;

function imapWorkload(): void
{
    $mailbox = Email::mailbox()->usingImap(new ImapConfig('127.0.0.1', 'labmail', 'fixture-only-password', security: ImapSecurity::None, port: 11430));
    $mailbox->connect();
    try {
        $inbox = $mailbox->folder('INBOX');
        verify($inbox->status()->messages === 12, 'IMAP seed count mismatch.');
        $refs = $inbox->query(MailboxSearch::new()->unseen()->from('billing@example.test')->subjectContains('Lab invoice')->limit(12));
        verify(count($refs) === 12, 'IMAP server search mismatch.');
        $uids = array_map(static fn($ref): int => $ref->uid, $refs);
        verify(count(array_unique($uids)) === 12, 'Duplicate IMAP UID.');
        $uid = $uids[0];
        $parsed = $inbox->fetchParsed($uid);
        verify(str_starts_with($parsed->subjectOrEmpty(), 'Lab invoice ') && $parsed->fromEmail() === 'billing@example.test', 'IMAP MIME fetch mismatch.');
        verify(str_contains($inbox->fetchRaw($uid), '.dot-prefixed line'), 'IMAP raw body lost content.');
        $inbox->markSeenMany(array_slice($uids, 0, 3));
        verify(count($inbox->query(MailboxSearch::new()->seen()->limit(12))) === 3, 'IMAP seen flags mismatch.');
        $inbox->markUnreadMany(array_slice($uids, 0, 3));
        verify(count($inbox->query(MailboxSearch::new()->unseen()->limit(12))) === 12, 'IMAP unread flags mismatch.');
        $mailbox->createFolder('LabArchive');
        $mailbox->subscribeFolder('LabArchive');
        $inbox->copy($uid, 'LabArchive');
        $inbox->move($uids[1], 'LabArchive');
        verify($mailbox->status('LabArchive')->messages === 2 && $inbox->status()->messages === 11, 'IMAP copy/move count mismatch.');
        $mailbox->renameFolder('LabArchive', 'LabComplete');
        verify($mailbox->folderExists('LabComplete') && !$mailbox->folderExists('LabArchive'), 'IMAP folder rename mismatch.');
        $complete = $mailbox->folder('LabComplete');
        $copies = $complete->query(MailboxSearch::new()->all()->limit(2));
        $complete->deleteMany(array_map(static fn($ref): int => $ref->uid, $copies));
        $complete->expunge();
        verify($complete->status()->messages === 0 && $inbox->status()->messages === 11, 'IMAP expunge crossed folder boundary.');
        $mailbox->unsubscribeFolder('LabComplete');
        $mailbox->deleteFolder('LabComplete');
        verify(!$mailbox->folderExists('LabComplete'), 'IMAP folder deletion failed.');
    } finally {
        $mailbox->logout();
    }
    $wrong = Email::mailbox()->usingImap(new ImapConfig('127.0.0.1', 'labmail', 'incorrect', security: ImapSecurity::None, port: 11430));
    expectFailure($wrong->connect(...), MailboxAuthenticationException::class, 'Invalid IMAP credentials accepted.');
    echo "Real IMAP search, fetch, flags, copy/move, folder lifecycle and authentication passed\n";
}

function pop3Workload(): void
{
    $connect = static fn() => Email::mailbox()->usingPop3(new Pop3Config('127.0.0.1', port: 11110, security: Pop3Security::None, username: 'labmail', password: 'fixture-only-password'));
    $mailbox = $connect();
    $mailbox->connect();
    try {
        verify($mailbox->status()->messages === 12, 'POP3 seed count mismatch.');
        $refs = $mailbox->listMessageRefs();
        $ids = array_map(static fn($ref): ?string => $ref->externalId, $refs);
        verify(count($ids) === 12 && !in_array(null, $ids, true) && count(array_unique($ids)) === 12, 'POP3 UIDL mismatch.');
        $number = $refs[0]->uid;
        verify(str_contains($mailbox->fetchRaw($number), "\r\n.dot-prefixed line"), 'POP3 dot transparency failed.');
        verify(str_starts_with($mailbox->fetchParsed($number)->subjectOrEmpty(), 'Lab invoice '), 'POP3 parsed message mismatch.');
        $mailbox->delete($number);
        verify($mailbox->status()->messages === 11, 'POP3 deletion mark failed.');
        $mailbox->reset();
        verify($mailbox->status()->messages === 12, 'POP3 RSET did not restore deletion mark.');
        $mailbox->delete($number);
    } finally {
        $mailbox->logout();
    }
    $replacement = $connect();
    $replacement->connect();
    try {
        $remaining = array_map(static fn($ref): ?string => $ref->externalId, $replacement->listMessageRefs());
        sort($remaining);
        $expected = array_slice($ids, 1);
        sort($expected);
        verify($remaining === $expected && $replacement->status()->messages === 11, 'POP3 QUIT/UIDL reconciliation failed.');
    } finally {
        $replacement->logout();
    }
    echo "Real POP3 UIDL, fetch, dot transparency, RSET and committed deletion passed\n";
}
