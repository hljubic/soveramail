<?php

namespace App\Services\Imap;

use App\Exceptions\ReauthRequiredException;
use App\Models\Account;
use Throwable;
use Webklex\PHPIMAP\ClientManager;

/**
 * Opens an authenticated IMAP session for an account (or for credentials
 * that are being tested before an account exists).
 */
class ImapSessionFactory
{
    public function open(Account $account): ImapSession
    {
        $c = $account->credentials['imap'] ?? null;
        if (! is_array($c) || empty($c['host'])) {
            throw new ReauthRequiredException('This account has no IMAP credentials. Reconnect it.');
        }

        return $this->openWith($c);
    }

    /** @param  array{host: string, port: int, encryption: string, username: string, password: string}  $imap */
    public function openWith(array $imap): ImapSession
    {
        $client = (new ClientManager)->make([
            'host' => $imap['host'],
            'port' => (int) $imap['port'],
            // webklex: 'ssl'/'tls' = implicit TLS from the first byte (993), 'starttls' =
            // plain connect then upgrade (143). Mapping starttls to 'tls' broke port 143.
            'encryption' => in_array($imap['encryption'] ?? 'ssl', ['ssl', 'tls', 'starttls'], true) ? $imap['encryption'] : false,
            'validate_cert' => true,
            'username' => $imap['username'],
            'password' => $imap['password'],
            'protocol' => 'imap',
            'timeout' => 30,
        ]);

        try {
            $client->connect();
        } catch (Throwable $e) {
            $message = $e->getMessage();
            if (preg_match('/AUTHENTICATIONFAILED|Invalid credentials|LOGIN failed|authentication failed|NO \[/i', $message)) {
                throw new ReauthRequiredException('IMAP login failed: check the address and the (app) password.', 0, $e);
            }
            throw new ImapConnectionException("Cannot connect to IMAP server {$imap['host']}:{$imap['port']}: {$message}", 0, $e);
        }

        return new WebklexImapSession($client);
    }
}
