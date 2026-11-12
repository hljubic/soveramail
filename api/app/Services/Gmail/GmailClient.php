<?php

namespace App\Services\Gmail;

use App\Exceptions\ReauthRequiredException;
use App\Models\Account;
use App\Services\Google\GoogleClientFactory;
use Google\Client;
use Google\Service\Exception as GoogleServiceException;
use Google\Service\Gmail;
use Google\Service\Gmail\Draft as GmailDraft;
use Google\Service\Gmail\Label as GmailLabel;
use Google\Service\Gmail\ListHistoryResponse;
use Google\Service\Gmail\Message as GmailMessage;
use Google\Service\Gmail\ModifyMessageRequest;
use Google\Service\Gmail\Profile;
use Illuminate\Support\Sleep;

/**
 * Thin, account-bound wrapper around the Gmail REST API.
 */
class GmailClient
{
    private Client $client;

    private Gmail $gmail;

    /** How many times Gmail answered "rate limited" since the client was created (sync uses it to slow down). */
    private int $rateLimitHits = 0;

    public function __construct(
        private readonly Account $account,
        private readonly GoogleClientFactory $factory,
        ?Gmail $gmail = null,
    ) {
        $this->client = $factory->forAccount($account);
        $this->gmail = $gmail ?? new Gmail($this->client);
    }

    public function rateLimitHits(): int
    {
        return $this->rateLimitHits;
    }

    /** Seconds before expiry at which the token is renewed ahead of the next call. */
    private const REFRESH_AHEAD_SECONDS = 120;

    /**
     * An access token lives one hour, a big sync runs longer. Renew it shortly
     * before it runs out instead of letting Gmail answer 401 half-way.
     */
    private function ensureFreshToken(): void
    {
        $expires = $this->account->token_expires_at;
        if ($this->account->refresh_token && $expires && now()->addSeconds(self::REFRESH_AHEAD_SECONDS)->greaterThan($expires)) {
            $this->factory->refresh($this->account, $this->client);
        }
    }

    /**
     * Gmail said 401 although the token should still be valid (clock skew, token
     * revoked by a newer one, ...). One forced refresh tells a stale token apart
     * from a real loss of access; only the latter needs the user to reconnect.
     *
     * @return bool true when a new token is in place and the call may be retried
     */
    private function recoverFromAuthFailure(GoogleServiceException $e, bool &$alreadyRetried): bool
    {
        if ($alreadyRetried || $e->getCode() !== 401 || ! $this->account->refresh_token) {
            return false;
        }
        $alreadyRetried = true;
        logger()->info('Gmail answered 401, refreshing the access token once', ['account' => $this->account->email]);
        $this->factory->refresh($this->account, $this->client);

        return true;
    }

    /**
     * Seconds to wait before retrying a rate-limited call. Gmail's per-user
     * budget ("Units per minute per user") refills every minute, so back off
     * hard rather than hammer it.
     */
    private const RETRY_DELAYS = [5, 15, 30, 60, 60];

    public function profile(): Profile
    {
        return $this->guard(fn () => $this->gmail->users->getProfile('me'));
    }

    /** @return GmailLabel[] */
    public function labels(): array
    {
        return $this->guard(fn () => $this->gmail->users_labels->listUsersLabels('me')->getLabels() ?? []);
    }

    /**
     * Newest-first message ids (id + threadId), up to $limit.
     *
     * @return array<int, array{id: string, threadId: string}>
     */
    public function listMessageIds(int $limit, ?string $query = null): array
    {
        $ids = [];
        $pageToken = null;

        do {
            $params = ['maxResults' => min(500, max(1, $limit - count($ids)))];
            if ($query !== null && $query !== '') {
                $params['q'] = $query;
            }
            if ($pageToken) {
                $params['pageToken'] = $pageToken;
            }

            $response = $this->guard(fn () => $this->gmail->users_messages->listUsersMessages('me', $params));

            foreach ($response->getMessages() ?? [] as $m) {
                $ids[] = ['id' => $m->getId(), 'threadId' => $m->getThreadId()];
                if (count($ids) >= $limit) {
                    break 2;
                }
            }

            $pageToken = $response->getNextPageToken();
        } while ($pageToken);

        return $ids;
    }

    /**
     * Fetch full messages in batches. Missing/deleted ids are skipped.
     *
     * @param  string[]  $ids
     * @return array<string, GmailMessage> keyed by Gmail message id
     */
    public function getMessages(array $ids, string $format = 'full'): array
    {
        $batchSize = (int) config('sovera.sync.batch_size', 25);
        $out = [];

        foreach (array_chunk(array_values($ids), max(1, $batchSize)) as $chunk) {
            // Items that came back rate-limited are retried after a pause; the
            // whole batch is retried by guard() when Gmail rejects it as a whole.
            $pending = $chunk;
            $attempt = 0;
            $authRetried = false;
            while ($pending !== []) {
                $this->client->setUseBatch(true);
                try {
                    $batch = $this->gmail->createBatch();
                    foreach ($pending as $id) {
                        $batch->add($this->gmail->users_messages->get('me', $id, ['format' => $format]), $id);
                    }
                    $results = $this->guard(fn () => $batch->execute());
                } finally {
                    $this->client->setUseBatch(false);
                }

                $pending = [];
                $authFailure = null;
                foreach ($results as $key => $result) {
                    $id = (string) preg_replace('/^response-/', '', (string) $key);
                    if ($result instanceof GmailMessage) {
                        $out[$id] = $result;
                    } elseif ($result instanceof GoogleServiceException) {
                        if (self::isAuthFailure($result)) {
                            $authFailure = $result;
                            $pending[] = $id;
                        } elseif (self::isRateLimited($result)) {
                            $pending[] = $id;
                        } elseif ($result->getCode() !== 404) {
                            // 404 = message deleted between list and get; other errors are logged and skipped.
                            logger()->warning('Gmail batch item failed', ['id' => $id, 'code' => $result->getCode(), 'error' => $result->getMessage()]);
                        }
                    }
                }

                if ($authFailure !== null) {
                    // Items of one batch share one token: refresh once and retry what is left.
                    if (! $this->recoverFromAuthFailure($authFailure, $authRetried)) {
                        throw new ReauthRequiredException(self::friendlyMessage($authFailure), $authFailure->getCode(), $authFailure);
                    }

                    continue;
                }

                if ($pending !== []) {
                    if (! isset(self::RETRY_DELAYS[$attempt])) {
                        throw new GoogleServiceException('Gmail rate limit: giving up after '.count(self::RETRY_DELAYS).' retries.', 429);
                    }
                    $this->rateLimitHits++;
                    logger()->info('Gmail rate limited, pausing', ['pending' => count($pending), 'seconds' => self::RETRY_DELAYS[$attempt]]);
                    Sleep::for(self::RETRY_DELAYS[$attempt++])->seconds();
                }
            }
        }

        return $out;
    }

    public function getMessage(string $id, string $format = 'full'): ?GmailMessage
    {
        try {
            return $this->guard(fn () => $this->gmail->users_messages->get('me', $id, ['format' => $format]));
        } catch (GoogleServiceException $e) {
            if ($e->getCode() === 404) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * The only Gmail labels this client will ever change. `users.messages.modify`
     * could also add TRASH or SPAM (which moves mail out of the inbox) or strip
     * INBOX (archive); none of that is a Sovera Mail feature, so it is refused
     * here at the lowest layer, whatever the caller asks for.
     */
    public const MUTABLE_LABELS = ['UNREAD', 'STARRED'];

    /**
     * Add / remove labels on one message (UNREAD, STARRED only). Needs gmail.modify.
     *
     * @throws \InvalidArgumentException for any other label
     */
    public function modifyLabels(string $messageId, array $addLabelIds = [], array $removeLabelIds = []): GmailMessage
    {
        foreach ([...array_values($addLabelIds), ...array_values($removeLabelIds)] as $label) {
            if (! in_array($label, self::MUTABLE_LABELS, true)) {
                throw new \InvalidArgumentException(
                    "Refusing to change Gmail label \"{$label}\": Sovera Mail only toggles read/unread and starred, never TRASH, SPAM or INBOX."
                );
            }
        }

        $request = new ModifyMessageRequest;
        $request->setAddLabelIds(array_values($addLabelIds));
        $request->setRemoveLabelIds(array_values($removeLabelIds));

        return $this->guard(fn () => $this->gmail->users_messages->modify('me', $messageId, $request));
    }

    /**
     * Send a raw RFC 5322 message. Returns the stored Gmail message (id + threadId).
     */
    public function sendRaw(string $rawMime, ?string $threadId = null): GmailMessage
    {
        $message = self::rawMessage($rawMime, $threadId);

        return $this->guard(fn () => $this->gmail->users_messages->send('me', $message));
    }

    /**
     * Save a raw RFC 5322 message as a Gmail draft (shows up in Drafts everywhere).
     */
    public function createDraft(string $rawMime, ?string $threadId = null): GmailDraft
    {
        $draft = new GmailDraft;
        $draft->setMessage(self::rawMessage($rawMime, $threadId));

        return $this->guard(fn () => $this->gmail->users_drafts->create('me', $draft));
    }

    /** RFC 5322 bytes as the url-safe base64 `raw` field Gmail expects. */
    private static function rawMessage(string $rawMime, ?string $threadId): GmailMessage
    {
        $message = new GmailMessage;
        $message->setRaw(rtrim(strtr(base64_encode($rawMime), '+/', '-_'), '='));
        if ($threadId) {
            $message->setThreadId($threadId);
        }

        return $message;
    }

    /**
     * Raw bytes of an attachment part.
     */
    public function getAttachment(string $messageId, string $attachmentId): ?string
    {
        $body = $this->guard(fn () => $this->gmail->users_messages_attachments->get('me', $messageId, $attachmentId));
        $data = $body?->getData();
        if (! $data) {
            return null;
        }

        return base64_decode(strtr($data, '-_', '+/')) ?: null;
    }

    public function listHistory(string $startHistoryId, ?string $pageToken = null): ListHistoryResponse
    {
        $params = [
            'startHistoryId' => $startHistoryId,
            'historyTypes' => ['messageAdded', 'messageDeleted', 'labelAdded', 'labelRemoved'],
            'maxResults' => 500,
        ];
        if ($pageToken) {
            $params['pageToken'] = $pageToken;
        }

        return $this->guard(fn () => $this->gmail->users_history->listUsersHistory('me', $params));
    }

    /**
     * Translate Google auth failures into ReauthRequiredException and wait out
     * per-user rate limits instead of failing the sync.
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    private function guard(callable $fn): mixed
    {
        $this->ensureFreshToken();

        $attempt = 0;
        $authRetried = false;
        while (true) {
            try {
                return $fn();
            } catch (GoogleServiceException $e) {
                if (self::isAuthFailure($e)) {
                    if ($this->recoverFromAuthFailure($e, $authRetried)) {
                        continue;
                    }
                    throw new ReauthRequiredException(self::friendlyMessage($e), $e->getCode(), $e);
                }
                if (! self::isRateLimited($e) || ! isset(self::RETRY_DELAYS[$attempt])) {
                    throw $e;
                }
                $this->rateLimitHits++;
                logger()->info('Gmail rate limited, pausing', ['seconds' => self::RETRY_DELAYS[$attempt], 'error' => $e->getMessage()]);
                Sleep::for(self::RETRY_DELAYS[$attempt++])->seconds();
            }
        }
    }

    /**
     * 429, or 403 with a quota/rate-limit reason ("Units per minute per user").
     * Temporary by definition: waiting a bit always fixes it.
     */
    public static function isRateLimited(GoogleServiceException $e): bool
    {
        if ($e->getCode() === 429) {
            return true;
        }
        if ($e->getCode() !== 403) {
            return false;
        }
        foreach ($e->getErrors() ?? [] as $err) {
            if (in_array($err['reason'] ?? '', ['rateLimitExceeded', 'userRateLimitExceeded', 'quotaExceeded'], true)) {
                return true;
            }
        }

        return (bool) preg_match('/quota exceeded|rate limit/i', $e->getMessage());
    }

    /**
     * 401, or 403 because the token lacks the Gmail scope: both need a fresh consent.
     */
    public static function isAuthFailure(GoogleServiceException $e): bool
    {
        if ($e->getCode() === 401) {
            return true;
        }
        if ($e->getCode() !== 403) {
            return false;
        }
        foreach ($e->getErrors() ?? [] as $err) {
            if (in_array($err['reason'] ?? '', ['insufficientPermissions', 'forbidden', 'accessNotConfigured'], true)) {
                return $err['reason'] !== 'accessNotConfigured';
            }
        }

        return str_contains(strtolower($e->getMessage()), 'insufficient');
    }

    /**
     * Human-readable message out of Google's JSON error body.
     */
    public static function friendlyMessage(GoogleServiceException $e): string
    {
        $errors = $e->getErrors() ?? [];
        $reason = $errors[0]['reason'] ?? null;
        $message = $errors[0]['message'] ?? null;

        if ($reason === 'insufficientPermissions' || str_contains(strtolower((string) $message), 'insufficient')) {
            return GmailOAuth::MISSING_GMAIL_SCOPE_MESSAGE;
        }
        if ($reason === 'accessNotConfigured') {
            return 'Gmail API is not enabled for this Google Cloud project. Enable it under APIs & Services → Library.';
        }
        if ($message) {
            return "Gmail API error {$e->getCode()}: {$message}";
        }

        $decoded = json_decode($e->getMessage(), true);

        return $decoded['error']['message'] ?? $e->getMessage();
    }
}
