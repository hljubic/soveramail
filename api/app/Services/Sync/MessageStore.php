<?php

namespace App\Services\Sync;

use App\Models\Account;
use App\Models\Message;
use App\Services\Gmail\ParsedMessage;
use App\Services\Mail\AttachmentRisk;
use App\Services\Mail\ContactBook;
use App\Services\Mail\Utf8;
use Illuminate\Support\Facades\DB;

/**
 * The one place that writes messages into the local database, whatever
 * backend they came from. Never deletes: a message the provider no longer has
 * is only hidden (`remote_deleted_at`).
 */
class MessageStore
{
    public function __construct(private readonly ContactBook $contacts) {}

    /**
     * Upsert one message (+ attachment metadata). Returns true when inserted.
     */
    public function store(Account $account, ParsedMessage $parsed): bool
    {
        return DB::transaction(function () use ($account, $parsed): bool {
            /** @var Message $message */
            $message = Message::query()->firstOrNew([
                'account_id' => $account->id,
                'provider_message_id' => $parsed->providerMessageId,
            ]);
            $isNew = ! $message->exists;

            $message->fill(self::clean($parsed->toRow()));
            $message->account_id = $account->id;
            $message->remote_deleted_at = null; // the provider still has it, so it is visible again
            $message->save();

            $this->storeAttachments($message, $parsed);
            if ($isNew) {
                $this->contacts->recordMessage($message);
            }

            return $isNew;
        });
    }

    /**
     * Make a parsed row safe to write: one message with a broken header must not
     * stop the whole account from syncing.
     *  - Strings that are not valid UTF-8 are repaired (Utf8::fix). They would
     *    otherwise fail JSON encoding (`headers` cast) or be rejected by utf8mb4.
     *  - Dates outside what a `timestamp` column holds (1970-01-01 00:00:00 from
     *    broken mailers, far-future spam) are dropped / replaced.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function clean(array $row): array
    {
        array_walk_recursive($row, function (mixed &$value): void {
            if (is_string($value)) {
                $value = Utf8::fix($value);
            }
        });

        $fits = static fn (mixed $d): bool => $d instanceof \DateTimeInterface
            && $d->getTimestamp() > 86_400 && $d->getTimestamp() < 2_145_916_800; // 1970-01-02 .. 2038-01-01
        if (isset($row['sent_at']) && ! $fits($row['sent_at'])) {
            $row['sent_at'] = null;
        }
        if (array_key_exists('received_at', $row) && ! $fits($row['received_at'])) {
            $row['received_at'] = $row['sent_at'] ?? now();
        }

        return $row;
    }

    /** The stored row for a provider id, if any. */
    public function find(Account $account, string $providerMessageId): ?Message
    {
        return Message::query()
            ->where('account_id', $account->id)
            ->where('provider_message_id', $providerMessageId)
            ->first();
    }

    /**
     * The provider says these are gone for good. The local copy is kept (this
     * app never deletes mail); it is only hidden. Returns how many were hidden.
     *
     * @param  string[]  $providerMessageIds
     */
    public function hide(Account $account, array $providerMessageIds): int
    {
        if ($providerMessageIds === []) {
            return 0;
        }

        return Message::query()
            ->where('account_id', $account->id)
            ->whereIn('provider_message_id', $providerMessageIds)
            ->whereNull('remote_deleted_at')
            ->update(['remote_deleted_at' => now(), 'updated_at' => now()]);
    }

    private function storeAttachments(Message $message, ParsedMessage $parsed): void
    {
        // A content-sniff verdict (from an explicit download) survives a re-sync.
        $sniffed = $message->attachments()->where('risk_reason', 'like', 'The file content%')->pluck('risk_reason', 'provider_attachment_id');
        $message->attachments()->delete();

        $danger = false;
        $caution = false;
        if ($parsed->attachments !== []) {
            $now = now();
            $rows = [];
            foreach ($parsed->attachments as $a) {
                // Screened from name + declared type only. Nothing is downloaded during sync.
                $verdict = isset($sniffed[$a['provider_attachment_id'] ?? ''])
                    ? ['level' => AttachmentRisk::DANGER, 'reason' => $sniffed[$a['provider_attachment_id']]]
                    : AttachmentRisk::screen($a['filename'], $a['mime_type'], (bool) $a['is_inline']);
                $danger = $danger || ($verdict['level'] ?? null) === AttachmentRisk::DANGER;
                $caution = $caution || ($verdict['level'] ?? null) === AttachmentRisk::CAUTION;
                $rows[] = $a + ['risk_level' => $verdict['level'] ?? null, 'risk_reason' => $verdict['reason'] ?? null, 'message_id' => $message->id, 'created_at' => $now, 'updated_at' => $now];
            }
            $message->attachments()->insert($rows);
        }

        if ($message->is_risky !== $danger || $message->has_caution !== $caution) {
            $message->forceFill(['is_risky' => $danger, 'has_caution' => $caution, 'risk_acknowledged_at' => $danger ? $message->risk_acknowledged_at : null])->save();
        }
    }
}
