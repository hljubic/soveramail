<?php

namespace Tests\Feature\Ai;

use App\Jobs\AiPassJob;
use App\Models\Account;
use App\Models\AiSummary;
use App\Models\Category;
use App\Models\Message;
use App\Models\ThreadCategory;
use App\Services\Ai\AiSettings;
use App\Services\Ai\ModelRuntime;
use App\Services\Ai\Processing\AiPass;
use App\Services\Ai\Processing\NewMailProcessor;
use App\Services\Ai\Proofreader;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * "Process new mail": nothing runs on its own by default; one click reads every new
 * conversation once (category, urgency, summaries in one call); models are unloaded
 * only by Ollama's idle timer or the user's "Unload" of a model Sovera loaded.
 */
class AiPassTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function setUpAi(array $changes = []): void
    {
        (new AiSettings('ollama', 'http://host.docker.internal:11434', 'gemma4:31b-it-qat', true, embeddingModel: null))->with($changes + ['summary_min_words' => 20, 'proofread' => false])->save();
        Category::query()->create(['name' => 'Studenti', 'description' => 'Nastava', 'color' => '#d97706', 'position' => 1]);
    }

    private function message(Account $account, string $thread, array $attrs = []): Message
    {
        $n = ++$this->seq;

        return Message::query()->forceCreate(array_merge([
            'account_id' => $account->id, 'provider_message_id' => "m{$n}", 'thread_id' => $thread,
            'subject' => 'Ispit', 'from_name' => 'Ana', 'from_email' => 'ana@student.example',
            'to_recipients' => [['name' => 'Me', 'email' => $account->email]], 'cc_recipients' => [], 'bcc_recipients' => [], 'reply_to' => [],
            'snippet' => 'x', 'body_text' => str_repeat("Rok za predaju seminara je petak u podne, molim potvrdu. ({$n}) ", 4), 'body_html' => null, 'headers' => [],
            'label_ids' => ['INBOX'], 'is_unread' => true, 'is_starred' => false, 'has_attachments' => false,
            'size_estimate' => 1, 'sent_at' => now()->subMinutes(100 - $n), 'received_at' => now()->subMinutes(100 - $n),
        ], $attrs));
    }

    /** One slice of the pass, in-process (the queue is faked, so follow-ups are only recorded). */
    private function runPhase(int $accountId, string $phase): void
    {
        $job = new AiPassJob($accountId, $phase);
        (new UniqueLock(app('cache.store')))->release($job); // a real worker releases it when the job starts
        app()->call([$job, 'handle']);
    }

    private function fakeOllama(): void
    {
        Http::fake([
            'host.docker.internal:11434/api/show' => Http::response(['capabilities' => ['completion']]),
            'host.docker.internal:11434/api/chat' => Http::response(['message' => ['role' => 'assistant', 'content' => json_encode([
                'category' => 'Studenti', 'urgency' => 'high', 'summary' => ['Rok: petak', '- Traži se potvrda'], 'latest' => ['Podsjetnik na rok'],
            ])]]),
            'host.docker.internal:11434/api/ps' => Http::response(['models' => []]),
        ]);
    }

    public function test_nothing_runs_on_its_own(): void
    {
        $this->setUpAi();
        $account = $this->makeAccount();
        $this->message($account, 't1');
        Queue::fake();

        $this->getJson('/api/accounts')->assertOk();
        Queue::assertNothingPushed();
        $this->getJson('/api/ai/runtime')->assertOk()
            ->assertJsonPath('data.pending.threads', 1)
            ->assertJsonPath('data.has_work', true);
    }

    public function test_one_click_reads_each_new_conversation_once(): void
    {
        $this->setUpAi();
        $account = $this->makeAccount();
        $this->message($account, 'conv', ['is_unread' => false]);
        $latest = $this->message($account, 'conv');
        $this->message($account, 'short', ['body_text' => 'Hvala!']);
        $this->message($account, 'news', ['headers' => ['List-Unsubscribe' => '<mailto:x@y>']]);
        $this->message($account, 'old', ['received_at' => now()->subDays(90)]);
        $this->fakeOllama();

        Queue::fake();
        $this->postJson('/api/ai/process')->assertStatus(202)->assertJsonPath('queued', 1);
        Queue::assertPushed(AiPassJob::class);
        $this->runPhase($account->id, 'threads');

        // conv, short and news: one call each; the 90-day-old conversation is left for "Re-sort".
        $chats = Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/api/chat'));
        $this->assertCount(3, $chats);
        $this->assertSame(3, ThreadCategory::query()->count());
        $this->assertNull(ThreadCategory::query()->where('thread_id', 'old')->first());
        $this->assertSame('high', $latest->fresh()->urgency);

        // The conversation with an unread reply: summary of the whole thread and of the reply, from the same call.
        $thread = AiSummary::query()->where('scope', 'thread')->sole();
        $this->assertSame($latest->id, $thread->message_id);
        $this->assertSame("- Rok: petak\n- Traži se potvrda", $thread->summary);
        $this->assertSame('- Podsjetnik na rok', AiSummary::query()->where('scope', 'message')->sole()->summary);
        $convCall = collect($chats)->first(fn ($pair) => str_contains($pair[0]['messages'][1]['content'], '(2)'))[0];
        $this->assertEqualsCanonicalizing(['category', 'urgency', 'summary', 'latest'], $convCall['format']['required']);

        // Short mail and newsletters: category and urgency only.
        foreach ($chats as [$r]) {
            if (! str_contains($r['messages'][1]['content'], '(2)')) {
                $this->assertArrayNotHasKey('summary', $r['format']['properties']);
            }
        }

        // The button now reads what was stored.
        $this->postJson("/api/messages/{$latest->id}/ai/summary")->assertOk()->assertJsonPath('data.stored', true);

        // The pass goes on with the other phases, then is done; nothing is left for the next click.
        Queue::assertPushed(AiPassJob::class, fn (AiPassJob $j) => $j->phase === 'style');
        foreach (['style', 'knowledge', 'index'] as $phase) {
            $this->runPhase($account->id, $phase);
        }
        $this->assertSame('done', AiPass::state($account->id)['state']);
        $this->assertSame([], app(NewMailProcessor::class)->pendingThreads($account));
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/api/generate'));
    }

    public function test_unload_is_offered_only_for_models_sovera_uses(): void
    {
        $this->setUpAi();
        $this->makeAccount();
        ModelRuntime::used('gemma4:31b-it-qat');
        Http::fake([
            'host.docker.internal:11434/api/ps' => Http::response(['models' => [
                ['name' => 'gemma4:31b-it-qat', 'size' => 20_000_000_000, 'size_vram' => 20_000_000_000, 'expires_at' => now()->addMinutes(4)->toIso8601String()],
                ['name' => 'llama3:8b', 'size' => 5_000_000_000, 'size_vram' => 5_000_000_000, 'expires_at' => now()->addMinutes(4)->toIso8601String()],
            ]]),
            'host.docker.internal:11434/api/generate' => Http::response(['done' => true]),
        ]);

        $this->getJson('/api/ai/runtime')->assertOk()
            ->assertJsonPath('data.models.0.ours', true)
            ->assertJsonPath('data.models.1.ours', false);

        $this->postJson('/api/ai/runtime/unload', ['model' => 'llama3:8b'])->assertStatus(409);
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/api/generate'));

        $this->postJson('/api/ai/runtime/unload', ['model' => 'gemma4:31b-it-qat'])->assertOk()->assertJsonPath('unloaded', true);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/generate') && $r['model'] === 'gemma4:31b-it-qat' && $r['keep_alive'] === 0);
    }

    public function test_summaries_get_a_second_pass_that_fixes_the_language(): void
    {
        $this->setUpAi(['proofread' => true]);
        $account = $this->makeAccount();
        $this->message($account, 'solo');
        Http::fake([
            'host.docker.internal:11434/api/show' => Http::response(['capabilities' => ['completion']]),
            'host.docker.internal:11434/api/chat' => Http::sequence()
                ->push(['message' => ['role' => 'assistant', 'content' => json_encode(['category' => 'Studenti', 'urgency' => 'low', 'summary' => ['Rok za predaju je petak', 'Trazi se potvrdjivanje']])]])
                ->push(['message' => ['role' => 'assistant', 'content' => "- Rok za predaju je petak\n- Traži se potvrda"]]),
        ]);

        $this->runPhase($account->id, 'threads');

        $this->assertSame("- Rok za predaju je petak\n- Traži se potvrda", AiSummary::query()->sole()->summary);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/chat')
            && str_contains($r['messages'][0]['content'], 'proofreader')
            && str_contains($r['messages'][1]['content'], 'Trazi se potvrdjivanje'));
    }

    public function test_a_proofreading_answer_that_rewrites_the_text_is_ignored(): void
    {
        $this->setUpAi(['proofread' => true]);
        Http::fake([
            'host.docker.internal:11434/api/show' => Http::response(['capabilities' => ['completion']]),
            'host.docker.internal:11434/api/chat' => Http::response(['message' => ['role' => 'assistant', 'content' => 'Ok.']]),
        ]);
        $text = "Poštovana Ana,\n\nhvala na poruci. Rok je petak u podne.\n\nLijep pozdrav";

        $this->assertSame($text, app(Proofreader::class)->fix($text, AiSettings::load()));
    }
}
