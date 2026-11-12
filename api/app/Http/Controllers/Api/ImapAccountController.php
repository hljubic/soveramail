<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ReauthRequiredException;
use App\Http\Controllers\Controller;
use App\Http\Resources\AccountResource;
use App\Jobs\SyncAccountJob;
use App\Models\Account;
use App\Services\Imap\ImapConnectionException;
use App\Services\Imap\ImapSessionFactory;
use App\Services\Imap\SmtpSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Connect a mailbox over IMAP/SMTP (iCloud, Yahoo, Fastmail, hosting mail, ...).
 * Credentials are verified against both servers before anything is stored, and
 * stored encrypted (Eloquent `encrypted:array` cast) on the account.
 */
class ImapAccountController extends Controller
{
    /** Create (or re-connect an existing address) and queue the first sync. */
    public function store(Request $request, ImapSessionFactory $imap, SmtpSender $smtp): JsonResponse
    {
        $data = $this->validated($request);

        if ($error = $this->verify($imap, $smtp, $data)) {
            return $error;
        }

        /** @var Account $account */
        $account = Account::query()->firstOrNew(['provider' => Account::PROVIDER_IMAP, 'email' => $data['email']]);
        $account->fill([
            'display_name' => $data['display_name'] ?? $account->display_name,
            'credentials' => ['imap' => $data['imap'], 'smtp' => $data['smtp']],
            'scopes' => [],
            'sync_status' => Account::STATUS_QUEUED,
            'sync_error' => null,
        ])->save();

        SyncAccountJob::dispatch($account->id);

        return response()->json(['data' => new AccountResource($account->fresh())], 201);
    }

    /** New password / servers for an existing IMAP account. */
    public function update(Request $request, Account $account, ImapSessionFactory $imap, SmtpSender $smtp): JsonResponse
    {
        if (! $account->isImap()) {
            return response()->json(['message' => 'Not an IMAP account.'], 409);
        }
        $data = $this->validated($request, $account);

        if ($error = $this->verify($imap, $smtp, $data)) {
            return $error;
        }

        $account->fill([
            'display_name' => $data['display_name'] ?? $account->display_name,
            'credentials' => ['imap' => $data['imap'], 'smtp' => $data['smtp']],
            'sync_status' => Account::STATUS_QUEUED,
            'sync_error' => null,
        ])->save();
        SyncAccountJob::dispatch($account->id);

        return response()->json(['data' => new AccountResource($account->fresh())]);
    }

    /** @return array{email: string, display_name: ?string, imap: array, smtp: array} */
    private function validated(Request $request, ?Account $existing = null): array
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:1000'],
            'imap.host' => ['required', 'string', 'max:255'],
            'imap.port' => ['required', 'integer', 'min:1', 'max:65535'],
            'imap.encryption' => ['required', Rule::in(['ssl', 'starttls', 'none'])],
            'imap.username' => ['nullable', 'string', 'max:255'],
            'smtp.host' => ['required', 'string', 'max:255'],
            'smtp.port' => ['required', 'integer', 'min:1', 'max:65535'],
            'smtp.encryption' => ['required', Rule::in(['ssl', 'starttls', 'none'])],
            'smtp.username' => ['nullable', 'string', 'max:255'],
            'smtp.password' => ['nullable', 'string', 'max:1000'],
        ]);

        $email = strtolower(trim($data['email']));
        $imapUser = trim((string) ($data['imap']['username'] ?? '')) ?: $email;

        return [
            'email' => $existing?->email ?? $email,
            'display_name' => isset($data['display_name']) ? trim($data['display_name']) : null,
            'imap' => [
                'host' => trim($data['imap']['host']), 'port' => (int) $data['imap']['port'], 'encryption' => $data['imap']['encryption'],
                'username' => $imapUser, 'password' => $data['password'],
            ],
            'smtp' => [
                'host' => trim($data['smtp']['host']), 'port' => (int) $data['smtp']['port'], 'encryption' => $data['smtp']['encryption'],
                'username' => trim((string) ($data['smtp']['username'] ?? '')) ?: $imapUser, 'password' => $data['smtp']['password'] ?? $data['password'],
            ],
        ];
    }

    /** Log in to both servers; 422 with a per-field message when either refuses. */
    private function verify(ImapSessionFactory $imap, SmtpSender $smtp, array $data): ?JsonResponse
    {
        try {
            $imap->openWith($data['imap'])->close();
        } catch (ReauthRequiredException|ImapConnectionException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['imap.host' => [$e->getMessage()]]], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'IMAP check failed: '.$e->getMessage(), 'errors' => ['imap.host' => [$e->getMessage()]]], 422);
        }

        try {
            $smtp->verify($data['smtp']);
        } catch (ReauthRequiredException|ImapConnectionException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['smtp.host' => [$e->getMessage()]]], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'SMTP check failed: '.$e->getMessage(), 'errors' => ['smtp.host' => [$e->getMessage()]]], 422);
        }

        return null;
    }
}
