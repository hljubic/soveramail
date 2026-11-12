<?php

namespace App\Services\Gmail;

use Carbon\CarbonImmutable;

/**
 * Provider-independent representation of one e-mail, ready to be persisted.
 */
final class ParsedMessage
{
    /**
     * @param  array<int, array{name: ?string, email: string}>  $to
     * @param  array<int, array{name: ?string, email: string}>  $cc
     * @param  array<int, array{name: ?string, email: string}>  $bcc
     * @param  array<int, array{name: ?string, email: string}>  $replyTo
     * @param  array<string, string>  $headers
     * @param  string[]  $labelIds
     * @param  array<int, array{provider_attachment_id: ?string, part_id: ?string, filename: ?string, mime_type: ?string, size: int, content_id: ?string, is_inline: bool}>  $attachments
     */
    public function __construct(
        public readonly string $providerMessageId,
        public readonly ?string $threadId,
        public readonly ?string $historyId,
        public readonly ?string $messageIdHeader,
        public readonly ?string $inReplyTo,
        public readonly ?string $references,
        public readonly ?string $subject,
        public readonly ?string $fromName,
        public readonly ?string $fromEmail,
        public readonly array $to,
        public readonly array $cc,
        public readonly array $bcc,
        public readonly array $replyTo,
        public readonly ?string $snippet,
        public readonly ?string $bodyText,
        public readonly ?string $bodyHtml,
        public readonly array $headers,
        public readonly array $labelIds,
        public readonly int $sizeEstimate,
        public readonly ?CarbonImmutable $sentAt,
        public readonly CarbonImmutable $receivedAt,
        public readonly array $attachments,
    ) {}

    public function isUnread(): bool
    {
        return in_array('UNREAD', $this->labelIds, true);
    }

    public function isStarred(): bool
    {
        return in_array('STARRED', $this->labelIds, true);
    }

    public function hasAttachments(): bool
    {
        foreach ($this->attachments as $a) {
            if (! $a['is_inline']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Column map for the `messages` table (without account_id).
     *
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [
            'provider_message_id' => $this->providerMessageId,
            'thread_id' => $this->threadId,
            'history_id' => $this->historyId,
            'message_id_header' => $this->messageIdHeader !== null ? mb_substr($this->messageIdHeader, 0, 998) : null,
            'in_reply_to' => $this->inReplyTo !== null ? mb_substr($this->inReplyTo, 0, 998) : null,
            'references_header' => $this->references,
            'subject' => $this->subject,
            'from_name' => $this->fromName !== null ? mb_substr($this->fromName, 0, 255) : null,
            'from_email' => $this->fromEmail !== null ? mb_substr($this->fromEmail, 0, 255) : null,
            'to_recipients' => $this->to,
            'cc_recipients' => $this->cc,
            'bcc_recipients' => $this->bcc,
            'reply_to' => $this->replyTo,
            'snippet' => $this->snippet,
            'body_text' => $this->bodyText,
            'body_html' => $this->bodyHtml,
            'headers' => $this->headers,
            'label_ids' => array_values($this->labelIds),
            'is_unread' => $this->isUnread(),
            'is_starred' => $this->isStarred(),
            'has_attachments' => $this->hasAttachments(),
            'size_estimate' => $this->sizeEstimate,
            'sent_at' => $this->sentAt,
            'received_at' => $this->receivedAt,
        ];
    }
}
