<?php

namespace Tests\Feature\Api;

use App\Models\Message;
use App\Services\Ai\AiSettings;
use App\Services\Ai\ModelRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiTest extends TestCase
{
    use RefreshDatabase;

    private function message(): Message
    {
        $account = $this->makeAccount(['display_name' => 'Davor Test']);

        return Message::query()->create([
            'account_id' => $account->id, 'provider_message_id' => 'gm-1', 'thread_id' => 'thr-1',
            'subject' => 'Sastanak', 'from_name' => 'Ana', 'from_email' => 'ana@example.com',
            'to_recipients' => [], 'cc_recipients' => [], 'bcc_recipients' => [], 'reply_to' => [],
            'snippet' => 'x', 'body_text' => 'Može li sastanak u četvrtak u 10?', 'body_html' => null, 'headers' => [],
            'label_ids' => ['INBOX'], 'is_unread' => false, 'is_starred' => false, 'has_attachments' => false,
            'size_estimate' => 1, 'sent_at' => now(), 'received_at' => now(),
        ]);
    }

    public function test_settings_default_and_update(): void
    {
        $this->getJson('/api/ai/settings')->assertOk()
            ->assertJsonPath('data.provider', 'ollama')
            ->assertJsonPath('data.ready', false);

        $this->putJson('/api/ai/settings', ['base_url' => 'http://localhost:11434', 'model' => 'llama3.2:latest', 'enabled' => true])
            ->assertOk()
            ->assertJsonPath('data.base_url', 'http://host.docker.internal:11434')
            ->assertJsonPath('data.model', 'llama3.2:latest')
            ->assertJsonPath('data.ready', true);

        $this->assertTrue(AiSettings::load()->isReady());
    }

    public function test_probe_lists_models(): void
    {
        Http::fake([
            'host.docker.internal:11434/api/tags' => Http::response(['models' => [
                ['name' => 'llama3.2:latest', 'size' => 2000000000, 'details' => ['family' => 'llama', 'parameter_size' => '3.2B', 'quantization_level' => 'Q4_K_M']],
            ]]),
            'host.docker.internal:11434/api/version' => Http::response(['version' => '0.6.0']),
            'host.docker.internal:11434/api/show' => Http::response(['capabilities' => ['completion']]),
        ]);

        $this->postJson('/api/ai/probe', ['base_url' => 'localhost:11434'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('version', '0.6.0')
            ->assertJsonPath('models.0.name', 'llama3.2:latest')
            ->assertJsonPath('models.0.parameters', '3.2B')
            ->assertJsonPath('models.0.capabilities', ['completion']);
    }

    public function test_probe_reports_unreachable_server(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $this->postJson('/api/ai/probe', ['base_url' => 'http://host.docker.internal:11434'])
            ->assertStatus(503)
            ->assertJsonPath('ok', false);
    }

    public function test_draft_requires_configured_ai(): void
    {
        $message = $this->message();

        $this->postJson("/api/messages/{$message->id}/ai/draft", ['kind' => 'reply'])
            ->assertStatus(503)
            ->assertJsonPath('ai_unavailable', true);
    }

    public function test_draft_calls_ollama_with_original_message(): void
    {
        $message = $this->message();
        (new AiSettings('ollama', 'http://host.docker.internal:11434', 'llama3.2:latest', true))->save();

        Http::fake([
            'host.docker.internal:11434/api/show' => Http::response(['capabilities' => ['completion']]),
            'host.docker.internal:11434/api/chat' => Http::response([
                'message' => ['role' => 'assistant', 'content' => "<think>hm</think>Bok Ana,\n\nmože u 10.\n\nDavor"],
            ]),
        ]);

        $this->postJson("/api/messages/{$message->id}/ai/draft", ['kind' => 'reply', 'instructions' => 'kratko'])
            ->assertOk()
            ->assertJsonPath('data.text', "Bok Ana,\n\nmože u 10.\n\nDavor")
            ->assertJsonPath('data.context', []);

        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/api/chat')) {
                return false;
            }
            $body = $request->data();

            return $body['model'] === 'llama3.2:latest'
                && $body['stream'] === false
                && ! array_key_exists('think', $body)   // model cannot think -> flag not sent
                && str_contains($body['messages'][1]['content'], 'Može li sastanak u četvrtak u 10?')
                && str_contains($body['messages'][1]['content'], 'kratko')
                && str_contains($body['messages'][0]['content'], 'Davor Test <me@example.com>');
        });
    }

    public function test_reasoning_models_think_natively_when_switched_on_and_the_reasoning_never_reaches_the_draft(): void
    {
        $message = $this->message();
        (new AiSettings('ollama', 'http://host.docker.internal:11434', 'qwen3:4b', true, thinking: true))->save();

        Http::fake([
            'host.docker.internal:11434/api/show' => Http::response(['capabilities' => ['completion', 'thinking']]),
            'host.docker.internal:11434/api/chat' => Http::response(['message' => ['role' => 'assistant', 'thinking' => 'The user wants a reply...', 'content' => 'Bok Ana, može.']]),
        ]);

        $this->postJson("/api/messages/{$message->id}/ai/draft", ['kind' => 'reply'])->assertOk()->assertJsonPath('data.text', 'Bok Ana, može.');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/chat') && $r['think'] === true);
    }

    public function test_thinking_is_off_by_default_and_can_be_switched_on_in_settings(): void
    {
        // Off: on real mail prompts Gemma 4 reasoned for minutes (or never finished) before writing.
        $this->getJson('/api/ai/settings')->assertOk()->assertJsonPath('data.thinking', false);

        $this->putJson('/api/ai/settings', ['thinking' => true])->assertOk()->assertJsonPath('data.thinking', true);
        $this->assertTrue(AiSettings::load()->thinking);

        // Saving other fields keeps the choice.
        $this->putJson('/api/ai/settings', ['streaming' => false])->assertOk()->assertJsonPath('data.thinking', true);
    }

    public function test_reasoning_models_skip_thinking_when_the_user_switched_it_off(): void
    {
        $message = $this->message();
        (new AiSettings('ollama', 'http://host.docker.internal:11434', 'gemma4:31b-it-qat', true))->with(['thinking' => false])->save();

        Http::fake([
            'host.docker.internal:11434/api/show' => Http::response(['capabilities' => ['completion', 'thinking']]),
            'host.docker.internal:11434/api/chat' => Http::response(['message' => ['role' => 'assistant', 'content' => 'Bok Ana, može.']]),
        ]);

        $this->postJson("/api/messages/{$message->id}/ai/draft", ['kind' => 'reply'])->assertOk()->assertJsonPath('data.text', 'Bok Ana, može.');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/chat') && $r['think'] === false);
    }

    public function test_switching_thinking_off_sends_no_flag_to_non_reasoning_models(): void
    {
        $message = $this->message();
        (new AiSettings('ollama', 'http://host.docker.internal:11434', 'llama3.2:latest', true))->with(['thinking' => false])->save();

        Http::fake([
            'host.docker.internal:11434/api/show' => Http::response(['capabilities' => ['completion']]),
            'host.docker.internal:11434/api/chat' => Http::response(['message' => ['role' => 'assistant', 'content' => 'Bok Ana.']]),
        ]);

        $this->postJson("/api/messages/{$message->id}/ai/draft", ['kind' => 'reply'])->assertOk();
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/chat') && ! array_key_exists('think', $r->data()));
    }

    public function test_non_reasoning_models_get_no_think_flag_and_inline_think_tags_are_stripped(): void
    {
        $message = $this->message();
        (new AiSettings('ollama', 'http://host.docker.internal:11434', 'llama3.2:latest', true))->save();
        Http::fake([
            'host.docker.internal:11434/api/show' => Http::response(['capabilities' => ['completion']]),
            'host.docker.internal:11434/api/chat' => Http::response(['message' => ['role' => 'assistant', 'content' => "<think>\nhmm\n</think>\nBok Ana."]]),
        ]);
        $this->postJson("/api/messages/{$message->id}/ai/draft", ['kind' => 'reply'])->assertOk()->assertJsonPath('data.text', 'Bok Ana.');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/chat') && ! array_key_exists('think', $r->data()));
    }

    public function test_streaming_draft_emits_context_deltas_and_done(): void
    {
        $message = $this->message();
        (new AiSettings('ollama', 'http://host.docker.internal:11434', 'llama3.2:latest', true))->save();

        $ndjson = implode("\n", [
            json_encode(['message' => ['role' => 'assistant', 'content' => 'Bok '], 'done' => false]),
            json_encode(['message' => ['role' => 'assistant', 'content' => 'Ana'], 'done' => false]),
            json_encode(['message' => ['role' => 'assistant', 'content' => ''], 'done' => true]),
        ])."\n";

        Http::fake([
            'host.docker.internal:11434/api/show' => Http::response(['capabilities' => ['completion']]),
            'host.docker.internal:11434/api/chat' => Http::response($ndjson, 200, ['Content-Type' => 'application/x-ndjson']),
        ]);

        $response = $this->postJson("/api/messages/{$message->id}/ai/draft", ['kind' => 'reply', 'stream' => true]);
        $response->assertOk();
        $this->assertStringStartsWith('text/event-stream', $response->headers->get('Content-Type'));

        $body = $response->streamedContent();
        $this->assertStringContainsString('event: context', $body);
        $this->assertStringContainsString('event: delta', $body);
        $this->assertStringContainsString('"text":"Bok "', $body);
        $this->assertStringContainsString('event: done', $body);
        $this->assertStringContainsString('"text":"Bok Ana"', $body);

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/chat') && $r['stream'] === true);
    }

    public function test_a_streamed_draft_is_proofread_in_a_second_pass(): void
    {
        $message = $this->message();
        (new AiSettings('ollama', 'http://host.docker.internal:11434', 'llama3.2:latest', true))->save();
        $this->assertTrue(AiSettings::load()->proofread, 'on by default');

        $ndjson = json_encode(['message' => ['role' => 'assistant', 'content' => 'Postovana Ana, saljem vam izvjestaj do petka.'], 'done' => true])."\n";
        Http::fake([
            'host.docker.internal:11434/api/show' => Http::response(['capabilities' => ['completion']]),
            'host.docker.internal:11434/api/chat' => Http::sequence()
                ->push($ndjson, 200, ['Content-Type' => 'application/x-ndjson'])
                ->push(['message' => ['role' => 'assistant', 'content' => 'Poštovana Ana, šaljem vam izvještaj do petka.']]),
        ]);

        $body = $this->postJson("/api/messages/{$message->id}/ai/draft", ['kind' => 'reply', 'stream' => true])->assertOk()->streamedContent();

        $this->assertStringContainsString('event: stage', $body);
        $this->assertStringContainsString('"stage":"proofreading"', $body);
        $this->assertStringContainsString('Po\\u0161tovana Ana, \\u0161aljem vam izvje\\u0161taj do petka.', json_encode($body));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/chat') && ($r['stream'] ?? null) === false && str_contains($r['messages'][0]['content'], 'proofreader'));

        // Switched off: one call, no stage.
        AiSettings::load()->with(['proofread' => false])->save();
        Http::fake([
            'host.docker.internal:11434/api/show' => Http::response(['capabilities' => ['completion']]),
            'host.docker.internal:11434/api/chat' => Http::response($ndjson, 200, ['Content-Type' => 'application/x-ndjson']),
        ]);
        $body = $this->postJson("/api/messages/{$message->id}/ai/draft", ['kind' => 'reply', 'stream' => true])->streamedContent();
        $this->assertStringNotContainsString('event: stage', $body);
    }

    public function test_every_chat_call_uses_one_context_size_and_an_empty_draft_is_an_error(): void
    {
        $message = $this->message();
        (new AiSettings('ollama', 'http://host.docker.internal:11434', 'llama3.2:latest', true, proofread: false))->save();
        $empty = json_encode(['message' => ['role' => 'assistant', 'content' => ''], 'done' => true])."\n";
        Http::fake([
            'host.docker.internal:11434/api/show' => Http::response(['capabilities' => ['completion']]),
            'host.docker.internal:11434/api/chat' => Http::response($empty, 200, ['Content-Type' => 'application/x-ndjson']),
        ]);

        $body = $this->postJson("/api/messages/{$message->id}/ai/draft", ['kind' => 'reply', 'stream' => true])->streamedContent();

        $this->assertStringContainsString('event: error', $body);
        $this->assertStringNotContainsString('event: done', $body);
        // Ollama reloads the model for a different num_ctx: every call asks for the same one.
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/chat') && $r['options']['num_ctx'] === AiSettings::CHAT_CONTEXT);
        $this->assertNull(Cache::get('ai:interactive-until'), 'background work may go on once the user is served');
    }

    public function test_background_work_waits_while_the_user_waits_for_the_ai(): void
    {
        $seen = null;
        ModelRuntime::interactive(function () use (&$seen) {
            $seen = Cache::get('ai:interactive-until');
        });
        $this->assertGreaterThan(now()->getTimestamp(), $seen);

        // Nobody waiting: background work goes straight on.
        $t = microtime(true);
        ModelRuntime::yieldToUser();
        $this->assertLessThan(0.5, microtime(true) - $t);

        // Someone waiting: it pauses (here at most its 1-second limit).
        Cache::put('ai:interactive-until', now()->getTimestamp() + 60, 60);
        $t = microtime(true);
        ModelRuntime::yieldToUser(1);
        $this->assertGreaterThanOrEqual(0.9, microtime(true) - $t);
    }

    public function test_the_ui_hears_that_a_reasoning_model_is_thinking(): void
    {
        $message = $this->message();
        (new AiSettings('ollama', 'http://host.docker.internal:11434', 'gemma4:26b-a4b-it-qat', true, proofread: false))->save();
        $ndjson = implode("\n", [
            json_encode(['message' => ['role' => 'assistant', 'content' => '', 'thinking' => 'Hmm, korisnik pita…'], 'done' => false]),
            json_encode(['message' => ['role' => 'assistant', 'content' => 'Bok Ana, šaljem do petka.'], 'done' => false]),
            json_encode(['message' => ['role' => 'assistant', 'content' => ''], 'done' => true]),
        ])."\n";
        Http::fake([
            'host.docker.internal:11434/api/show' => Http::response(['capabilities' => ['completion', 'thinking']]),
            'host.docker.internal:11434/api/chat' => Http::response($ndjson, 200, ['Content-Type' => 'application/x-ndjson']),
        ]);

        $body = $this->postJson("/api/messages/{$message->id}/ai/draft", ['kind' => 'reply', 'stream' => true])->streamedContent();

        $this->assertStringContainsString('"stage":"thinking"', $body);
        $this->assertStringNotContainsString('korisnik pita', $body, 'the reasoning itself is never sent');
        $this->assertLessThan(strpos($body, 'event: delta'), strpos($body, '"stage":"thinking"'));
    }
}
