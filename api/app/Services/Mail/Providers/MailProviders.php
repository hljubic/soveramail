<?php

namespace App\Services\Mail\Providers;

use App\Models\Account;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Picks the backend for an account (`accounts.provider`).
 */
class MailProviders
{
    /** @var array<string, class-string<MailProvider>> */
    private const PROVIDERS = [
        Account::PROVIDER_GMAIL => GmailProvider::class,
        Account::PROVIDER_IMAP => ImapProvider::class,
    ];

    public function __construct(private readonly Container $container) {}

    public function for(Account $account): MailProvider
    {
        $class = self::PROVIDERS[$account->provider] ?? null;
        if ($class === null) {
            throw new InvalidArgumentException("Unknown mail provider \"{$account->provider}\".");
        }

        return $this->container->make($class);
    }
}
