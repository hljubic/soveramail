<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Services\Gmail\GmailOAuth;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class GoogleAuthController extends Controller
{
    public function __construct(private readonly GmailOAuth $oauth) {}

    /**
     * The UI opens this URL in a new browser tab.
     */
    public function url(): JsonResponse
    {
        if (! config('sovera.google.client_id') || ! config('sovera.google.client_secret')) {
            return response()->json([
                'message' => 'Google OAuth is not configured. Set GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET in .env and restart the API.',
            ], 503);
        }

        return response()->json(['url' => $this->oauth->authorizationUrl()]);
    }

    /**
     * Google redirects the browser here. Renders a small page; the Sovera tab
     * that started the sign-in notices the new account by itself.
     */
    public function callback(Request $request): View
    {
        if ($request->filled('error')) {
            return $this->failed('Google sign-in was cancelled ('.$request->string('error').').');
        }

        try {
            $account = $this->oauth->handleCallback(
                (string) $request->query('code', ''),
                (string) $request->query('state', ''),
            );
        } catch (Throwable $e) {
            report($e);

            return $this->failed($e->getMessage());
        }

        if ($account->sync_status === Account::STATUS_NEEDS_REAUTH) {
            return $this->failed((string) $account->sync_error);
        }

        return view('oauth.result', [
            'ok' => true,
            'email' => $account->email,
            'message' => "{$account->email} is connected. The first sync has started.",
            'returnUrl' => self::returnUrl(),
        ]);
    }

    private function failed(string $message): View
    {
        return view('oauth.result', [
            'ok' => false,
            'message' => $message,
            'returnUrl' => self::returnUrl(),
        ]);
    }

    private static function returnUrl(): string
    {
        return rtrim((string) config('sovera.frontend_url', 'http://localhost:3000'), '/').'/accounts';
    }
}
