<?php

namespace Tests\Support;

use App\Exceptions\ReauthRequiredException;
use App\Models\Account;
use App\Services\Imap\ImapSession;
use App\Services\Imap\ImapSessionFactory;

class FakeImapSessionFactory extends ImapSessionFactory
{
    /** Password the fake server accepts. */
    public string $acceptedPassword = 'app-password';

    public function __construct(public FakeImapSession $session) {}

    public function open(Account $account): ImapSession
    {
        return $this->openWith($account->credentials['imap'] ?? []);
    }

    public function openWith(array $imap): ImapSession
    {
        if (($imap['password'] ?? null) !== $this->acceptedPassword) {
            throw new ReauthRequiredException('IMAP login failed: check the address and the (app) password.');
        }

        return $this->session;
    }
}
