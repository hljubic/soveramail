<?php

namespace App\Services\Imap;

use App\Exceptions\ReauthRequiredException;
use App\Models\Account;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Email;

class SymfonySmtpSender implements SmtpSender
{
    public function send(Account $account, Email $email): void
    {
        $smtp = $account->credentials['smtp'] ?? null;
        if (! is_array($smtp) || empty($smtp['host'])) {
            throw new ReauthRequiredException('This account has no SMTP credentials. Reconnect it.');
        }

        $this->transport($smtp)->send($email);
    }

    public function verify(array $smtp): void
    {
        $transport = $this->transport($smtp);
        try {
            $transport->start();
            $transport->stop();
        } catch (TransportExceptionInterface $e) {
            if (preg_match('/535|authentication|credentials/i', $e->getMessage())) {
                throw new ReauthRequiredException('SMTP login failed: check the address and the (app) password.', 0, $e);
            }
            throw new ImapConnectionException("Cannot connect to SMTP server {$smtp['host']}:{$smtp['port']}: {$e->getMessage()}", 0, $e);
        }
    }

    /** @param  array{host: string, port: int, encryption: string, username: string, password: string}  $smtp */
    private function transport(array $smtp): EsmtpTransport
    {
        $encryption = $smtp['encryption'] ?? 'starttls';
        // tls=true means implicit TLS (SMTPS, usually 465); on a plain connection
        // STARTTLS is negotiated automatically unless the user chose "none".
        $transport = new EsmtpTransport($smtp['host'], (int) $smtp['port'], $encryption === 'ssl');
        if ($encryption === 'none') {
            $transport->setAutoTls(false);
        } elseif ($encryption === 'starttls') {
            // Refuse to log in over plain text when the server (or someone on the
            // network stripping it from EHLO) does not offer STARTTLS.
            $transport->setRequireTls(true);
        }
        if (! empty($smtp['username'])) {
            $transport->setUsername((string) $smtp['username'])->setPassword((string) ($smtp['password'] ?? ''));
        }

        return $transport;
    }
}
