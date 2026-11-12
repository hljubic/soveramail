<?php

namespace App\Services\Gmail;

use App\Jobs\SyncAccountJob;
use App\Models\Account;
use App\Services\Google\GoogleClientFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

class GmailOAuth
{
    private const STATE_TTL_MINUTES = 15;

    public const MISSING_GMAIL_SCOPE_MESSAGE = 'Google did not grant access to your mailbox. Reconnect and allow Sovera Mail to access Gmail on the Google consent screen.';

    public function __construct(private readonly GoogleClientFactory $clients) {}

    /**
     * Build the Google consent URL. The `state` is stored server-side so the
     * callback can reject forged requests.
     */
    public function authorizationUrl(): string
    {
        $client = $this->clients->make();

        $state = Str::random(48);
        Cache::put($this->stateKey($state), true, now()->addMinutes(self::STATE_TTL_MINUTES));

        $client->setState($state);
        // `consent` guarantees a refresh token; `select_account` lets users add a second mailbox.
        $client->setPrompt('consent select_account');

        return $client->createAuthUrl();
    }

    /**
     * Exchange the authorization code, look up the mailbox identity and
     * create/update the Account. Kicks off the first sync.
     */
    public function handleCallback(string $code, string $state): Account
    {
        if ($state === '' || ! Cache::pull($this->stateKey($state))) {
            throw new RuntimeException('Invalid or expired OAuth state. Please start the sign-in again.');
        }

        $client = $this->clients->make();
        $token = $client->fetchAccessTokenWithAuthCode($code);

        if (! is_array($token) || isset($token['error']) || empty($token['access_token'])) {
            $error = is_array($token) ? ($token['error_description'] ?? $token['error'] ?? 'unknown error') : 'unknown error';
            throw new RuntimeException("Google did not return an access token: {$error}");
        }

        $client->setAccessToken($token);

        $userinfo = $this->clients->userinfo($client);
        $email = strtolower(trim((string) $userinfo->getEmail()));

        if ($email === '') {
            throw new RuntimeException('Google did not return an e-mail address for this account.');
        }

        $grantedScopes = array_values(array_filter(explode(' ', (string) ($token['scope'] ?? ''))));

        /** @var Account $account */
        $account = Account::query()->firstOrNew([
            'provider' => Account::PROVIDER_GMAIL,
            'email' => $email,
        ]);

        $account->fill([
            'display_name' => $userinfo->getName() ?: $account->display_name,
            'avatar_url' => $userinfo->getPicture() ?: $account->avatar_url,
            'access_token' => $token['access_token'],
            // Google only returns a refresh token on the first consent (or with prompt=consent).
            'refresh_token' => $token['refresh_token'] ?? $account->refresh_token,
            'token_expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 3600)),
            'scopes' => $grantedScopes,
        ]);

        if (! $account->canRead()) {
            // User unticked the Gmail permission on Google's granular consent screen.
            $account->fill([
                'sync_status' => Account::STATUS_NEEDS_REAUTH,
                'sync_error' => self::MISSING_GMAIL_SCOPE_MESSAGE,
            ])->save();

            return $account;
        }

        $account->fill(['sync_status' => Account::STATUS_QUEUED, 'sync_error' => null])->save();

        SyncAccountJob::dispatch($account->id);

        return $account;
    }

    private function stateKey(string $state): string
    {
        return 'oauth:google:state:'.$state;
    }
}
