<?php

declare(strict_types=1);

namespace Experiment;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Notifications\EmailProfiles;
use Infocyph\TalkingBytes\Email\EmailMessage;

final readonly class Mail
{
    public function __construct(private Application $app) {}

    public function setup(): void {}

    /** @throws \Throwable */
    public function execute(string $id): void
    {
        $result = $this->app->make(EmailProfiles::class)->sender()->send(
            EmailMessage::new()->from('orders@example.test')->to('customer@example.test')
                ->subject('experiment-' . $id)->text('Order receipt ' . $id),
        );
        ensure($result->successful, 'Actual SMTP delivery failed.');
    }

    /** @throws \Throwable */
    public function verify(int $expected): void
    {
        $actual = 0;
        $deadline = hrtime(true) + 15_000_000_000;
        do {
            $actual = $this->receiptCount($expected);
            if ($actual === $expected) {
                return;
            }
            ensure($actual < $expected, 'Unexpected excess SMTP receipts: ' . $actual);
            usleep(250_000);
        } while (hrtime(true) < $deadline);
        ensure(false, 'SMTP receipt count mismatch: expected ' . $expected . ', observed ' . $actual);
    }

    private function receiptCount(int $expected): int
    {
        $subjects = [];
        for ($start = 0; $start <= $expected; $start += 50) {
            $body = file_get_contents('http://127.0.0.1:8025/api/v1/messages?start=' . $start . '&limit=50');
            ensure(is_string($body), 'Mailpit inspection failed.');
            $page = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
            ensure(is_array($page['messages'] ?? null), 'Invalid Mailpit response.');
            foreach ($page['messages'] as $message) {
                $subject = $message['Subject'] ?? '';
                ensure(is_string($subject) && str_starts_with($subject, 'experiment-'), 'Unexpected mail subject.');
                ensure(!isset($subjects[$subject]), 'Duplicate mail receipt.');
                $subjects[$subject] = true;
            }
        }
        return count($subjects);
    }
}
