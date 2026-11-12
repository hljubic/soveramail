<?php

namespace Tests\Feature\Ai;

use App\Jobs\ClassifyAccountJob;
use App\Jobs\EmbedAccountJob;
use App\Models\Account;
use App\Models\Category;
use App\Models\Message;
use App\Models\MessageEmbedding;
use App\Models\ThreadCategory;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Classify\ThreadClassifier;
use App\Services\Ai\Retrieval\DatabaseVectorStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CategoryClassificationTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function setUpAi(?string $classifyModel = null): void
    {
        (new AiSettings('ollama', 'http://host.docker.internal:11434', 'gemma4:31b-it-qat', true, classifyModel: $classifyModel))->save();
        Category::query()->create(['name' => 'Projekt Alfa', 'description' => 'EU projekt', 'color' => '#2563eb', 'position' => 1]);
        Category::query()->create(['name' => 'Studenti', 'description' => 'Nastava i studenti', 'color' => '#d97706', 'position' => 2]);
    }

    private function message(Account $account, string $thread, array $attrs = []): Message
    {
        $n = ++$this->seq;

        return Message::query()->forceCreate(array_merge([
            'account_id' => $account->id, 'provider_message_id' => "m{$n}", 'thread_id' => $thread,
            'subject' => 'Ispitni rokovi', 'from_name' => 'Ana', 'from_email' => 'ana@student.example',
            'to_recipients' => [['name' => 'Me', 'email' => $account->email]], 'cc_recipients' => [], 'bcc_recipients' => [], 'reply_to' => [],
            'snippet' => 'Kada su rokovi?', 'body_text' => "Kada su ispitni rokovi? (poruka {$n})", 'body_html' => null, 'headers' => [],
            'label_ids' => ['INBOX'], 'is_unread' => false, 'is_starred' => false, 'has_attachments' => false,
            'size_estimate' => 1, 'sent_at' => now()->subMinutes(100 - $n), 'received_at' => now()->subMinutes(100 - $n),
        ], $attrs));
    }

    /** Ollama answers every /api/chat with $answer (as JSON content). */
    private function fakeOllama(array $answer): void
    {
        Http::fake([
            'host.docker.internal:11434/api/show' => Http::response(['capabilities' => ['completion', 'thinking']]),
            'host.docker.internal:11434/api/chat' => Http::response(['message' => ['role' => 'assistant', 'content' => json_encode($answer)]]),
        ]);
    }

    public function test_a_thread_gets_one_category_and_its_unread_mail_an_urgency_from_the_whole_conversation(): void
    {
        $this->setUpAi();
        $account = $this->makeAccount();
        $first = $this->message($account, 't1', ['body_text' => 'Prva poruka: trebam termine ispita.']);
        $unread = $this->message($account, 't1', ['is_unread' => true, 'body_text' => 'Podsjećam, rok je sutra!']);
        $this->fakeOllama(['category' => 'Studenti', 'urgency' => 'high']);

        $result = app(ThreadClassifier::class)->classifyAccount($account);

        $this->assertSame(['classified' => 1, 'urgency' => 1, 'remaining' => 0], $result);
        $this->assertSame('Studenti', ThreadCategory::query()->where('thread_id', 't1')->first()->category->name);
        $this->assertSame('high', $unread->fresh()->urgency);
        $this->assertNull($first->fresh()->urgency, 'read mail gets no urgency');

        // One call (same model for both); it carried the whole thread, an enum of the categories, and no thinking.
        Http::assertSentCount(2); // show + chat
        Http::assertSent(function (Request $r) {
            if (! str_ends_with($r->url(), '/api/chat')) {
                return false;
            }
            $conversation = $r['messages'][1]['content'];

            return str_contains($conversation, 'Prva poruka') && str_contains($conversation, 'rok je sutra')
                && $r['format']['properties']['category']['enum'] === ['Projekt Alfa', 'Studenti', 'Other']
                && $r['format']['properties']['urgency']['enum'] === ['high', 'medium', 'low']
                && $r['think'] === false;
        });
    }

    public function test_categories_come_from_the_small_model_and_urgency_from_the_chat_model(): void
    {
        $this->setUpAi(classifyModel: 'gemma4:e4b-it-qat');
        $account = $this->makeAccount();
        $this->message($account, 't1', ['is_unread' => true]);
        Http::fake([
            'host.docker.internal:11434/api/show' => Http::response(['capabilities' => ['completion']]),
            'host.docker.internal:11434/api/chat' => function (Request $r) {
                $content = $r['model'] === 'gemma4:e4b-it-qat' ? ['category' => 'Studenti'] : ['urgency' => 'medium'];

                return Http::response(['message' => ['role' => 'assistant', 'content' => json_encode($content)]]);
            },
        ]);

        app(ThreadClassifier::class)->classifyAccount($account);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/chat') && $r['model'] === 'gemma4:e4b-it-qat'
            && isset($r['format']['properties']['category']) && ! isset($r['format']['properties']['urgency']));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/chat') && $r['model'] === 'gemma4:31b-it-qat'
            && isset($r['format']['properties']['urgency']) && ! isset($r['format']['properties']['category']));
        $this->assertSame('medium', Message::query()->where('thread_id', 't1')->value('urgency'));
        $this->assertSame('gemma4:e4b-it-qat', ThreadCategory::query()->value('model'));
    }

    public function test_a_hand_picked_category_is_never_overridden_and_becomes_an_example(): void
    {
        $this->setUpAi();
        $account = $this->makeAccount();
        $picked = $this->message($account, 't1', ['subject' => 'HPC webinar']);
        $this->message($account, 't2');
        $euro = Category::query()->where('name', 'Projekt Alfa')->first();

        $this->putJson("/api/messages/{$picked->id}/category", ['category_id' => $euro->id])->assertOk()
            ->assertJsonPath('data.category_source', 'user');

        $this->fakeOllama(['category' => 'Studenti']);
        app(ThreadClassifier::class)->classifyAccount($account);

        $this->assertSame($euro->id, ThreadCategory::query()->where('thread_id', 't1')->value('category_id'));
        $this->assertSame('user', ThreadCategory::query()->where('thread_id', 't1')->value('source'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/chat')
            && str_contains($r['messages'][0]['content'], '"HPC webinar" (from Ana) → Projekt Alfa'));

        // Re-sorting keeps it too.
        Queue::fake();
        $this->postJson('/api/categories/reclassify')->assertStatus(202);
        $this->assertSame('user', ThreadCategory::query()->where('thread_id', 't1')->value('source'));
        $this->assertNull(ThreadCategory::query()->where('thread_id', 't2')->first());
        Queue::assertPushed(ClassifyAccountJob::class);
    }

    public function test_an_unknown_answer_falls_back_to_ostalo_and_low(): void
    {
        $this->setUpAi();
        $account = $this->makeAccount();
        $m = $this->message($account, 't1', ['is_unread' => true]);
        $this->fakeOllama(['category' => 'Something invented', 'urgency' => 'very']);

        app(ThreadClassifier::class)->classifyAccount($account);

        $this->assertSame('Other', ThreadCategory::query()->first()->category->name);
        $this->assertSame('low', $m->fresh()->urgency);
    }

    public function test_spam_and_trash_are_never_sorted_and_nothing_runs_without_categories(): void
    {
        (new AiSettings('ollama', 'http://host.docker.internal:11434', 'gemma4:31b-it-qat', true))->save();
        $account = $this->makeAccount();
        $this->message($account, 't1');
        Http::fake();

        // Only the fallback exists: sorting is off.
        $this->assertSame(['classified' => 0, 'urgency' => 0, 'remaining' => 0], app(ThreadClassifier::class)->classifyAccount($account));
        Http::assertNothingSent();

        $this->setUpAi();
        $this->message($account, 'spam', ['label_ids' => ['SPAM'], 'is_unread' => true]);
        $this->message($account, 'trash', ['label_ids' => ['TRASH']]);
        $this->fakeOllama(['category' => 'Studenti']);
        app(ThreadClassifier::class)->classifyAccount($account);

        $this->assertSame(['t1'], ThreadCategory::query()->pluck('thread_id')->all());
    }

    public function test_category_view_lists_whole_threads_from_every_folder_and_the_sidebar_counts_them(): void
    {
        $this->setUpAi();
        $account = $this->makeAccount();
        $studenti = Category::query()->where('name', 'Studenti')->first();
        $in = $this->message($account, 't1', ['is_unread' => true, 'urgency' => 'high']);
        $sent = $this->message($account, 't1', ['label_ids' => ['SENT'], 'from_email' => $account->email]);
        $this->message($account, 't2');
        ThreadCategory::query()->create(['account_id' => $account->id, 'thread_id' => 't1', 'category_id' => $studenti->id, 'source' => 'ai']);

        $ids = collect($this->getJson("/api/accounts/{$account->id}/messages?category={$studenti->id}")->assertOk()->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$in->id, $sent->id], $ids);
        $this->getJson("/api/accounts/{$account->id}/messages?folder=inbox")->assertJsonPath('data.1.urgency', 'high');

        $this->getJson("/api/accounts/{$account->id}/categories")->assertOk()
            ->assertJsonPath('data.1.name', 'Studenti')
            ->assertJsonPath('data.1.threads', 1)
            ->assertJsonPath('data.1.unread', 1)
            ->assertJsonPath('meta.progress.total', 2)
            ->assertJsonPath('meta.progress.done', 1);

        $this->getJson("/api/messages/{$in->id}")->assertOk()->assertJsonPath('data.category_id', $studenti->id)->assertJsonPath('data.category_source', 'ai');
    }

    public function test_categories_can_be_managed_but_the_fallback_cannot_be_deleted(): void
    {
        $this->setUpAi();
        $id = $this->postJson('/api/categories', ['name' => 'SUMIT', 'description' => 'IT', 'color' => '#9333ea'])->assertCreated()->json('data.id');
        $this->putJson("/api/categories/{$id}", ['description' => 'IT SUM-a'])->assertOk()->assertJsonPath('data.description', 'IT SUM-a');
        $this->postJson('/api/categories', ['name' => 'SUMIT'])->assertStatus(422);
        $this->deleteJson("/api/categories/{$id}")->assertOk();
        $this->deleteJson('/api/categories/'.Category::fallback()->id)->assertStatus(422);
    }

    public function test_rebuild_everything_resets_what_the_ai_derived_and_keeps_hand_made_choices(): void
    {
        $this->setUpAi();
        AiSettings::load()->with(['embedding_model' => 'bge-m3'])->save();
        $account = $this->makeAccount();
        $unread = $this->message($account, 't1', ['is_unread' => true, 'urgency' => 'high']);
        $read = $this->message($account, 't2', ['urgency' => 'medium']);
        $studenti = Category::query()->where('name', 'Studenti')->first();
        ThreadCategory::query()->create(['account_id' => $account->id, 'thread_id' => 't1', 'category_id' => $studenti->id, 'source' => 'ai']);
        ThreadCategory::query()->create(['account_id' => $account->id, 'thread_id' => 't2', 'category_id' => $studenti->id, 'source' => 'user']);
        app(DatabaseVectorStore::class)->upsert($unread, 'bge-m3:latest', 'x', [0.1, 0.2]);

        Queue::fake();
        $res = $this->postJson('/api/ai/rebuild')->assertStatus(202);
        $this->assertSame(1, $res->json('data')[$account->email]['categories']);

        $this->assertSame(0, MessageEmbedding::query()->count());
        $this->assertSame(['t2'], ThreadCategory::query()->pluck('thread_id')->all());
        $this->assertNull($unread->fresh()->urgency);
        $this->assertSame('medium', $read->fresh()->urgency, 'read mail keeps its old urgency (it is never judged again)');
        Queue::assertPushed(EmbedAccountJob::class);
        Queue::assertPushed(ClassifyAccountJob::class);
    }

    public function test_only_the_last_n_days_are_sorted_when_limited_but_whole_threads_are_read(): void
    {
        $this->setUpAi();
        AiSettings::load()->with(['classify_since_days' => 14])->save();
        $account = $this->makeAccount();
        $this->message($account, 'old', ['received_at' => now()->subDays(60)]);
        $this->message($account, 'recent', ['received_at' => now()->subDays(40), 'body_text' => 'Davni početak razgovora']);
        $this->message($account, 'recent', ['received_at' => now()->subDays(2)]);
        $this->fakeOllama(['category' => 'Studenti']);

        app(ThreadClassifier::class)->classifyAccount($account);

        $this->assertSame(['recent'], ThreadCategory::query()->pluck('thread_id')->all());
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/chat') && str_contains($r['messages'][1]['content'], 'Davni početak'));
        $this->assertSame(['total' => 1, 'done' => 1, 'unread_pending' => 0], app(ThreadClassifier::class)->progress($account));
    }

    public function test_urgency_prompt_carries_the_local_date_and_time(): void
    {
        config(['sovera.ai.timezone' => 'Europe/Sarajevo']);
        $this->travelTo(Carbon::parse('2026-11-09 17:30:00', 'UTC'));
        $this->setUpAi();
        $account = $this->makeAccount();
        $this->message($account, 't1', ['is_unread' => true, 'received_at' => now()->subHour(), 'sent_at' => now()->subHour()]);
        $this->fakeOllama(['category' => 'Studenti', 'urgency' => 'low']);

        app(ThreadClassifier::class)->classifyAccount($account);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/chat')
            && str_contains($r['messages'][0]['content'], 'Right now it is Monday, 9 November 2026, 18:30 (Europe/Sarajevo)')
            && str_contains($r['messages'][1]['content'], 'Mon 2026-11-09 17:30'));
    }

    public function test_models_stay_in_ram_until_the_user_unloads_them(): void
    {
        $this->setUpAi(classifyModel: 'gemma4:e4b-it-qat');
        $account = $this->makeAccount();
        $this->message($account, 't1', ['is_unread' => true]);
        Http::fake([
            'host.docker.internal:11434/api/show' => Http::response(['capabilities' => ['completion']]),
            'host.docker.internal:11434/api/chat' => Http::response(['message' => ['role' => 'assistant', 'content' => '{"category":"Studenti","urgency":"low"}']]),
            'host.docker.internal:11434/api/generate' => Http::response(['done' => true]),
        ]);

        (new ClassifyAccountJob($account->id))->handle(app(ThreadClassifier::class));

        // No countdown, no unload by the job: only "Unload" takes a model out.
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/chat') && $r['keep_alive'] === -1);
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/api/generate'));
    }

    public function test_model_comparison_scores_against_hand_sorted_threads_and_prints_no_mail(): void
    {
        $this->setUpAi();
        $account = $this->makeAccount();
        $studenti = Category::query()->where('name', 'Studenti')->first();
        $euro = Category::query()->where('name', 'Projekt Alfa')->first();
        $this->message($account, 't1', ['subject' => 'Tajni naslov jedan']);
        $this->message($account, 't2', ['subject' => 'Tajni naslov dva']);
        ThreadCategory::query()->create(['account_id' => $account->id, 'thread_id' => 't1', 'category_id' => $studenti->id, 'source' => 'user']);
        ThreadCategory::query()->create(['account_id' => $account->id, 'thread_id' => 't2', 'category_id' => $euro->id, 'source' => 'user']);
        Http::fake([
            'host.docker.internal:11434/api/show' => Http::response(['capabilities' => ['completion']]),
            'host.docker.internal:11434/api/chat' => Http::response(['message' => ['role' => 'assistant', 'content' => '{"category":"Studenti"}']]),
            'host.docker.internal:11434/api/generate' => Http::response(['done' => true]),
        ]);

        $this->artisan('sovera:eval-classifier', ['--models' => 'm1'])
            ->expectsOutputToContain('1/2 (50%)')
            ->doesntExpectOutputToContain('Tajni naslov')
            ->assertSuccessful();
        $this->assertSame(2, ThreadCategory::query()->where('source', 'user')->count(), 'nothing is written');
    }

    public function test_a_model_without_structured_output_gets_the_schema_in_the_prompt(): void
    {
        $this->setUpAi();
        $account = $this->makeAccount();
        $this->message($account, 't1');
        $calls = 0;
        Http::fake([
            'host.docker.internal:11434/api/show' => Http::response(['capabilities' => ['completion']]),
            'host.docker.internal:11434/api/chat' => function (Request $r) use (&$calls) {
                $calls++;

                return isset($r['format'])
                    ? Http::response(['error' => 'format not supported'], 501)
                    : Http::response(['message' => ['role' => 'assistant', 'content' => 'Evo: {"category": "Studenti"}']]);
            },
        ]);

        app(ThreadClassifier::class)->classifyAccount($account);

        $this->assertSame(2, $calls);
        $this->assertSame('Studenti', ThreadCategory::query()->first()->category->name);
    }

    public function test_several_conversations_move_to_a_category_at_once(): void
    {
        $this->setUpAi();
        $account = $this->makeAccount();
        $a1 = $this->message($account, 'a');
        $a2 = $this->message($account, 'a'); // same conversation as $a1
        $b = $this->message($account, 'b');
        $c = $this->message($account, 'c');
        $studenti = Category::query()->where('name', 'Studenti')->first();
        $euro = Category::query()->where('name', 'Projekt Alfa')->first();
        ThreadCategory::query()->create(['account_id' => $account->id, 'thread_id' => 'b', 'category_id' => $studenti->id, 'source' => 'ai']);

        $this->putJson('/api/messages/category', ['category_id' => $euro->id, 'message_ids' => [$a1->id, $a2->id, $b->id]])->assertOk()
            ->assertJsonPath('data.conversations', 2);

        foreach (['a', 'b'] as $thread) {
            $row = ThreadCategory::query()->where('thread_id', $thread)->sole();
            $this->assertSame($euro->id, $row->category_id);
            $this->assertSame('user', $row->source, 'picked by hand: the model never moves it back');
        }
        $this->assertNull(ThreadCategory::query()->where('thread_id', 'c')->first(), 'unselected conversations stay as they were');

        $this->putJson('/api/messages/category', ['category_id' => 999, 'message_ids' => [$c->id]])->assertStatus(422);
        $this->putJson('/api/messages/category', ['category_id' => $euro->id, 'message_ids' => []])->assertStatus(422);
    }
}
