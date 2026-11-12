<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\BuildKnowledgeJob;
use App\Models\Account;
use App\Models\KnowledgeItem;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Knowledge\KnowledgeBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the local AI knows about the user: list, edit by hand, rebuild.
 */
class KnowledgeController extends Controller
{
    public function index(Account $account, KnowledgeBuilder $builder): JsonResponse
    {
        $items = KnowledgeItem::query()->where('account_id', $account->id)
            ->orderByRaw("CASE kind WHEN 'about' THEN 0 WHEN 'audience' THEN 1 WHEN 'faq' THEN 2 ELSE 3 END")
            ->orderBy('title')
            ->get();

        return response()->json([
            'data' => $items->map(fn (KnowledgeItem $i) => self::present($i))->values(),
            'meta' => ['progress' => $builder->progress($account), 'ready' => AiSettings::load()->isReady()],
        ]);
    }

    /** Edited by hand: kept as it is, never overwritten by a rebuild. */
    public function update(Request $request, KnowledgeItem $item): JsonResponse
    {
        $data = $request->validate(['content' => ['present', 'nullable', 'string', 'max:8000']]);
        $item->forceFill(['content' => trim((string) $data['content']), 'source' => KnowledgeItem::SOURCE_USER])->save();

        return response()->json(['data' => self::present($item)]);
    }

    /** Back to the model's version: the item is dropped and built again. */
    public function reset(KnowledgeItem $item): JsonResponse
    {
        $accountId = $item->account_id;
        $item->delete();
        BuildKnowledgeJob::dispatch($accountId);

        return response()->json(['queued' => true], 202);
    }

    /** Build what is missing (all = drop the model-written items first; hand-edited ones stay). */
    public function rebuild(Request $request, Account $account): JsonResponse
    {
        if (! AiSettings::load()->isReady()) {
            return response()->json(['message' => 'Pick a local model first (Configure AI).'], 409);
        }
        $reset = 0;
        if ($request->boolean('all')) {
            $reset = KnowledgeItem::query()->where('account_id', $account->id)->where('source', KnowledgeItem::SOURCE_AI)->delete();
        }
        BuildKnowledgeJob::dispatch($account->id);

        return response()->json(['queued' => true, 'reset' => $reset], 202);
    }

    public static function present(KnowledgeItem $i): array
    {
        return [
            'id' => $i->id,
            'kind' => $i->kind,
            'subject' => $i->subject,
            'title' => $i->title,
            'content' => $i->content,
            'source' => $i->source,
            'model' => $i->model,
            'evidence' => $i->evidence,
            'built_at' => $i->built_at?->toIso8601String(),
            'updated_at' => $i->updated_at?->toIso8601String(),
        ];
    }
}
