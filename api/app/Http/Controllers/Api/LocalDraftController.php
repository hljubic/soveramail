<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LocalDraft;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Local (unsent) reply/forward drafts bound to a message.
 */
class LocalDraftController extends Controller
{
    public function show(Message $message): JsonResponse
    {
        $draft = LocalDraft::query()->where('message_id', $message->id)->first();

        return response()->json(['data' => $draft ? self::serialize($draft) : null]);
    }

    public function upsert(Request $request, Message $message): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['reply', 'forward'])],
            'to' => ['nullable', 'array', 'max:50'],
            'to.*' => ['string', 'max:320'],
            'cc' => ['nullable', 'array', 'max:50'],
            'cc.*' => ['string', 'max:320'],
            'bcc' => ['nullable', 'array', 'max:50'],
            'bcc.*' => ['string', 'max:320'],
            'subject' => ['nullable', 'string', 'max:998'],
            'body' => ['present', 'nullable', 'string', 'max:500000'],
        ]);

        $draft = LocalDraft::query()->updateOrCreate(
            ['message_id' => $message->id],
            [
                'account_id' => $message->account_id,
                'kind' => $data['kind'],
                'to' => $data['to'] ?? [],
                'cc' => $data['cc'] ?? [],
                'bcc' => $data['bcc'] ?? [],
                'subject' => $data['subject'] ?? null,
                'body' => (string) ($data['body'] ?? ''),
            ],
        );

        return response()->json(['data' => self::serialize($draft)]);
    }

    public function destroy(Message $message): JsonResponse
    {
        LocalDraft::query()->where('message_id', $message->id)->delete();

        return response()->json(['deleted' => true]);
    }

    /** @return array<string, mixed> */
    public static function serialize(LocalDraft $d): array
    {
        return [
            'kind' => $d->kind,
            'to' => $d->to ?? [],
            'cc' => $d->cc ?? [],
            'bcc' => $d->bcc ?? [],
            'subject' => $d->subject,
            'body' => $d->body,
            'updated_at' => $d->updated_at?->toIso8601String(),
        ];
    }
}
