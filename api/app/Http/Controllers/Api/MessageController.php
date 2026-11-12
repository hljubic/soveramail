<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ReauthRequiredException;
use App\Http\Controllers\Controller;
use App\Http\Resources\MessageDetailResource;
use App\Http\Resources\MessageListResource;
use App\Jobs\SyncMessageFlagsJob;
use App\Models\Account;
use App\Models\Message;
use App\Services\Ai\Style\DraftFeedbackService;
use App\Services\Gmail\GmailClient;
use App\Services\Mail\DuplicateSendException;
use App\Services\Mail\MessageStateService;
use App\Services\Mail\OutgoingMailService;
use App\Services\Mail\SendNotAllowedException;
use Closure;
use Google\Service\Exception as GoogleServiceException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\Mime\Address;
use Throwable;

class MessageController extends Controller
{
    private const FOLDERS = ['all', 'inbox', 'starred', 'unread', 'sent', 'drafts', 'important', 'archive', 'spam', 'trash'];

    /**
     * Cursor-paginated, newest first (or oldest first with sort=oldest).
     *
     *  - conversations=1: one row per thread (its newest message in the view), with
     *    thread_count, thread_unread and the urgency of its newest judged unread mail
     *  - thread=<id>: every message of one conversation, oldest first (the reading view)
     */
    public function index(Request $request, Account $account): AnonymousResourceCollection
    {
        $data = $request->validate([
            'folder' => ['nullable', Rule::in(self::FOLDERS)],
            'label' => ['nullable', 'string', 'max:191'],
            'q' => ['nullable', 'string', 'max:200'],
            'sort' => ['nullable', Rule::in(['newest', 'oldest'])],
            // A local category: every folder, only conversations sorted into it.
            'category' => ['nullable', 'integer'],
            'conversations' => ['nullable', 'boolean'],
            'thread' => ['nullable', 'string', 'max:191'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'cursor' => ['nullable', 'string'],
        ]);
        $direction = ($data['sort'] ?? 'newest') === 'oldest' ? 'asc' : 'desc';

        $filtered = Message::query()->where('account_id', $account->id);

        if (! empty($data['thread'])) {
            $filtered->visible()->where('thread_id', $data['thread']);
            $direction = 'asc';
        } elseif (! empty($data['category'])) {
            $filtered->visible()
                ->whereJsonDoesntContain('label_ids', 'SPAM')
                ->whereJsonDoesntContain('label_ids', 'TRASH')
                ->whereExists(fn ($q) => $q->selectRaw('1')->from('thread_categories')
                    ->whereColumn('thread_categories.account_id', 'messages.account_id')
                    ->whereColumn('thread_categories.thread_id', 'messages.thread_id')
                    ->where('thread_categories.category_id', (int) $data['category']));
        } elseif (! empty($data['label'])) {
            $filtered->withLabel($data['label']);
        } else {
            $filtered->inFolder($data['folder'] ?? 'inbox');
        }

        if (! empty($data['q'])) {
            $filtered->search($data['q']);
        }

        $query = (clone $filtered)->select([
            'id', 'account_id', 'provider_message_id', 'thread_id', 'subject', 'from_name', 'from_email',
            'to_recipients', 'snippet', 'label_ids', 'is_unread', 'is_starred', 'has_attachments', 'is_risky', 'has_caution', 'risk_acknowledged_at',
            'sent_at', 'received_at', 'urgency',
        ]);

        if (empty($data['thread']) && $request->boolean('conversations')) {
            // Newest message of each thread within this view (window function: MySQL 8, SQLite 3.25+).
            $ranked = (clone $filtered)->select('id')->selectRaw(
                'ROW_NUMBER() OVER (PARTITION BY COALESCE(thread_id, provider_message_id) ORDER BY received_at DESC, id DESC) AS rn'
            );
            $query->whereIn('id', DB::query()->fromSub($ranked, 'ranked')->where('rn', 1)->select('id'));

            $sameThread = fn ($q) => $q->from('messages as t')
                ->whereColumn('t.account_id', 'messages.account_id')
                ->whereColumn('t.thread_id', 'messages.thread_id')
                ->whereNull('t.remote_deleted_at');
            $query->selectSub(fn ($q) => $sameThread($q)->selectRaw('COUNT(*)'), 'thread_count')
                ->selectSub(fn ($q) => $sameThread($q)->where('t.is_unread', true)->selectRaw('COUNT(*)'), 'thread_unread')
                ->selectSub(fn ($q) => $sameThread($q)->where('t.is_unread', true)->whereNotNull('t.urgency')
                    ->orderByDesc('t.received_at')->limit(1)->select('t.urgency'), 'thread_urgency')
                ->selectSub(fn ($q) => $sameThread($q)->whereExists(fn ($d) => $d->selectRaw('1')->from('local_drafts')->whereColumn('local_drafts.message_id', 't.id'))
                    ->selectRaw('COUNT(*)'), 'thread_drafts')
                ->selectSub(fn ($q) => $q->from('thread_categories')
                    ->whereColumn('thread_categories.account_id', 'messages.account_id')
                    ->whereColumn('thread_categories.thread_id', 'messages.thread_id')
                    ->limit(1)->select('category_id'), 'category_id')
                // Attachment chips under the row (real attachments, not inline images).
                ->with(['attachments' => fn ($q) => $q->where('is_inline', false)->select(['id', 'message_id', 'filename', 'mime_type', 'risk_level'])]);
        }

        $messages = $query
            ->withExists('localDraft as has_draft')
            ->orderBy('received_at', $direction)
            ->orderBy('id', $direction)
            ->cursorPaginate((int) ($data['per_page'] ?? (empty($data['thread']) ? 50 : 200)));

        $resource = MessageListResource::collection($messages);

        // "1–50 of 4,607": how many conversations the view holds (first page only).
        if (empty($data['thread']) && empty($data['cursor']) && $request->boolean('conversations')) {
            $total = (int) (clone $filtered)->selectRaw('COUNT(DISTINCT COALESCE(thread_id, provider_message_id)) AS c')->value('c');
            $resource->additional(['meta' => ['total' => $total]]);
        }

        return $resource;
    }

    public function show(Message $message): MessageDetailResource|JsonResponse
    {
        if ($message->isRiskLocked()) {
            return response()->json([
                'message' => 'This message carries a dangerous attachment. Confirm that you understand the risk before opening it.',
                'risk_locked' => true,
            ], 423);
        }

        $message->load(['attachments', 'localDraft']);

        return new MessageDetailResource($message);
    }

    /**
     * The user confirmed they know the message carries a dangerous attachment.
     * Unlocks reading the text; the attachments stay blocked forever.
     */
    public function acknowledgeRisk(Message $message): JsonResponse
    {
        if ($message->is_risky && $message->risk_acknowledged_at === null) {
            $message->forceFill(['risk_acknowledged_at' => now()])->save();
        }

        return response()->json(['data' => new MessageDetailResource($message->fresh()->load(['attachments', 'localDraft']))]);
    }

    /**
     * Read/unread and starred state. Local DB is updated immediately, Gmail is
     * mirrored when the account has write access.
     */
    /**
     * Bulk "Mark as read / unread" from the list's checkboxes. Read: every unread message of
     * the selected conversations. Unread: the selected (newest) message of each one, as the
     * classic clients do. Changed here at once; Gmail / IMAP follow in the background.
     */
    public function bulkReadState(Request $request, MessageStateService $state): JsonResponse
    {
        $data = $request->validate([
            'message_ids' => ['required', 'array', 'min:1', 'max:500'],
            'message_ids.*' => ['integer'],
            'unread' => ['required', 'boolean'],
        ]);
        $unread = (bool) $data['unread'];
        $picked = Message::query()->whereKey($data['message_ids'])->visible()->get();

        if ($unread) {
            $targets = $picked;
        } else {
            $targets = $picked->whereNull('thread_id')->values();
            foreach ($picked->whereNotNull('thread_id')->groupBy('account_id') as $accountId => $rows) {
                $targets = $targets->merge(Message::query()->where('account_id', $accountId)
                    ->whereIn('thread_id', $rows->pluck('thread_id')->unique())->visible()->where('is_unread', true)->get());
            }
        }

        $changed = $state->setUnreadLocally($targets, $unread);
        if ($changed !== []) {
            SyncMessageFlagsJob::dispatch($changed, 'UNREAD', $unread);
        }

        return response()->json(['data' => ['changed' => count($changed), 'conversations' => $picked->count()]]);
    }

    public function update(Request $request, Message $message, MessageStateService $state): JsonResponse
    {
        $data = $request->validate([
            'is_unread' => ['sometimes', 'boolean'],
            'is_starred' => ['sometimes', 'boolean'],
        ]);

        $remote = MessageStateService::REMOTE_OK;
        $remoteError = null;

        if (array_key_exists('is_unread', $data)) {
            $result = $state->setUnread($message, (bool) $data['is_unread']);
            $remote = $result['remote'];
            $remoteError = $result['remote_error'];
        }
        if (array_key_exists('is_starred', $data)) {
            $result = $state->setStarred($message, (bool) $data['is_starred']);
            if ($result['remote'] !== MessageStateService::REMOTE_OK) {
                $remote = $result['remote'];
                $remoteError = $result['remote_error'];
            }
        }

        return response()->json([
            'data' => new MessageDetailResource($message->load('attachments')),
            'remote' => $remote,
            'remote_error' => $remoteError,
        ]);
    }

    public function reply(Request $request, Message $message, OutgoingMailService $outgoing): JsonResponse
    {
        [$data, $suggestionId] = $this->validateSend($request);

        return $this->composed($message->account, fn () => $outgoing->reply($message, $data, $data['idempotency_key']), $suggestionId, $data['body']);
    }

    public function forward(Request $request, Message $message, OutgoingMailService $outgoing): JsonResponse
    {
        [$data, $suggestionId] = $this->validateSend($request);

        return $this->composed($message->account, fn () => $outgoing->forward($message, $data, $data['idempotency_key']), $suggestionId, $data['body']);
    }

    /**
     * New message (not a reply): send it.
     */
    public function sendNew(Request $request, Account $account, OutgoingMailService $outgoing): JsonResponse
    {
        [$data, $suggestionId] = $this->validateSend($request);

        return $this->composed($account, fn () => $outgoing->sendNew($account, $data, $data['idempotency_key']), $suggestionId, $data['body']);
    }

    /**
     * New message: save as Gmail draft.
     */
    public function draftNew(Request $request, Account $account, OutgoingMailService $outgoing): JsonResponse
    {
        $data = $this->validateCompose($request, draft: true);

        return $this->composed($account, fn () => $outgoing->saveNewDraft($account, $data));
    }

    /**
     * Save the composition as a Gmail draft (no send). Recipients may be empty.
     */
    public function draft(Request $request, Message $message, OutgoingMailService $outgoing): JsonResponse
    {
        $kind = $request->validate(['kind' => ['required', Rule::in(['reply', 'forward'])]])['kind'];
        $data = $this->validateCompose($request, draft: true);

        return $this->composed($message->account, fn () => $outgoing->saveDraft($message, $kind, $data));
    }

    /**
     * Run a send/draft operation and answer 201 with the stored copy, or map
     * the failure to an honest error response.
     *
     * @param  Closure(): Message  $operation
     */
    private function composed(Account $account, Closure $operation, ?int $suggestionId = null, ?string $body = null): JsonResponse
    {
        try {
            $stored = $operation();
        } catch (Throwable $e) {
            return $this->composeFailed($e, $account);
        }

        // Sent from an AI draft: learn what the user changed. Never allowed to fail the send.
        if ($suggestionId !== null) {
            try {
                app(DraftFeedbackService::class)->recordSend($account, $suggestionId, (string) $body, $stored);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return response()->json(['data' => new MessageDetailResource($stored->load('attachments'))], 201);
    }

    /**
     * Send payload plus, separately, the id of the AI draft it started from (kept out of
     * the payload so it does not take part in the idempotency hash).
     *
     * @return array{0: array<string, mixed>, 1: ?int}
     */
    private function validateSend(Request $request): array
    {
        $data = $this->validateCompose($request);
        $suggestionId = $request->validate(['ai_suggestion_id' => ['nullable', 'integer', 'min:1']])['ai_suggestion_id'] ?? null;

        return [$data, $suggestionId !== null ? (int) $suggestionId : null];
    }

    /** @return array<string, mixed> */
    private function validateCompose(Request $request, bool $draft = false): array
    {
        // Every recipient has to be a parseable RFC 5322 address ("Ana <ana@x.y>" or
        // "ana@x.y"). Checked here, before an idempotency key is claimed or Gmail is
        // contacted, so a typo is a 422 and never a half-done send.
        $address = function (string $attribute, mixed $value, Closure $fail): void {
            $value = trim((string) $value);
            if ($value === '') {
                return;
            }
            try {
                Address::create($value);
            } catch (Throwable) {
                $fail("\"{$value}\" is not a valid e-mail address.");
            }
        };

        $data = $request->validate([
            'to' => [$draft ? 'nullable' : 'required', 'array', $draft ? 'min:0' : 'min:1', 'max:50'],
            'to.*' => ['required', 'string', 'max:320', $address],
            'cc' => ['nullable', 'array', 'max:50'],
            'cc.*' => ['string', 'max:320', $address],
            'bcc' => ['nullable', 'array', 'max:50'],
            'bcc.*' => ['string', 'max:320', $address],
            'subject' => ['nullable', 'string', 'max:998'],
            'body' => ['present', 'nullable', 'string', 'max:500000'], // '' arrives as null (ConvertEmptyStringsToNull)
            'include_attachments' => ['nullable', 'boolean'],
            // Sending is intentionally a separate, explicit operation from
            // draft generation/saving. Each confirmed click gets one durable
            // key so duplicate HTTP requests cannot send duplicate mail.
            'confirm_send' => [$draft ? 'nullable' : 'required', $draft ? 'boolean' : 'accepted'],
            'idempotency_key' => [$draft ? 'nullable' : 'required', 'uuid'],
        ]);

        $result = [
            'to' => $data['to'] ?? [],
            'cc' => $data['cc'] ?? [],
            'bcc' => $data['bcc'] ?? [],
            'subject' => $data['subject'] ?? null,
            'body' => (string) ($data['body'] ?? ''),
            'include_attachments' => (bool) ($data['include_attachments'] ?? true),
        ];

        if (! $draft) {
            $result['idempotency_key'] = strtolower($data['idempotency_key']);
        }

        return $result;
    }

    /**
     * One place that turns send/draft failures into honest responses. The
     * `duplicate_prevented` flag tells the UI to lock the Send button.
     */
    private function composeFailed(Throwable $e, Account $account): JsonResponse
    {
        if ($e instanceof DuplicateSendException) {
            return response()->json(['message' => $e->getMessage(), 'duplicate_prevented' => true], 409);
        }
        if ($e instanceof SendNotAllowedException) {
            return response()->json(['message' => $e->getMessage(), 'needs_reauth' => true], 409);
        }
        if ($e instanceof ReauthRequiredException) {
            $account->markNeedsReauth($e->getMessage());

            return response()->json(['message' => $e->getMessage(), 'needs_reauth' => true], 409);
        }
        if ($e instanceof GoogleServiceException) {
            report($e);

            return response()->json(['message' => 'Gmail refused the request: '.GmailClient::friendlyMessage($e)], 502);
        }

        throw $e;
    }
}
