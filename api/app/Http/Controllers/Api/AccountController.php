<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccountResource;
use App\Http\Resources\LabelResource;
use App\Jobs\SyncAccountJob;
use App\Models\Account;
use App\Services\Mail\AttachmentService;
use App\Services\Mail\ContactBook;
use App\Services\Mail\Providers\MailProvider;
use App\Support\AppPresence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    /** Message counters shown next to each account (hidden = deleted in Gmail, kept locally). */
    private static function COUNTS(): array
    {
        return [
            'messages' => fn ($q) => $q->visible(),
            'messages as unread_count' => fn ($q) => $q->visible()->where('is_unread', true)->whereJsonContains('label_ids', 'INBOX'),
        ];
    }

    public function index(): AnonymousResourceCollection
    {
        // The open window polls this every few seconds: scheduled fetching runs only then.
        AppPresence::touch();

        $accounts = Account::query()->withCount(self::COUNTS())->orderBy('created_at')->get();

        return AccountResource::collection($accounts);
    }

    public function show(Account $account): AccountResource
    {
        $account->loadCount(self::COUNTS());

        return new AccountResource($account);
    }

    /**
     * Remove a mailbox. The caller must say what happens to the local copy:
     *   keep_mail=true  -> disconnect: tokens are wiped, mail stays on disk
     *                      (reconnecting later continues without a re-download)
     *   keep_mail=false -> delete the local copy (messages, attachments, drafts,
     *                      index, send receipts). Gmail itself is never touched.
     */
    public function destroy(Request $request, Account $account, AttachmentService $attachments): JsonResponse
    {
        $data = $request->validate(['keep_mail' => ['required', 'boolean']]);

        if ($data['keep_mail']) {
            $account->disconnect();

            return response()->json(['disconnected' => true, 'account' => new AccountResource($account)]);
        }

        $attachments->forgetAccount($account);
        $account->delete(); // FK cascades: messages, labels, contacts, drafts, embeddings, send receipts

        return response()->json(['deleted' => true]);
    }

    public function sync(Request $request, Account $account): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['nullable', Rule::in(MailProvider::MODES)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('sovera.sync.max_limit', 2000)],
            'since_days' => ['required_if:mode,'.MailProvider::MODE_RANGE, 'nullable', 'integer', 'min:0', 'max:36500'], // 0 = everything
        ]);

        if ($account->sync_status === Account::STATUS_NEEDS_REAUTH || $account->isDisconnected()) {
            return response()->json(['message' => 'This account must be reconnected before it can sync.'], 409);
        }

        if ($account->sync_status !== Account::STATUS_SYNCING) {
            $account->forceFill(['sync_status' => Account::STATUS_QUEUED])->save();
        }

        SyncAccountJob::dispatch(
            $account->id,
            $data['mode'] ?? MailProvider::MODE_INCREMENTAL,
            isset($data['limit']) ? (int) $data['limit'] : null,
            isset($data['since_days']) ? (int) $data['since_days'] : null,
        );

        return response()->json(['queued' => true, 'account' => new AccountResource($account)], 202);
    }

    /**
     * Recipient autocomplete (this account's address book only).
     */
    public function contacts(Request $request, Account $account, ContactBook $book): JsonResponse
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:200'], 'limit' => ['nullable', 'integer', 'min:1', 'max:25']]);

        $contacts = $book->search($account, (string) ($data['q'] ?? ''), (int) ($data['limit'] ?? 8));

        return response()->json(['data' => $contacts->map(fn ($c) => [
            'email' => $c->email,
            'name' => $c->name,
            'sent_count' => $c->sent_count,
            'seen_count' => $c->seen_count,
        ])->values()]);
    }

    public function labels(Account $account): AnonymousResourceCollection
    {
        return LabelResource::collection(
            $account->labels()->orderByRaw("CASE WHEN type = 'system' THEN 0 ELSE 1 END")->orderBy('name')->get()
        );
    }
}
