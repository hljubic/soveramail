<?php

namespace App\Services\Mail\Providers;

use App\Exceptions\ReauthRequiredException;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Message;
use App\Services\Gmail\GmailClient;
use App\Services\Gmail\GmailClientResolver;
use App\Services\Gmail\GmailMessageParser;
use App\Services\Gmail\ParsedMessage;
use App\Services\Mail\RawMime;
use App\Services\Sync\GmailSyncService;
use Google\Service\Exception as GoogleServiceException;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Gmail REST API backend (OAuth, scope gmail.modify).
 */
class GmailProvider implements MailProvider
{
    /** One authorised client per account for the lifetime of this provider (one request/job). */
    private array $clients = [];

    public function __construct(
        private readonly GmailClientResolver $clientResolver,
        private readonly GmailSyncService $sync,
        private readonly GmailMessageParser $parser,
    ) {}

    private function client(Account $account): GmailClient
    {
        return $this->clients[$account->id] ??= $this->clientResolver->for($account);
    }

    public function sync(Account $account, string $mode = self::MODE_INCREMENTAL, ?int $limit = null, ?int $sinceDays = null): array
    {
        return $this->sync->sync($account, $mode, $limit, $sinceDays);
    }

    public function send(Account $account, Email $email, ?string $threadId): string
    {
        return (string) $this->client($account)->sendRaw(RawMime::withBcc($email), $threadId)->getId();
    }

    public function saveDraft(Account $account, Email $email, ?string $threadId): string
    {
        return (string) $this->client($account)->createDraft(RawMime::withBcc($email), $threadId)->getMessage()?->getId();
    }

    public function fetch(Account $account, string $providerMessageId): ?ParsedMessage
    {
        $full = $this->client($account)->getMessage($providerMessageId, 'full');

        return $full ? $this->parser->parse($full) : null;
    }

    public function setFlag(Account $account, Message $message, string $flag, bool $on): void
    {
        $client = $this->client($account);
        $on
            ? $client->modifyLabels($message->provider_message_id, [$flag], [])
            : $client->modifyLabels($message->provider_message_id, [], [$flag]);
    }

    public function fetchAttachment(Account $account, Message $message, Attachment $attachment): ?string
    {
        if (! $attachment->provider_attachment_id) {
            return null;
        }

        return $this->client($account)->getAttachment($message->provider_message_id, $attachment->provider_attachment_id);
    }

    /**
     * A 4xx from Gmail (bad request, expired grant, rate limit, ...) is a definite
     * "not sent". Anything else (no response, 5xx) is treated as possibly sent.
     */
    public function definitelyRefused(Throwable $e): bool
    {
        if ($e instanceof ReauthRequiredException) {
            return true;
        }
        if ($e instanceof GoogleServiceException) {
            return $e->getCode() >= 400 && $e->getCode() < 500;
        }

        return false;
    }
}
