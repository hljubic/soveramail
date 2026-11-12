<?php

namespace App\Services\Gmail;

use App\Models\Account;
use App\Services\Google\GoogleClientFactory;

/**
 * Single place that turns an Account into an authorised GmailClient.
 * Bound as a singleton so tests can swap it for a fake.
 */
class GmailClientResolver
{
    public function __construct(private readonly GoogleClientFactory $factory) {}

    public function for(Account $account): GmailClient
    {
        return new GmailClient($account, $this->factory);
    }
}
