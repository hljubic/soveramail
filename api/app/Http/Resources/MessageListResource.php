<?php

namespace App\Http\Resources;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Message */
class MessageListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'account_id' => $this->account_id,
            'provider_message_id' => $this->provider_message_id,
            'thread_id' => $this->thread_id,
            'subject' => $this->subject,
            'from' => ['name' => $this->from_name, 'email' => $this->from_email],
            'to' => $this->to_recipients ?? [],
            'snippet' => $this->snippet,
            'label_ids' => $this->label_ids ?? [],
            'is_unread' => $this->is_unread,
            'is_starred' => $this->is_starred,
            // high | medium | low, judged once while the message was unread; null = not judged.
            'urgency' => $this->urgency,
            'has_attachments' => $this->has_attachments,
            'has_draft' => (bool) ($this->has_draft ?? false),
            'is_risky' => (bool) $this->is_risky,
            'has_caution' => (bool) $this->has_caution,
            'risk_acknowledged' => $this->risk_acknowledged_at !== null,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'received_at' => $this->received_at?->toIso8601String(),
            // Conversation rows (conversations=1): size of the thread, unread in it, its urgency.
            'thread_count' => $this->when($this->getAttribute('thread_count') !== null, fn () => (int) $this->getAttribute('thread_count')),
            'thread_unread' => $this->when($this->getAttribute('thread_unread') !== null, fn () => (int) $this->getAttribute('thread_unread')),
            'thread_urgency' => $this->when($this->getAttribute('thread_count') !== null, fn () => $this->getAttribute('thread_urgency')),
            'category_id' => $this->when($this->getAttribute('thread_count') !== null, fn () => $this->getAttribute('category_id') !== null ? (int) $this->getAttribute('category_id') : null),
            'attachment_chips' => $this->whenLoaded('attachments', fn () => $this->attachments->take(3)->map(fn ($a) => [
                'filename' => $a->filename, 'mime_type' => $a->mime_type, 'risk_level' => $a->risk_level,
            ])->values()),
            'attachments_more' => $this->whenLoaded('attachments', fn () => max(0, $this->attachments->count() - 3)),
            'thread_has_draft' => $this->when($this->getAttribute('thread_drafts') !== null, fn () => (int) $this->getAttribute('thread_drafts') > 0),
            // Smart search only: similarity to the topic (0..1).
            'score' => $this->when($this->getAttribute('score') !== null, fn () => $this->getAttribute('score')),
        ];
    }
}
