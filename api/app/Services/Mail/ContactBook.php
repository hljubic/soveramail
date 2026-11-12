<?php

namespace App\Services\Mail;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Message;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Per-account address book harvested from mail headers.
 */
class ContactBook
{
    /**
     * Record every address on one message. Called by the sync when a message is stored.
     */
    public function recordMessage(Message $message): void
    {
        $account = $message->account;
        $mine = strtolower((string) $account->email);
        $sentByMe = in_array('SENT', $message->label_ids ?? [], true) || strtolower((string) $message->from_email) === $mine;
        $seenAt = $message->sent_at ?? $message->received_at;

        $people = [];
        if ($message->from_email) {
            $people[] = ['email' => $message->from_email, 'name' => $message->from_name, 'sent' => false];
        }
        foreach (array_merge($message->to_recipients ?? [], $message->cc_recipients ?? [], $message->bcc_recipients ?? [], $message->reply_to ?? []) as $a) {
            if (! empty($a['email'])) {
                $people[] = ['email' => $a['email'], 'name' => $a['name'] ?? null, 'sent' => $sentByMe];
            }
        }

        foreach ($people as $p) {
            $email = strtolower(trim((string) $p['email']));
            if ($email === '' || $email === $mine || ! str_contains($email, '@') || strlen($email) > 320) {
                continue;
            }
            if (self::looksAutomated($email)) {
                continue;
            }
            $this->bump($account->id, $email, $p['name'] ? mb_substr(trim((string) $p['name']), 0, 255) : null, (bool) $p['sent'], $seenAt);
        }
    }

    private function bump(int $accountId, string $email, ?string $name, bool $sent, ?Carbon $seenAt): void
    {
        /** @var Contact $contact */
        $contact = Contact::query()->firstOrNew(['account_id' => $accountId, 'email' => $email]);

        if (! $contact->exists) {
            $contact->fill(['name' => $name, 'seen_count' => 0, 'sent_count' => 0]);
        } elseif ($name && (! $contact->name || mb_strlen($name) > mb_strlen($contact->name))) {
            $contact->name = $name;   // keep the most complete display name we have seen
        }

        $contact->seen_count = $contact->seen_count + 1;
        if ($sent) {
            $contact->sent_count = $contact->sent_count + 1;
        }
        if ($seenAt && (! $contact->last_seen_at || $seenAt->gt($contact->last_seen_at))) {
            $contact->last_seen_at = $seenAt;
        }
        $contact->save();
    }

    /**
     * Autocomplete: prefix/substring on e-mail or name, people the user writes to first.
     *
     * @return Collection<int, Contact>
     */
    public function search(Account $account, string $term, int $limit = 8): Collection
    {
        $term = trim($term);
        $query = Contact::query()->where('account_id', $account->id);

        if ($term !== '') {
            $like = '%'.$term.'%';
            $query->where(fn ($q) => $q->where('email', 'like', $like)->orWhere('name', 'like', $like));
        }

        return $query
            ->orderByDesc('sent_count')
            ->orderByDesc('seen_count')
            ->orderByDesc('last_seen_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Rebuild the book for an account from the messages already in the local DB.
     */
    public function rebuild(Account $account): int
    {
        Contact::query()->where('account_id', $account->id)->delete();
        $n = 0;
        Message::query()->where('account_id', $account->id)->orderBy('id')->chunkById(500, function ($messages) use (&$n): void {
            foreach ($messages as $message) {
                $this->recordMessage($message);
                $n++;
            }
        });

        return $n;
    }

    /** Bulk senders nobody replies to. */
    public static function looksAutomated(string $email): bool
    {
        return (bool) preg_match('/^(no-?reply|noreply|donotreply|do-not-reply|mailer-daemon|postmaster|bounce|notifications?|newsletter|alerts?)[@+.-]/i', $email)
            || ((bool) preg_match('/@(?:.*\.)?(bounce|mailer|email\.|e\.|mail\.)/i', $email) && preg_match('/^[a-z0-9]{20,}@/i', $email));
    }
}
