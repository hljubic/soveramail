<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ClassifyAccountJob;
use App\Models\Account;
use App\Models\Category;
use App\Models\Message;
use App\Models\ThreadCategory;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Classify\ThreadClassifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The user's local categories (never sent to Gmail) and the per-thread choice.
 */
class CategoryController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Category::query()->ordered()->get()->map(fn (Category $c) => self::present($c))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['position'] ??= (int) Category::query()->where('is_fallback', false)->max('position') + 1;
        $category = Category::query()->create($data + ['is_fallback' => false]);

        return response()->json(['data' => self::present($category)], 201);
    }

    public function update(Request $request, Category $category): JsonResponse
    {
        $category->update($this->validated($request, $category));

        return response()->json(['data' => self::present($category->fresh())]);
    }

    /**
     * Its conversations lose their category and are sorted again (into what is left).
     */
    public function destroy(Category $category): JsonResponse
    {
        if ($category->is_fallback) {
            return response()->json(['message' => 'The fallback category cannot be deleted.'], 422);
        }
        $accounts = ThreadCategory::query()->where('category_id', $category->id)->distinct()->pluck('account_id');
        $category->delete(); // thread_categories cascade
        $this->classifyAccounts($accounts->all());

        return response()->json(['deleted' => true]);
    }

    /**
     * Sort everything again after the categories changed. Hand-picked categories stay.
     */
    public function reclassify(ThreadClassifier $classifier): JsonResponse
    {
        // Only what the classifier would sort again (the "last N days" window, when set):
        // older conversations keep their category instead of silently losing it.
        $deleted = 0;
        foreach (Account::query()->get() as $account) {
            $deleted += ThreadCategory::query()->where('account_id', $account->id)->where('source', ThreadCategory::SOURCE_AI)
                ->whereIn('thread_id', $classifier->eligible($account)->select('thread_id'))
                ->delete();
        }
        $this->classifyAccounts(Account::query()->pluck('id')->all());

        return response()->json(['queued' => true, 'reset' => $deleted], 202);
    }

    /**
     * Sidebar data for one account: each category with its thread and unread
     * counts, plus how far classification has come.
     */
    public function forAccount(Account $account, ThreadClassifier $classifier): JsonResponse
    {
        $threads = ThreadCategory::query()->where('account_id', $account->id)
            ->select('category_id', DB::raw('COUNT(*) as threads'))->groupBy('category_id')->pluck('threads', 'category_id');

        $unread = DB::table('thread_categories')
            ->join('messages', fn ($j) => $j->on('messages.account_id', '=', 'thread_categories.account_id')
                ->on('messages.thread_id', '=', 'thread_categories.thread_id'))
            ->where('thread_categories.account_id', $account->id)
            ->where('messages.is_unread', true)
            ->whereNull('messages.remote_deleted_at')
            ->select('thread_categories.category_id', DB::raw('COUNT(DISTINCT thread_categories.thread_id) as unread'))
            ->groupBy('thread_categories.category_id')
            ->pluck('unread', 'category_id');

        return response()->json([
            'data' => Category::query()->ordered()->get()->map(fn (Category $c) => self::present($c) + [
                'threads' => (int) ($threads[$c->id] ?? 0),
                'unread' => (int) ($unread[$c->id] ?? 0),
            ]),
            'meta' => [
                'active' => ThreadClassifier::isActive(AiSettings::load()),
                'model' => AiSettings::load()->classificationModel(),
                'progress' => $classifier->progress($account),
            ],
        ]);
    }

    public function classify(Account $account): JsonResponse
    {
        ThreadClassifier::assertReady();
        ClassifyAccountJob::dispatch($account->id);

        return response()->json(['queued' => true], 202);
    }

    /**
     * The user picks the category of a conversation by hand: it applies to the
     * whole thread, is never overridden and teaches the model by example.
     */
    public function setForMessage(Request $request, Message $message): JsonResponse
    {
        $data = $request->validate(['category_id' => ['required', 'integer', Rule::exists('categories', 'id')]]);
        if (! $message->thread_id) {
            return response()->json(['message' => 'This message is not part of a conversation.'], 422);
        }

        ThreadCategory::query()->updateOrCreate(
            ['account_id' => $message->account_id, 'thread_id' => $message->thread_id],
            ['category_id' => (int) $data['category_id'], 'source' => ThreadCategory::SOURCE_USER, 'model' => null, 'classified_at' => now()],
        );

        return response()->json(['data' => ['category_id' => (int) $data['category_id'], 'category_source' => ThreadCategory::SOURCE_USER]]);
    }

    /**
     * Move several conversations at once (the list's checkboxes): each message's whole
     * thread gets the category, picked by hand, so the model never changes it again.
     */
    public function setForMessages(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')],
            'message_ids' => ['required', 'array', 'min:1', 'max:500'],
            'message_ids.*' => ['integer'],
        ]);

        $threads = Message::query()->whereKey($data['message_ids'])->whereNotNull('thread_id')
            ->get(['account_id', 'thread_id'])->unique(fn (Message $m) => $m->account_id.'|'.$m->thread_id);
        foreach ($threads as $m) {
            ThreadCategory::query()->updateOrCreate(
                ['account_id' => $m->account_id, 'thread_id' => $m->thread_id],
                ['category_id' => (int) $data['category_id'], 'source' => ThreadCategory::SOURCE_USER, 'model' => null, 'classified_at' => now()],
            );
        }

        return response()->json(['data' => ['category_id' => (int) $data['category_id'], 'conversations' => $threads->count()]]);
    }

    private function validated(Request $request, ?Category $category = null): array
    {
        $data = $request->validate([
            'name' => [$category ? 'sometimes' : 'required', 'string', 'max:100',
                Rule::unique('categories', 'name')->ignore($category?->id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'position' => ['nullable', 'integer', 'min:0', 'max:999'],
        ]);

        return $data;
    }

    /** @param  int[]  $accountIds */
    private function classifyAccounts(array $accountIds): void
    {
        if (! ThreadClassifier::isActive(AiSettings::load())) {
            return;
        }
        foreach (array_unique($accountIds) as $id) {
            ClassifyAccountJob::dispatch((int) $id);
        }
    }

    public static function present(Category $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'description' => $c->description,
            'color' => $c->color,
            'position' => $c->position,
            'is_fallback' => $c->is_fallback,
        ];
    }
}
