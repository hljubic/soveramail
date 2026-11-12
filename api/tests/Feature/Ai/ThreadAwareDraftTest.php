<?php

namespace Tests\Feature\Ai;

use App\Models\Account;
use App\Models\Message;
use App\Services\Ai\AiSettings;
use App\Services\Ai\RagSettings;
use App\Services\Ai\Retrieval\DatabaseVectorStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Replying inside a thread: the thread is the conversation (shown as history),
 * never a style example or a "similar conversation". Retrieval finds *other*
 * threads and shows how the user answered there.
 */
class ThreadAwareDraftTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function message(Account $a, string $thread, string $from, string $body, array $labels = ['INBOX'], ?string $subject = null): Message
    {
        $n = ++$this->seq;
        $mine = $from === 'me@example.com';

        return Message::query()->create([
            'account_id' => $a->id, 'provider_message_id' => "gm-{$n}", 'thread_id' => $thread,
            'subject' => $subject ?? "Tema {$thread}", 'from_name' => $mine ? 'Davor' : 'Ivo', 'from_email' => $from,
            'to_recipients' => [['name' => null, 'email' => $mine ? 'ivo@example.com' : 'me@example.com']],
            'cc_recipients' => [], 'bcc_recipients' => [], 'reply_to' => [], 'headers' => [],
            'snippet' => '', 'body_text' => $body, 'label_ids' => $mine ? ['SENT'] : $labels,
            'received_at' => now()->subHours(100 - $n), 'sent_at' => now()->subHours(100 - $n),
        ]);
    }

    private function fakeModel(): void
    {
        Http::fake([
            'host.docker.internal:11434/api/embed' => Http::response(['embeddings' => [[1.0, 0.0, 0.0]]]),
            'host.docker.internal:11434/api/chat' => Http::response(['message' => ['role' => 'assistant', 'content' => 'Nacrt']]),
        ]);
    }

    private function sentPrompt(): string
    {
        $prompt = '';
        Http::assertSent(function ($r) use (&$prompt) {
            if (str_ends_with($r->url(), '/api/chat')) {
                $prompt = $r['messages'][1]['content'];

                return true;
            }

            return false;
        });

        return $prompt;
    }

    public function test_the_thread_is_history_in_the_prompt_and_never_a_style_or_similarity_example(): void
    {
        (new AiSettings('ollama', 'http://host.docker.internal:11434', 'qwen3:8b', true, 'nomic-embed-text:latest'))->save();
        $a = $this->makeAccount(['display_name' => 'Davor']);

        // The thread being answered: Ivo asked, I answered, Ivo asks again.
        $this->message($a, 'T1', 'ivo@example.com', 'Pozdrav, možemo li dogovoriti termin za obranu?');
        $this->message($a, 'T1', 'me@example.com', 'Može, predlažem četvrtak u 10. THREAD-REPLY-TEXT');
        $last = $this->message($a, 'T1', 'ivo@example.com', 'Četvrtak mi ne odgovara, može petak?');

        // An older, separate exchange with Ivo: style example + similar conversation with my answer.
        $old = $this->message($a, 'T0', 'ivo@example.com', 'Kad je rok za prijavu teme?', subject: 'Prijava teme');
        $this->message($a, 'T0', 'me@example.com', 'Rok je 15. lipnja. OLD-REPLY-TEXT');

        $store = new DatabaseVectorStore;
        foreach (Message::query()->get() as $m) {
            // Every thread-T1 mail is a "perfect" match; retrieval must still skip them.
            $store->upsert($m, 'nomic-embed-text:latest', 'h', $m->thread_id === 'T1' ? [1.0, 0.0, 0.0] : [0.8, 0.2, 0.0]);
        }
        $this->fakeModel();

        $response = $this->postJson("/api/messages/{$last->id}/ai/draft", ['kind' => 'reply'])->assertOk();
        $prompt = $this->sentPrompt();

        // 1. Conversation history, oldest first, ends right before the mail being answered.
        $this->assertStringContainsString('Conversation so far', $prompt);
        $this->assertStringContainsString('THREAD-REPLY-TEXT', $prompt);
        $this->assertLessThan(strpos($prompt, 'Četvrtak mi ne odgovara'), strpos($prompt, 'THREAD-REPLY-TEXT'));
        $this->assertLessThan(strpos($prompt, 'THREAD-REPLY-TEXT'), strpos($prompt, 'dogovoriti termin za obranu'));

        // 2. Style example comes from the other thread, not from this one.
        $style = substr($prompt, strpos($prompt, 'How the user previously wrote'), strpos($prompt, 'Related earlier') - strpos($prompt, 'How the user previously wrote'));
        $this->assertStringContainsString('OLD-REPLY-TEXT', $style);
        $this->assertStringNotContainsString('THREAD-REPLY-TEXT', $style);

        // 3. Similar conversations: only the other thread (once, grouped), with my answer attached.
        $ids = array_column($response->json('data.context'), 'id');
        $this->assertSame([$old->id], $ids);
        $this->assertStringContainsString('Rok je 15. lipnja', $prompt);
        $this->assertStringContainsString('How the user answered (written by the user)', $prompt);
    }

    public function test_rag_settings_are_saved_validated_and_applied(): void
    {
        (new AiSettings('ollama', 'http://host.docker.internal:11434', 'qwen3:8b', true))->save();

        $this->getJson('/api/ai/settings')->assertOk()
            ->assertJsonPath('data.rag.top_k', 5)
            ->assertJsonPath('data.rag.exclude_thread', true);

        $this->putJson('/api/ai/settings', ['rag' => ['top_k' => 99]])->assertStatus(422)->assertJsonValidationErrors(['rag.top_k']);
        $this->putJson('/api/ai/settings', ['rag' => ['temperature' => 5]])->assertStatus(422);

        $this->putJson('/api/ai/settings', ['rag' => ['top_k' => 2, 'thread_messages' => 0, 'style_examples' => 0, 'temperature' => 0.1]])
            ->assertOk()
            ->assertJsonPath('data.rag.top_k', 2)
            ->assertJsonPath('data.rag.thread_messages', 0)
            ->assertJsonPath('data.rag.min_score', 0)   // untouched fields keep their value
            ->assertJsonPath('data.model', 'qwen3:8b'); // other settings untouched

        $this->assertSame(2, AiSettings::load()->rag()->topK);
        $this->assertInstanceOf(RagSettings::class, AiSettings::load()->rag());

        // thread_messages = 0 and style_examples = 0 -> no history, no style block; temperature reaches Ollama.
        $a = $this->makeAccount();
        $this->message($a, 'T1', 'ivo@example.com', 'prvi');
        $this->message($a, 'T1', 'me@example.com', 'moj odgovor');
        $last = $this->message($a, 'T1', 'ivo@example.com', 'drugi');
        $this->fakeModel();

        $this->postJson("/api/messages/{$last->id}/ai/draft", ['kind' => 'reply'])->assertOk();
        $prompt = $this->sentPrompt();
        $this->assertStringNotContainsString('Conversation so far', $prompt);
        $this->assertStringNotContainsString('How the user previously wrote', $prompt);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/chat') && $r['options']['temperature'] === 0.1);
    }
}
