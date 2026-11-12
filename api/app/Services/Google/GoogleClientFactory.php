<?php

namespace App\Services\Google;

use App\Exceptions\ReauthRequiredException;
use App\Models\Account;
use Google\Client;
use Google\Service\Oauth2;
use Google\Service\Oauth2\Userinfo;
use RuntimeException;

class GoogleClientFactory
{
    /**
     * Bare client with app credentials (used for the OAuth handshake).
     */
    public function make(): Client
    {
        $clientId = (string) config('sovera.google.client_id');
        $clientSecret = (string) config('sovera.google.client_secret');

        if ($clientId === '' || $clientSecret === '') {
            throw new RuntimeException('GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET are not configured.');
        }

        $client = new Client;
        $client->setApplicationName(config('app.name', 'Sovera Mail'));
        $client->setClientId($clientId);
        $client->setClientSecret($clientSecret);
        $client->setRedirectUri((string) config('sovera.google.redirect_uri'));
        $client->setScopes((array) config('sovera.google.scopes'));
        $client->setAccessType('offline');
        $client->setIncludeGrantedScopes(true);

        return $client;
    }

    /**
     * Who the freshly authorised token belongs to (OpenID userinfo).
     */
    public function userinfo(Client $client): Userinfo
    {
        return (new Oauth2($client))->userinfo->get();
    }

    /**
     * Authorised client for an account. Refreshes (and persists) the access
     * token when it is expired or about to expire.
     *
     * @throws ReauthRequiredException when the refresh token is gone/revoked
     */
    public function forAccount(Account $account): Client
    {
        $client = $this->make();

        if (! $account->access_token) {
            throw new ReauthRequiredException('Account has no access token.');
        }

        $expiresIn = $account->token_expires_at
            ? max(0, (int) now()->diffInSeconds($account->token_expires_at, false))
            : 0;

        $client->setAccessToken([
            'access_token' => $account->access_token,
            'refresh_token' => $account->refresh_token,
            'expires_in' => $expiresIn,
            'created' => time(),
        ]);

        if ($account->token_expired) {
            $this->refresh($account, $client);
        }

        return $client;
    }

    /**
     * Fetch a new access token with the stored refresh token, persist it and
     * put it on $client. Used up front (expired token) and by long-running
     * syncs whose token runs out half-way (it lives one hour).
     *
     * @throws ReauthRequiredException when Google refuses the refresh token
     */
    public function refresh(Account $account, Client $client): Client
    {
        if (! $account->refresh_token) {
            $account->markNeedsReauth();
            throw new ReauthRequiredException('No refresh token stored for '.$account->email);
        }

        $token = $client->fetchAccessTokenWithRefreshToken($account->refresh_token);

        if (! is_array($token) || isset($token['error']) || empty($token['access_token'])) {
            $error = is_array($token) ? ($token['error_description'] ?? $token['error'] ?? 'unknown') : 'unknown';
            $account->markNeedsReauth("Token refresh failed: {$error}");
            throw new ReauthRequiredException("Token refresh failed for {$account->email}: {$error}");
        }

        $account->forceFill([
            'access_token' => $token['access_token'],
            'refresh_token' => $token['refresh_token'] ?? $account->refresh_token,
            'token_expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 3600)),
        ])->save();

        $client->setAccessToken($token);

        return $client;
    }
}
