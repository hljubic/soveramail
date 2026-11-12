<?php

namespace App\Http\Resources;

use App\Http\Controllers\Api\LocalDraftController;
use App\Models\Message;
use App\Models\ThreadCategory;
use App\Services\Mail\AttachmentService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Message */
class MessageDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'account_id' => $this->account_id,
            'provider_message_id' => $this->provider_message_id,
            'thread_id' => $this->thread_id,
            'message_id_header' => $this->message_id_header,
            'in_reply_to' => $this->in_reply_to,
            'subject' => $this->subject,
            'from' => ['name' => $this->from_name, 'email' => $this->from_email],
            'to' => $this->to_recipients ?? [],
            'cc' => $this->cc_recipients ?? [],
            'bcc' => $this->bcc_recipients ?? [],
            'reply_to' => $this->reply_to ?? [],
            'snippet' => $this->snippet,
            'body_text' => $this->body_text,
            'body_html' => $this->body_html,
            'label_ids' => $this->label_ids ?? [],
            'is_unread' => $this->is_unread,
            'is_starred' => $this->is_starred,
            'urgency' => $this->urgency,
            // The conversation's local category ('ai' = sorted by the model, 'user' = picked by hand).
            ...$this->categoryFields(),
            'has_attachments' => $this->has_attachments,
            'is_risky' => (bool) $this->is_risky,
            'has_caution' => (bool) $this->has_caution,
            'risk_acknowledged' => $this->risk_acknowledged_at !== null,
            'has_draft' => $this->relationLoaded('localDraft') && $this->localDraft !== null,
            'local_draft' => $this->whenLoaded('localDraft', fn () => $this->localDraft ? LocalDraftController::serialize($this->localDraft) : null),
            'size_estimate' => $this->size_estimate,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'received_at' => $this->received_at?->toIso8601String(),
            'attachments' => $this->whenLoaded('attachments', fn () => $this->attachments->map(fn ($a) => [
                'id' => $a->id,
                'filename' => $a->filename,
                'mime_type' => $a->mime_type,
                'size' => $a->size,
                'is_inline' => $a->is_inline,
                'content_id' => $a->content_id,
                'risk_level' => $a->risk_level,
                'risk' => $a->risk_reason,
                // Blocked attachments get no URL at all: nothing in the UI can fetch them.
                'previewable' => ! $a->isBlocked() && AttachmentService::isPreviewable($a->mime_type),
                'url' => $a->provider_attachment_id && ! $a->isBlocked() ? route('attachments.show', $a) : null,
                'download_url' => $a->provider_attachment_id && ! $a->isBlocked() ? route('attachments.show', ['attachment' => $a, 'download' => 1]) : null,
            ])->values()),
        ];
    }

    /** @return array{category_id: ?int, category_source: ?string} */
    private function categoryFields(): array
    {
        $tc = $this->thread_id
            ? ThreadCategory::query()->where('account_id', $this->account_id)->where('thread_id', $this->thread_id)->first(['category_id', 'source'])
            : null;

        return ['category_id' => $tc?->category_id, 'category_source' => $tc?->source];
    }
}
