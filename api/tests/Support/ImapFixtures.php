<?php

namespace Tests\Support;

use App\Models\Account;
use App\Services\Imap\ImapSessionFactory;
use App\Services\Imap\SmtpSender;

/** Shared helpers for IMAP tests: an IMAP account, a fake server and raw messages. */
trait ImapFixtures
{
    protected FakeImapSession $imap;

    protected FakeSmtpSender $smtp;

    protected function fakeImap(): void
    {
        $this->imap = new FakeImapSession;
        $this->smtp = new FakeSmtpSender;
        $this->app->instance(ImapSessionFactory::class, new FakeImapSessionFactory($this->imap));
        $this->app->instance(SmtpSender::class, $this->smtp);
    }

    protected function imapAccount(array $attrs = []): Account
    {
        return $this->makeAccount(array_merge([
            'provider' => Account::PROVIDER_IMAP, 'email' => 'me@fastmail.com', 'display_name' => 'Me',
            'access_token' => null, 'refresh_token' => null, 'token_expires_at' => null, 'scopes' => [],
            'credentials' => [
                'imap' => ['host' => 'imap.fastmail.com', 'port' => 993, 'encryption' => 'ssl', 'username' => 'me@fastmail.com', 'password' => 'app-password'],
                'smtp' => ['host' => 'smtp.fastmail.com', 'port' => 465, 'encryption' => 'ssl', 'username' => 'me@fastmail.com', 'password' => 'app-password'],
            ],
            'initial_sync_done' => false, 'history_id' => null,
        ], $attrs));
    }

    protected function raw(string $from, string $to, string $subject, string $body, string $messageId, ?string $inReplyTo = null, ?string $references = null, string $date = 'Thu, 10 Sep 2026 10:00:00 +0200'): string
    {
        $h = "From: {$from}\r\nTo: {$to}\r\nSubject: {$subject}\r\nMessage-ID: <{$messageId}>\r\nDate: {$date}\r\n";
        if ($inReplyTo) {
            $h .= "In-Reply-To: <{$inReplyTo}>\r\n";
        }
        if ($references) {
            $h .= "References: {$references}\r\n";
        }

        return $h."MIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n{$body}\r\n";
    }

    protected function rawWithAttachment(string $from, string $to, string $subject, string $body, string $messageId, string $filename, string $mime, string $bytes): string
    {
        $b64 = chunk_split(base64_encode($bytes));

        return "From: {$from}\r\nTo: {$to}\r\nSubject: {$subject}\r\nMessage-ID: <{$messageId}>\r\nDate: Thu, 10 Sep 2026 10:00:00 +0200\r\nMIME-Version: 1.0\r\n"
            ."Content-Type: multipart/mixed; boundary=\"B1\"\r\n\r\n--B1\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n{$body}\r\n"
            ."--B1\r\nContent-Type: {$mime}; name=\"{$filename}\"\r\nContent-Disposition: attachment; filename=\"{$filename}\"\r\nContent-Transfer-Encoding: base64\r\n\r\n{$b64}--B1--\r\n";
    }
}
