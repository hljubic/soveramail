<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MessageListResource;
use App\Jobs\EmbedAccountJob;
use App\Models\Account;
use App\Models\Message;
use App\Models\StyleProfile;
use App\Services\Ai\AiSettings;
use App\Services\Ai\AiUnavailableException;
use App\Services\Ai\DraftService;
use App\Services\Ai\Language\LanguageDetector;
use App\Services\Ai\Language\Languages;
use App\Services\Ai\OllamaClient;
use App\Services\Ai\RagSettings;
use App\Services\Ai\ReindexService;
use App\Services\Ai\Retrieval\EmbeddingService;
use App\Services\Ai\SmartSearchService;
use App\Services\Ai\Style\StyleProfileService;
use App\Services\Ai\SummaryService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AiController extends Controller
{
    public function settings(): JsonResponse
    {
        $settings = AiSettings::load();

        return self::settingsResponse($settings);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['nullable', Rule::in(['ollama'])],
            'base_url' => ['nullable', 'string', 'max:500'],
            'model' => ['nullable', 'string', 'max:200'],
            'embedding_model' => ['nullable', 'string', 'max:200'],
            'enabled' => ['nullable', 'boolean'],
            'user_prompt' => ['nullable', 'string', 'max:4000'],
            'streaming' => ['nullable', 'boolean'],
            'thinking' => ['nullable', 'boolean'],
            'classify_model' => ['nullable', 'string', 'max:200'],
            'summary_model' => ['nullable', 'string', 'max:200'],
            'summaries_in_pass' => ['nullable', 'boolean'],
            'summary_min_words' => ['nullable', 'integer', 'min:0', 'max:5000'],
            'summary_skip_bulk' => ['nullable', 'boolean'],
            'proofread' => ['nullable', 'boolean'],
            'classify_since_days' => ['nullable', 'integer', 'min:0', 'max:36500'], // 0/null = everything
            'allow_remote' => ['nullable', 'boolean'],
            'rag' => ['nullable', 'array'],
            'reply_language' => ['nullable', Rule::in(Languages::codes())],
        ] + RagSettings::rules());

        $before = AiSettings::load();
        // A language chosen (or cleared) here is the user's decision: automatic detection never touches it again.
        if (array_key_exists('reply_language', $data)) {
            $data['reply_language_source'] = 'user';
        }
        $settings = $before->with($data);

        try {
            AiSettings::assertLocal($settings->baseUrl, $settings->allowRemote);
        } catch (AiUnavailableException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['base_url' => [$e->getMessage()]]], 422);
        }

        $settings->save();

        // New embedding model -> (re)build the index in the background.
        if ($settings->canEmbed() && $settings->embeddingModel !== $before->embeddingModel) {
            Account::query()->pluck('id')->each(fn ($id) => EmbedAccountJob::dispatch((int) $id));
        }

        return self::settingsResponse($settings);
    }

    /**
     * Vector index progress per account.
     */
    public function indexStatus(EmbeddingService $embeddings): JsonResponse
    {
        $settings = AiSettings::load();

        $accounts = Account::query()->orderBy('created_at')->get()->map(fn (Account $a) => [
            'account_id' => $a->id,
            'email' => $a->email,
        ] + $embeddings->stats($a));

        return response()->json([
            'data' => [
                'enabled' => $settings->canEmbed(),
                'embedding_model' => $settings->embeddingModel,
                'accounts' => $accounts,
            ],
        ]);
    }

    /**
     * One click: rebuild the vector index, the writing style, the categories and
     * the urgency of unread mail for every account (hand-made choices are kept).
     */
    public function rebuildAll(ReindexService $reindex): JsonResponse
    {
        if (! AiSettings::load()->isReady()) {
            return response()->json(['message' => 'Pick a local model first (Configure AI).'], 409);
        }

        $accounts = Account::query()->get()->reject(fn (Account $a) => $a->isDisconnected());

        return response()->json(['data' => $accounts->mapWithKeys(fn (Account $a) => [$a->email => $reindex->rebuild($a)])], 202);
    }

    public function runIndex(): JsonResponse
    {
        if (! AiSettings::load()->canEmbed()) {
            return response()->json(['message' => 'Pick an embedding model first.'], 409);
        }

        Account::query()->pluck('id')->each(fn ($id) => EmbedAccountJob::dispatch((int) $id));

        return response()->json(['queued' => true], 202);
    }

    /**
     * Ping the model server and list installed models (uses the given base_url
     * without saving it, so the UI can test before saving).
     */
    public function probe(Request $request): JsonResponse
    {
        $data = $request->validate(['base_url' => ['nullable', 'string', 'max:500'], 'allow_remote' => ['nullable', 'boolean']]);
        $settings = AiSettings::load();
        $baseUrl = AiSettings::normaliseBaseUrl((string) ($data['base_url'] ?? $settings->baseUrl));

        try {
            AiSettings::assertLocal($baseUrl, array_key_exists('allow_remote', $data) ? (bool) $data['allow_remote'] : $settings->allowRemote);
            $info = (new OllamaClient($baseUrl))->info();
        } catch (AiUnavailableException $e) {
            return response()->json(['ok' => false, 'base_url' => $baseUrl, 'message' => $e->getMessage()], 503);
        }

        return response()->json(['ok' => true, 'base_url' => $baseUrl] + $info);
    }

    /** "Summarize": a few lines on what the conversation is about (local model, nothing stored but a day's cache). */
    public function summarize(Request $request, Message $message, SummaryService $summaries): JsonResponse
    {
        $data = $request->validate(['scope' => ['nullable', Rule::in(['thread', 'message'])]]);

        try {
            return response()->json(['data' => $summaries->summarize($message, $data['scope'] ?? 'thread')]);
        } catch (AiUnavailableException $e) {
            return response()->json(['message' => $e->getMessage(), 'ai_unavailable' => true], 503);
        }
    }

    public function draft(Request $request, Message $message, DraftService $drafts): JsonResponse|StreamedResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['reply', 'forward'])],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'current_draft' => ['nullable', 'string', 'max:20000'],
            'stream' => ['nullable', 'boolean'],
        ]);

        if ($request->boolean('stream')) {
            return $this->sse(function (callable $send) use ($drafts, $message, $data): void {
                $result = $drafts->onStage(fn (string $stage) => $send('stage', ['stage' => $stage]))->draftStream(
                    $message,
                    $data['kind'],
                    fn (string $delta) => $send('delta', ['text' => $delta]),
                    fn (array $context) => $send('context', ['context' => $context]),
                    $data['instructions'] ?? null,
                    $data['current_draft'] ?? null,
                );
                $send('done', ['text' => $result['text'], 'suggestion_id' => $result['suggestion_id'] ?? null]);
            });
        }

        return $this->drafted(fn () => $drafts->draft($message, $data['kind'], $data['instructions'] ?? null, $data['current_draft'] ?? null));
    }

    /**
     * Draft a brand-new e-mail for an account (no original message).
     */
    public function draftNew(Request $request, Account $account, DraftService $drafts): JsonResponse|StreamedResponse
    {
        $data = $request->validate([
            'to' => ['nullable', 'array', 'max:50'],
            'to.*' => ['string', 'max:320'],
            'subject' => ['nullable', 'string', 'max:998'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'current_draft' => ['nullable', 'string', 'max:20000'],
            'stream' => ['nullable', 'boolean'],
        ]);

        if ($request->boolean('stream')) {
            return $this->sse(function (callable $send) use ($drafts, $account, $data): void {
                $result = $drafts->onStage(fn (string $stage) => $send('stage', ['stage' => $stage]))->draftNew(
                    $account,
                    $data,
                    fn (string $delta) => $send('delta', ['text' => $delta]),
                    fn (array $context) => $send('context', ['context' => $context]),
                );
                $send('done', ['text' => $result['text'], 'suggestion_id' => $result['suggestion_id'] ?? null]);
            });
        }

        return $this->drafted(fn () => $drafts->draftNew($account, $data));
    }

    // ---------------------------------------------------------------- writing-style profile

    public function styleProfile(Account $account, StyleProfileService $profiles): JsonResponse
    {
        $profile = StyleProfile::query()->where('account_id', $account->id)->first();

        return response()->json(['data' => self::profilePayload($account, $profile, $profiles)]);
    }

    /** Queue a rebuild (statistics + model-written guide when AI is ready). */
    public function rebuildStyleProfile(Account $account, StyleProfileService $profiles): JsonResponse
    {
        $profiles->queue($account);

        return response()->json(['queued' => true], 202);
    }

    /** Switch the profile on/off for drafts, or hand-edit the style guide. */
    public function updateStyleProfile(Request $request, Account $account, StyleProfileService $profiles): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'summary' => ['nullable', 'string', 'max:7000'],
            'reset_summary' => ['nullable', 'boolean'],
        ]);

        $profile = StyleProfile::query()->firstOrCreate(['account_id' => $account->id]);
        if (array_key_exists('enabled', $data) && $data['enabled'] !== null) {
            $profile->enabled = (bool) $data['enabled'];
        }
        if ($request->boolean('reset_summary')) {
            $profile->summary_edited = false;
            $profile->summary = null;
            $profile->summary_built_at = null;
        } elseif (array_key_exists('summary', $data)) {
            $summary = trim((string) $data['summary']);
            $profile->summary = $summary === '' ? null : $summary;
            $profile->summary_edited = $summary !== '';
            $profile->summary_built_at = now();
        }
        $profile->save();

        if ($request->boolean('reset_summary')) {
            $profiles->queue($account);
        }

        return response()->json(['data' => self::profilePayload($account, $profile->refresh(), $profiles)]);
    }

    private static function profilePayload(Account $account, ?StyleProfile $profile, StyleProfileService $profiles): array
    {
        $stats = is_array($profile?->stats) ? $profile->stats : null;
        unset($stats['sample_ids']);

        return [
            'account_id' => $account->id,
            'email' => $account->email,
            'exists' => $profile !== null,
            'enabled' => $profile?->enabled ?? true,
            'built' => (bool) $profile?->isBuilt(),
            'built_at' => $profile?->built_at?->toIso8601String(),
            'analysed_until' => $profile?->analysed_until?->toIso8601String(),
            'sample_count' => (int) ($profile?->sample_count ?? 0),
            'queued' => $profile?->queued_at !== null,
            'stale' => $profiles->isStale($account, $profile),
            'summary' => $profile?->summary,
            'summary_model' => $profile?->summary_model,
            'summary_built_at' => $profile?->summary_built_at?->toIso8601String(),
            'summary_edited' => (bool) ($profile?->summary_edited ?? false),
            'last_error' => $profile?->last_error,
            'stats' => $stats,
            'prompt_preview' => $profile ? $profiles->promptBlock($account, AiSettings::load()->rag()->maxProfileChars) : null,
        ];
    }

    /**
     * Natural-language mailbox search ("PDF and Word attachments about course syllabi").
     * Filters are extracted by the local chat model, ranking uses the local vector index.
     */
    public function smartSearch(Request $request, Account $account, SmartSearchService $search): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:500'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        try {
            $result = $search->search($account, $data['q'], (int) ($data['limit'] ?? SmartSearchService::LIMIT));
        } catch (AiUnavailableException $e) {
            return response()->json(['message' => $e->getMessage(), 'ai_unavailable' => true], 503);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json([
            'data' => MessageListResource::collection($result['messages'])->resolve(),
            'meta' => [
                'query' => $data['q'],
                'interpretation' => $result['interpretation'],
                'filters' => $result['filters'],
                'ranking' => $result['ranking'],
                'parsed' => $result['parsed'],
                'total' => $result['messages']->count(),
            ],
        ]);
    }

    /** @param  Closure(): array  $generate */
    private function drafted(Closure $generate): JsonResponse
    {
        try {
            $result = $generate();
        } catch (AiUnavailableException $e) {
            return response()->json(['message' => $e->getMessage(), 'ai_unavailable' => true], 503);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json(['data' => $result]);
    }

    private static function settingsResponse(AiSettings $settings): JsonResponse
    {
        return response()->json(['data' => $settings->toArray() + ['ready' => $settings->isReady(), 'languages' => Languages::list()]]);
    }

    /**
     * Detect the user's language from a mailbox now and make it the reply language
     * (source "auto"); also used automatically, once, while the mailbox is indexed.
     */
    public function detectLanguage(Request $request, LanguageDetector $detector): JsonResponse
    {
        $data = $request->validate(['account_id' => ['required', 'integer', 'exists:accounts,id']]);
        $account = Account::query()->findOrFail((int) $data['account_id']);

        $result = $detector->detectForAccount($account);
        if ($result['ready']) {
            $changes = ['reply_language_checked_at' => now()->toIso8601String()];
            if ($result['code'] !== null) {
                $changes += ['reply_language' => $result['code'], 'reply_language_source' => 'auto', 'reply_language_detected_at' => now()->toIso8601String()];
            }
            AiSettings::load()->with($changes)->save();
        }

        return response()->json(['detection' => $result, 'data' => AiSettings::load()->toArray() + ['ready' => AiSettings::load()->isReady(), 'languages' => Languages::list()]]);
    }

    /**
     * Server-sent events: `context` (once), many `delta`, then `done` or `error`.
     *
     * @param  callable(callable(string, array): void): void  $work
     */
    private function sse(callable $work): StreamedResponse
    {
        $send = function (string $event, array $payload): void {
            echo "event: {$event}\ndata: ".json_encode($payload, JSON_UNESCAPED_UNICODE)."\n\n";
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        };

        return response()->stream(function () use ($send, $work): void {
            try {
                $work($send);
            } catch (AiUnavailableException $e) {
                $send('error', ['message' => $e->getMessage(), 'ai_unavailable' => true]);
            } catch (Throwable $e) {
                report($e);
                $send('error', ['message' => $e->getMessage()]);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=utf-8',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',   // nginx: do not buffer the stream
        ]);
    }
}
