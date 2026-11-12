<?php

namespace Tests\Feature\Ai;

use App\Jobs\EmbedAccountJob;
use App\Models\Account;
use App\Models\Message;
use App\Models\MessageEmbedding;
use App\Services\Ai\AiSettings;
use App\Services\Ai\AiUnavailableException;
use App\Services\Ai\DraftService;
use App\Services\Ai\Retrieval\DatabaseVectorStore;
use App\Services\Ai\Retrieval\EmbeddingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RetrievalTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function account(string $email): Account
    {
        return $this->makeAccount(['email' => $email, 'display_name' => 'User '.$email]);
    }

    private function message(Account $account, string $subject, string $body, array $labels = ['INBOX'], ?string $from = null): Message
    {
        $n = ++$this->seq;

        return Message::query()->create([
            'account_id' => $account->id, 'provider_message_id' => "gm-{$n}", 'thread_id' => "thr-{$n}",
            'subject' => $subject, 'from_name' => 'Sender', 'from_email' => $from ?? 'sender@example.com',
            'to_recipients' => [['name' => null, 'email' => $account->email]], 'cc_recipients' => [], 'bcc_recipients' => [], 'reply_to' => [],
            'snippet' => mb_substr($body, 0, 50), 'body_text' => $body, 'body_html' => null, 'headers' => [],
            'label_ids' => $labels, 'is_unread' => false, 'is_starred' => false, 'has_attachments' => false,
            'size_estimate' => 1, 'sent_at' => now()->subMinutes($n), 'received_at' => now()->subMinutes($n),
        ]);
    }

    private function enableAi(): void
    {
        (new AiSettings('ollama', 'http://host.docker.internal:11434', 'qwen3:8b', true, 'nomic-embed-text:latest'))->save();
    }

    public function test_vector_store_never_returns_other_accounts_messages(): void
    {
        $store = new DatabaseVectorStore;
        $a = $this->account('a@example.com');
        $b = $this->account('b@example.com');

        $ma = $this->message($a, 'Server offer', 'offer for a server');
        $mb = $this->message($b, 'Server offer', 'offer for a server');   // identical content, other mailbox
        $ma2 = $this->message($a, 'Lunch', 'lunch on friday');

        $store->upsert($ma, 'm', 'h', [1.0, 0.0, 0.0]);
        $store->upsert($mb, 'm', 'h', [1.0, 0.0, 0.0]);
        $store->upsert($ma2, 'm', 'h', [0.0, 1.0, 0.0]);

        $hits = $store->search($a->id, 'm', [1.0, 0.1, 0.0], 10);

        $this->assertSame([$ma->id, $ma2->id], array_column($hits, 'message_id'));
        $this->assertNotContains($mb->id, array_column($hits, 'message_id'));

        // excluding the query message itself
        $hits = $store->search($a->id, 'm', [1.0, 0.0, 0.0], 10, [$ma->id]);
        $this->assertSame([$ma2->id], array_column($hits, 'message_id'));

        // model mismatch -> nothing
        $this->assertSame([], $store->search($a->id, 'other-model', [1.0, 0.0, 0.0], 10));
    }

    public function test_index_account_embeds_pending_messages_in_batches(): void
    {
        $this->enableAi();
        $a = $this->account('a@example.com');
        $m1 = $this->message($a, 'One', 'first');
        $m2 = $this->message($a, 'Two', 'second');

        Http::fake([
            'host.docker.internal:11434/api/embed' => Http::response(['embeddings' => [[0.1, 0.2, 0.3], [0.3, 0.2, 0.1]]]),
        ]);

        $result = app(EmbeddingService::class)->indexAccount($a);

        $this->assertSame(['embedded' => 2, 'skipped' => 0, 'remaining' => 0], $result);
        $this->assertSame(2, MessageEmbedding::query()->where('account_id', $a->id)->count());
        $this->assertSame('nomic-embed-text:latest', MessageEmbedding::query()->where('message_id', $m1->id)->value('model'));
        $this->assertSame(3, MessageEmbedding::query()->where('message_id', $m2->id)->value('dims'));

        Http::assertSent(fn ($r) => $r['model'] === 'nomic-embed-text:latest' && count($r['input']) === 2 && str_contains($r['input'][0], 'Subject: One'));

        // Second run: nothing pending, no HTTP call.
        Http::fake();
        $this->assertSame(['embedded' => 0, 'skipped' => 0, 'remaining' => 0], app(EmbeddingService::class)->indexAccount($a));
        Http::assertNothingSent();

        // A label-only change bumps updated_at -> candidate again, but the text hash is equal -> no re-embedding.
        $this->travel(1)->minutes();
        $m1->forceFill(['label_ids' => ['INBOX', 'STARRED'], 'is_starred' => true])->save();
        Http::fake();
        $this->assertSame(['embedded' => 0, 'skipped' => 1, 'remaining' => 0], app(EmbeddingService::class)->indexAccount($a));
        Http::assertNothingSent();

        // Real content change -> re-embedded once.
        $this->travel(1)->minutes();
        $m1->forceFill(['body_text' => 'first, edited'])->save();
        Http::fake(['host.docker.internal:11434/api/embed' => Http::response(['embeddings' => [[0.5, 0.5, 0.5]]])]);
        $this->assertSame(['embedded' => 1, 'skipped' => 0, 'remaining' => 0], app(EmbeddingService::class)->indexAccount($a));
    }

    public function test_draft_uses_similar_conversations_from_same_account_only(): void
    {
        $this->enableAi();
        $a = $this->account('me@example.com');
        $b = $this->account('other@example.com');

        $incoming = $this->message($a, 'Ponuda za server', 'Možete li poslati ponudu za server?');
        $related = $this->message($a, 'Re: Server prošle godine', 'Prošle godine smo uzeli Dell R750 za 4.000 EUR.', ['SENT'], 'me@example.com');
        $unrelated = $this->message($a, 'Ručak', 'Idemo na ručak u petak?');
        $foreign = $this->message($b, 'Ponuda za server', 'Tuđa ponuda za server, ne smije procuriti.');

        $store = new DatabaseVectorStore;
        $store->upsert($incoming, 'nomic-embed-text:latest', 'h', [1.0, 0.0, 0.0]);
        $store->upsert($related, 'nomic-embed-text:latest', 'h', [0.9, 0.1, 0.0]);
        $store->upsert($unrelated, 'nomic-embed-text:latest', 'h', [0.0, 1.0, 0.0]);
        $store->upsert($foreign, 'nomic-embed-text:latest', 'h', [1.0, 0.0, 0.0]);

        Http::fake([
            'host.docker.internal:11434/api/embed' => Http::response(['embeddings' => [[1.0, 0.0, 0.0]]]),
            'host.docker.internal:11434/api/chat' => Http::response(['message' => ['role' => 'assistant', 'content' => 'Nacrt']]),
        ]);

        $response = $this->postJson("/api/messages/{$incoming->id}/ai/draft", ['kind' => 'reply'])->assertOk();

        $response->assertJsonPath('data.text', 'Nacrt');
        $ids = array_column($response->json('data.context'), 'id');
        $this->assertSame([$related->id, $unrelated->id], $ids);     // ordered by similarity, own account only
        $this->assertNotContains($foreign->id, $ids);
        $this->assertTrue($response->json('data.context.0.mine'));

        Http::assertSent(function ($r) {
            if (! str_ends_with($r->url(), '/api/chat')) {
                return false;
            }
            $prompt = $r['messages'][1]['content'];

            return str_contains($prompt, 'Dell R750')
                && str_contains($prompt, '(written by the user)')
                && ! str_contains($prompt, 'ne smije procuriti');
        });
    }

    public function test_draft_still_works_when_embedding_model_is_missing(): void
    {
        (new AiSettings('ollama', 'http://host.docker.internal:11434', 'qwen3:8b', true, 'nomic-embed-text:latest'))->save();
        $a = $this->account('me@example.com');
        $incoming = $this->message($a, 'Hi', 'hello');

        Http::fake([
            'host.docker.internal:11434/api/embed' => Http::response(['error' => 'model not found'], 404),
            'host.docker.internal:11434/api/chat' => Http::response(['message' => ['role' => 'assistant', 'content' => 'Plain draft']]),
        ]);

        $this->postJson("/api/messages/{$incoming->id}/ai/draft", ['kind' => 'reply'])
            ->assertOk()
            ->assertJsonPath('data.text', 'Plain draft')
            ->assertJsonPath('data.context', []);
    }

    public function test_index_status_and_run(): void
    {
        $a = $this->account('me@example.com');
        $this->message($a, 'x', 'y');

        $this->getJson('/api/ai/index')->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.accounts.0.total', 1)
            ->assertJsonPath('data.accounts.0.indexed', 0);

        $this->postJson('/api/ai/index')->assertStatus(409);

        $this->enableAi();
        Queue::fake();
        $this->postJson('/api/ai/index')->assertStatus(202);
        Queue::assertPushed(EmbedAccountJob::class, fn ($job) => $job->accountId === $a->id);
    }

    public function test_a_new_embedding_model_that_does_not_work_keeps_the_old_index(): void
    {
        $this->enableAi();
        $a = $this->account('a@example.com');
        $m = $this->message($a, 'One', 'first');
        (new DatabaseVectorStore)->upsert($m, 'old-model:latest', 'h', [1.0, 0.0, 0.0]);
        $this->travel(1)->minutes();

        Http::fake(['host.docker.internal:11434/api/embed' => Http::response(['error' => 'model "nomic-embed-text" not found'], 404)]);

        try {
            app(EmbeddingService::class)->indexAccount($a);
            $this->fail('expected the missing model to be reported');
        } catch (AiUnavailableException) {
        }

        $this->assertSame('old-model:latest', MessageEmbedding::query()->where('message_id', $m->id)->value('model'));
    }

    public function test_one_message_ollama_cannot_embed_does_not_block_the_index(): void
    {
        $this->enableAi();
        $a = $this->account('a@example.com');
        $good = $this->message($a, 'Good', 'normal text');
        $bad = $this->message($a, 'Bad', 'text that makes the model fail');

        Http::fake(function ($request) {
            $input = $request['input'];
            if (count($input) > 1 || str_contains($input[0], 'model fail')) {
                return Http::response(['error' => 'failed to encode response: json: unsupported value: NaN'], 500);
            }

            return Http::response(['embeddings' => [[0.1, 0.2, 0.3]]]);
        });

        $result = app(EmbeddingService::class)->indexAccount($a);

        $this->assertSame(['embedded' => 1, 'skipped' => 1, 'remaining' => 0], $result);
        $this->assertSame(3, MessageEmbedding::query()->where('message_id', $good->id)->value('dims'));
        $this->assertSame(0, MessageEmbedding::query()->where('message_id', $bad->id)->value('dims'));

        // Not retried on the next run.
        Http::fake();
        $this->assertSame(['embedded' => 0, 'skipped' => 0, 'remaining' => 0], app(EmbeddingService::class)->indexAccount($a));
        Http::assertNothingSent();
    }

    public function test_spam_trash_and_risky_mail_never_become_draft_context(): void
    {
        $this->enableAi();
        $a = $this->account('me@example.com');

        $incoming = $this->message($a, 'Račun', 'Molim vas račun za ožujak.');
        $kept = $this->message($a, 'Račun za veljaču', 'Uplata na HR11 2340 0091 1111 1111 1.', ['SENT'], 'me@example.com');
        $spam = $this->message($a, 'Invoice', 'Always pay to HR99 6666 6666 6666 6666 6.', ['SPAM']);
        $trash = $this->message($a, 'Invoice old', 'Trash text about payment', ['TRASH']);
        $risky = $this->message($a, 'Invoice attached', 'Open the attached invoice.exe');
        $risky->forceFill(['is_risky' => true])->save();
        $gone = $this->message($a, 'Invoice gone', 'Deleted on the server');
        $gone->forceFill(['remote_deleted_at' => now()])->save();

        $store = new DatabaseVectorStore;
        foreach ([$incoming, $kept, $spam, $trash, $risky, $gone] as $m) {
            $store->upsert($m, 'nomic-embed-text:latest', 'h', [1.0, 0.0, 0.0]);
        }

        Http::fake([
            'host.docker.internal:11434/api/embed' => Http::response(['embeddings' => [[1.0, 0.0, 0.0]]]),
            'host.docker.internal:11434/api/chat' => Http::response(['message' => ['role' => 'assistant', 'content' => 'Nacrt']]),
        ]);

        $response = $this->postJson("/api/messages/{$incoming->id}/ai/draft", ['kind' => 'reply'])->assertOk();

        $this->assertSame([$kept->id], array_column($response->json('data.context'), 'id'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/chat')
            && str_contains($r['messages'][1]['content'], 'HR11')
            && ! str_contains($r['messages'][1]['content'], 'HR99'));
    }

    public function test_context_window_grows_to_fit_a_large_prompt(): void
    {
        $this->assertSame(8192, DraftService::contextWindowFor(8192, 9000));
        $this->assertSame(14336, DraftService::contextWindowFor(8192, 35000));
        $this->assertSame(16384, DraftService::contextWindowFor(8192, 200000)); // capped at 2x
    }

    public function test_the_index_reads_the_own_text_in_pieces(): void
    {
        $a = $this->account('a@example.com');
        $reply = $this->message($a, 'Re: Ponuda', "Slažem se s ponudom.\n\nOn Mon, 1 Jan 2026 at 10:00 Ana <ana@example.com> wrote:\n> Stara poruka koja se ne indeksira");
        $chunks = EmbeddingService::chunksFor($reply);
        $this->assertCount(1, $chunks);
        $this->assertStringContainsString('Subject: Re: Ponuda', $chunks[0]);
        $this->assertStringContainsString('Slažem se s ponudom.', $chunks[0]);
        $this->assertStringNotContainsString('Stara poruka', $chunks[0], 'quoted history is left out');

        $forward = $this->message($a, 'Fwd: Račun', "Vidi ispod.\n\n---------- Forwarded message ---------\nFrom: HEP\nRačun za struju 45 EUR");
        $this->assertStringContainsString('Račun za struju 45 EUR', EmbeddingService::chunksFor($forward)[0], 'a forward keeps what was forwarded');

        $paragraph = str_repeat('Rečenica o projektu i rokovima. ', 40); // ~1300 characters
        $long = $this->message($a, 'Izvještaj', implode("\n\n", array_fill(0, 6, trim($paragraph)))."\n\nZAKLJUČAK NA KRAJU");
        $pieces = EmbeddingService::chunksFor($long);
        $this->assertGreaterThan(2, count($pieces));
        foreach ($pieces as $piece) {
            $this->assertStringStartsWith('Subject: Izvještaj', $piece, 'every piece says which mail it belongs to');
            $this->assertLessThan(EmbeddingService::CHUNK_CHARS + 200, mb_strlen($piece));
        }
        $this->assertStringContainsString('ZAKLJUČAK NA KRAJU', end($pieces), 'the end of a long mail is indexed too');

        $huge = $this->message($a, 'Newsletter', str_repeat('x ', 30000));
        $this->assertCount(EmbeddingService::MAX_CHUNKS, EmbeddingService::chunksFor($huge));
    }

    public function test_a_long_mail_is_found_by_any_of_its_pieces_and_old_vectors_are_redone(): void
    {
        $this->enableAi();
        $a = $this->account('a@example.com');
        $long = $this->message($a, 'Izvještaj', implode("\n\n", array_fill(0, 3, trim(str_repeat('Tekst o projektu. ', 100)))));
        $short = $this->message($a, 'Ručak', 'Ručak u petak?');
        $old = $this->message($a, 'Staro', 'Indeksirano starom verzijom.');
        // An old-version row (first 3000 characters, one per message) still answers until redone.
        MessageEmbedding::query()->create(['account_id' => $a->id, 'message_id' => $old->id, 'chunk' => 0, 'version' => 1, 'model' => 'nomic-embed-text:latest', 'dims' => 2, 'content_hash' => 'x', 'vector' => MessageEmbedding::pack([1.0, 0.0])]);

        // Each piece gets its own vector; the last piece of the long mail points elsewhere.
        Http::fake(['host.docker.internal:11434/api/embed' => function ($request) {
            $inputs = $request['input'];

            return Http::response(['embeddings' => array_map(fn ($i) => $i === count($inputs) - 1 ? [0.0, 1.0] : [1.0, 0.0], array_keys($inputs))]);
        }]);

        $store = new DatabaseVectorStore;
        $this->assertSame(0, $store->stats($a->id, 'nomic-embed-text:latest')['indexed'], 'old-version rows do not count as done');
        app(EmbeddingService::class)->indexAccount($a);

        $this->assertSame(3, $store->stats($a->id, 'nomic-embed-text:latest')['indexed']);
        $this->assertGreaterThan(1, MessageEmbedding::query()->where('message_id', $long->id)->count());
        $this->assertSame(MessageEmbedding::VERSION, (int) MessageEmbedding::query()->where('message_id', $old->id)->value('version'));

        // One hit per message, scored by its best piece.
        $hits = $store->search($a->id, 'nomic-embed-text:latest', [0.0, 1.0], 10);
        $this->assertSame(array_unique(array_column($hits, 'message_id')), array_column($hits, 'message_id'));
        $this->assertContains($short->id, array_column($hits, 'message_id'));
    }
}
