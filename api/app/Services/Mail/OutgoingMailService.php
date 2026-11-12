<?php

namespace App\Services\Mail;

use App\Models\Account;
use App\Models\Message;
use App\Models\OutgoingMailAttempt;
use App\Services\Mail\Providers\MailProvider;
use App\Services\Mail\Providers\MailProviders;
use App\Services\Sync\MessageStore;
use RuntimeException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Throwable;

/**
 * Builds and sends new mail, replies and forwards through the account's
 * provider, then stores the sent copy locally so it shows up in "Sent" right away.
 */
class OutgoingMailService
{
    public function __construct(
        private readonly MailProviders $providers,
        private readonly MessageStore $store,
    ) {}

    /**
     * @param  array{to: string[], cc?: string[], bcc?: string[], subject?: ?string, body: string, reply_all?: bool}  $input
     */
    public function reply(Message $original, array $input, string $idempotencyKey): Message
    {
        return $this->sendComposed($original, 'reply', $input, $idempotencyKey);
    }

    /**
     * @param  array{to: string[], cc?: string[], bcc?: string[], subject?: ?string, body: string, include_attachments?: bool}  $input
     */
    public function forward(Message $original, array $input, string $idempotencyKey): Message
    {
        return $this->sendComposed($original, 'forward', $input, $idempotencyKey);
    }

    /** @param  'reply'|'forward'  $kind */
    private function sendComposed(Message $original, string $kind, array $input, string $idempotencyKey): Message
    {
        [$email, $threadId] = $this->compose($original, $kind, $input);
        $sent = $this->send(
            $original->account,
            $email,
            $threadId,
            $idempotencyKey,
            self::payloadHash($kind, $original->account_id, $original->id, $input),
        );
        $original->localDraft()->delete();

        return $sent;
    }

    /**
     * Same composition as reply/forward, but stored as a Gmail draft instead of sent.
     *
     * @param  'reply'|'forward'  $kind
     */
    public function saveDraft(Message $original, string $kind, array $input): Message
    {
        [$email, $threadId] = $this->compose($original, $kind, $input, allowEmptyRecipients: true);

        return $this->persistDraft($original->account, $email, $threadId);
    }

    /**
     * Brand-new message (no original). Sent through Gmail and stored locally.
     *
     * @param  array{to: string[], cc?: string[], bcc?: string[], subject?: ?string, body: string}  $input
     */
    public function sendNew(Account $account, array $input, string $idempotencyKey): Message
    {
        $email = $this->composeNew($account, $input);

        return $this->send(
            $account,
            $email,
            null,
            $idempotencyKey,
            self::payloadHash('new', $account->id, null, $input),
        );
    }

    /**
     * @param  array{to?: string[], cc?: string[], bcc?: string[], subject?: ?string, body?: string}  $input
     */
    public function saveNewDraft(Account $account, array $input): Message
    {
        return $this->persistDraft($account, $this->composeNew($account, $input, allowEmptyRecipients: true), null);
    }

    /**
     * Store the composition as a draft on the provider (never sent) and mirror it locally.
     */
    private function persistDraft(Account $account, Email $email, ?string $threadId): Message
    {
        $provider = $this->providers->for($account);
        $id = $provider->saveDraft($account, $email, $threadId);

        return $this->pullLocal($provider, $account, $id)
            ?? throw new RuntimeException('Draft was saved in Gmail but could not be stored locally.');
    }

    /**
     * Fetch the copy the provider keeps and upsert it into the local store, so the
     * local row is the exact copy the server has. Null when it has no such message.
     */
    private function pullLocal(MailProvider $provider, Account $account, string $providerMessageId): ?Message
    {
        if ($providerMessageId === '') {
            return null;
        }
        if ($parsed = $provider->fetch($account, $providerMessageId)) {
            $this->store->store($account, $parsed);
        }

        return $this->store->find($account, $providerMessageId);
    }

    private function composeNew(Account $account, array $input, bool $allowEmptyRecipients = false): Email
    {
        $this->assertCanSend($account);

        $subject = trim((string) ($input['subject'] ?? ''));
        $email = $this->baseEmail($account, $input['to'] ?? [], $input['cc'] ?? [], $input['bcc'] ?? [], $subject !== '' ? $subject : '(no subject)', $allowEmptyRecipients);

        $body = str_replace("\r\n", "\n", trim((string) ($input['body'] ?? '')));
        $email->text($body)->html('<div>'.nl2br(e($body)).'</div>');

        return $email;
    }

    /**
     * @param  'reply'|'forward'  $kind
     * @return array{0: Email, 1: ?string} email + Gmail thread id
     */
    private function compose(Message $original, string $kind, array $input, bool $allowEmptyRecipients = false): array
    {
        $account = $this->assertCanSend($original->account);

        if ($kind === 'reply') {
            $subject = $input['subject'] ?? self::prefixSubject('Re:', $original->subject);
            $email = $this->baseEmail($account, $input['to'] ?? [], $input['cc'] ?? [], $input['bcc'] ?? [], $subject, $allowEmptyRecipients);

            // Threading headers so Gmail (and every other client) groups the reply.
            if ($original->message_id_header) {
                $this->addInReplyTo($email, $original->message_id_header);
                $refs = trim(($original->references_header ?? '').' '.$original->message_id_header);
                $email->getHeaders()->addTextHeader('References', $refs);
            }

            $this->setBody($email, $input['body'] ?? '', $original, quoteStyle: 'reply');

            return [$email, $original->thread_id];
        }

        $subject = $input['subject'] ?? self::prefixSubject('Fwd:', $original->subject);
        $email = $this->baseEmail($account, $input['to'] ?? [], $input['cc'] ?? [], $input['bcc'] ?? [], $subject, $allowEmptyRecipients);
        $this->setBody($email, $input['body'] ?? '', $original, quoteStyle: 'forward');

        if ($input['include_attachments'] ?? true) {
            $provider = $this->providers->for($account);
            // Blocked (screened) attachments are never forwarded, whatever was ticked.
            foreach ($original->attachments()->where('is_inline', false)->where(fn ($q) => $q->whereNull('risk_level')->orWhere('risk_level', '!=', AttachmentRisk::DANGER))->get() as $attachment) {
                if (! $attachment->provider_attachment_id) {
                    continue;
                }
                $bytes = $provider->fetchAttachment($account, $original, $attachment);
                if ($bytes === null) {
                    continue;
                }
                $email->addPart(new DataPart($bytes, $attachment->filename ?: 'attachment', $attachment->mime_type ?: 'application/octet-stream'));
            }
        }

        // Forwards start a new thread.
        return [$email, null];
    }

    private function assertCanSend(Account $account): Account
    {
        if (! $account->canModify()) {
            throw new SendNotAllowedException('Reconnect this account to allow Sovera Mail to send e-mail.');
        }

        return $account;
    }

    /**
     * Symfony validates Message-IDs as addresses; real mail carries ids without an
     * "@" or with trailing comments. Fall back to the raw header text instead of
     * making the message impossible to reply to.
     */
    private function addInReplyTo(Email $email, string $messageId): void
    {
        $id = trim($messageId, '<> ');
        try {
            $email->getHeaders()->addIdHeader('In-Reply-To', $id);
        } catch (Throwable) {
            $email->getHeaders()->remove('In-Reply-To');
            $email->getHeaders()->addTextHeader('In-Reply-To', '<'.$id.'>');
        }
    }

    private function baseEmail(Account $account, array $to, array $cc, array $bcc, string $subject, bool $allowEmptyRecipients = false): Email
    {
        $email = (new Email)
            ->from(new Address($account->email, $account->display_name ?? ''))
            ->subject($subject);

        // Set it ourselves instead of letting the MIME layer add one at the last
        // moment: providers that do not return an id for a sent message (SMTP)
        // find the stored copy again by this header.
        $domain = str_contains($account->email, '@') ? explode('@', $account->email)[1] : 'localhost';
        $email->getHeaders()->addIdHeader('Message-ID', bin2hex(random_bytes(16)).'@'.$domain);

        $toAddresses = $this->addresses($to);
        if ($toAddresses === [] && ! $allowEmptyRecipients) {
            throw new RuntimeException('At least one valid recipient is required.');
        }
        if ($toAddresses !== []) {
            $email->to(...$toAddresses);
        }
        if ($cc = $this->addresses($cc)) {
            $email->cc(...$cc);
        }
        if ($bcc = $this->addresses($bcc)) {
            $email->bcc(...$bcc);
        }

        return $email;
    }

    /** @return Address[] */
    private function addresses(array $raw): array
    {
        $out = [];
        foreach ($raw as $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            $out[] = Address::create($value);
        }

        return $out;
    }

    private function setBody(Email $email, string $body, Message $original, string $quoteStyle): void
    {
        $body = str_replace("\r\n", "\n", trim($body));
        $date = ($original->sent_at ?? $original->received_at)?->locale('en')->isoFormat('ddd, D MMM YYYY [at] HH:mm') ?? '';
        $from = $original->from_name ? "{$original->from_name} <{$original->from_email}>" : (string) $original->from_email;

        // ---- text/plain ----
        $quotedText = $original->body_text ?? '';
        if ($quoteStyle === 'reply') {
            $intro = "On {$date}, {$from} wrote:";
            $quoted = implode("\n", array_map(fn ($l) => '> '.$l, explode("\n", $quotedText)));
            $text = "{$body}\n\n{$intro}\n{$quoted}";
        } else {
            $to = implode(', ', array_map(fn ($a) => $a['name'] ? "{$a['name']} <{$a['email']}>" : $a['email'], $original->to_recipients ?? []));
            $header = "---------- Forwarded message ---------\nFrom: {$from}\nDate: {$date}\nSubject: {$original->subject}\nTo: {$to}";
            $text = "{$body}\n\n{$header}\n\n{$quotedText}";
        }

        // ---- text/html ----
        $bodyHtml = nl2br(e($body));
        $quotedHtml = $original->body_html ?: nl2br(e($quotedText));
        if ($quoteStyle === 'reply') {
            $html = "<div>{$bodyHtml}</div><br><div class=\"gmail_quote\">"
                .'<div>On '.e($date).', '.e($from).' wrote:</div>'
                ."<blockquote style=\"margin:0 0 0 .8ex;border-left:1px solid #ccc;padding-left:1ex\">{$quotedHtml}</blockquote></div>";
        } else {
            $html = "<div>{$bodyHtml}</div><br><div class=\"gmail_quote\">"
                .'<div>---------- Forwarded message ---------<br>From: '.e($from).'<br>Date: '.e($date)
                .'<br>Subject: '.e((string) $original->subject).'</div><br>'
                ."<div>{$quotedHtml}</div></div>";
        }

        $email->text($text)->html($html);
    }

    private function send(
        Account $account,
        Email $email,
        ?string $threadId,
        string $idempotencyKey,
        string $payloadHash,
    ): Message {
        $attempt = OutgoingMailAttempt::query()->firstOrCreate(
            ['idempotency_key' => strtolower($idempotencyKey)],
            [
                'account_id' => $account->id,
                'payload_hash' => $payloadHash,
                'status' => OutgoingMailAttempt::STATUS_PROCESSING,
            ],
        );

        if (! hash_equals($attempt->payload_hash, $payloadHash) || $attempt->account_id !== $account->id) {
            throw new DuplicateSendException('This send identifier was already used for different content. Nothing was sent.');
        }

        if (! $attempt->wasRecentlyCreated) {
            return $this->recoverExistingAttempt($account, $attempt);
        }

        // Everything up to the send call itself (picking the backend, MIME encoding)
        // cannot have delivered anything, so a failure here hands the key back:
        // the user may fix the problem (e.g. reconnect) and press Send again.
        try {
            $provider = $this->providers->for($account);
            $email->toString();
        } catch (Throwable $e) {
            $attempt->delete();
            throw $e;
        }

        // The one and only call that can deliver mail.
        try {
            $providerMessageId = $provider->send($account, $email, $threadId);
        } catch (Throwable $e) {
            if ($provider->definitelyRefused($e)) {
                // The server refused it (4xx, bad credentials, rejected recipient):
                // nothing went out. Release the key so the corrected message can be sent.
                $attempt->delete();
                throw $e;
            }
            // Timeout, dropped connection or a 5xx: the server may already have accepted
            // the message. Keep the key consumed so a retry cannot send a duplicate.
            $attempt->forceFill([
                'status' => OutgoingMailAttempt::STATUS_UNCERTAIN,
                'error' => mb_substr($e->getMessage(), 0, 2000),
            ])->save();
            throw $e;
        }

        $attempt->forceFill([
            'status' => OutgoingMailAttempt::STATUS_ACCEPTED,
            'provider_message_id' => $providerMessageId,
            'error' => null,
        ])->save();

        $local = $this->pullLocal($provider, $account, $providerMessageId)
            ?? throw new RuntimeException('Message was sent but could not be stored locally.');

        $attempt->forceFill([
            'status' => OutgoingMailAttempt::STATUS_COMPLETE,
            'local_message_id' => $local->id,
            'error' => null,
        ])->save();

        return $local;
    }

    private function recoverExistingAttempt(Account $account, OutgoingMailAttempt $attempt): Message
    {
        if ($attempt->local_message_id) {
            $local = Message::query()
                ->where('account_id', $account->id)
                ->find($attempt->local_message_id);
            if ($local) {
                return $local;
            }
        }

        // The server accepted it but local persistence failed. Recover the same
        // provider message; critically, do not send again.
        if ($attempt->provider_message_id) {
            $local = $this->pullLocal($this->providers->for($account), $account, $attempt->provider_message_id);
            if ($local) {
                $attempt->forceFill([
                    'status' => OutgoingMailAttempt::STATUS_COMPLETE,
                    'local_message_id' => $local->id,
                    'error' => null,
                ])->save();

                return $local;
            }
        }

        throw new DuplicateSendException(
            'This send request was already attempted and its delivery status is uncertain. Check the Sent folder; it was not sent again.'
        );
    }

    public static function payloadHash(string $kind, int $accountId, ?int $originalId, array $input): string
    {
        return hash('sha256', json_encode([
            'kind' => $kind,
            'account_id' => $accountId,
            'original_id' => $originalId,
            'to' => array_values($input['to'] ?? []),
            'cc' => array_values($input['cc'] ?? []),
            'bcc' => array_values($input['bcc'] ?? []),
            'subject' => $input['subject'] ?? null,
            'body' => $input['body'] ?? '',
            'include_attachments' => (bool) ($input['include_attachments'] ?? true),
        ], JSON_THROW_ON_ERROR));
    }

    public static function prefixSubject(string $prefix, ?string $subject): string
    {
        $subject = trim((string) $subject);
        if (preg_match('/^'.preg_quote(rtrim($prefix, ':'), '/').':/i', $subject)) {
            return $subject;
        }

        return trim("{$prefix} {$subject}");
    }
}
