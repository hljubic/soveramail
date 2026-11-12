<?php

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\Attachment;
use App\Models\Message;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Retrieval\DatabaseVectorStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SmartSearchTest extends TestCase
{
    use RefreshDatabase;

    private function enableAi(?string $embeddingModel = 'nomic-embed-text:latest'): void
    {
        (new AiSettings('ollama', 'http://host.docker.internal:11434', 'qwen3:8b', true, $embeddingModel))->save();
    }

    private function message(Account $account, string $subject, string $body, array $attachments = [], array $extra = []): Message
    {
        static $n = 0;
        $n++;
        $message = Message::query()->create(array_merge([
            'account_id' => $account->id, 'provider_message_id' => "gm-{$n}", 'thread_id' => "thr-{$n}",
            'subject' => $subject, 'from_name' => 'Ana', 'from_email' => 'ana@example.com',
            'to_recipients' => [['name' => 'Me', 'email' => $account->email]], 'cc_recipients' => [], 'bcc_recipients' => [], 'reply_to' => [],
            'snippet' => mb_substr($body, 0, 50), 'body_text' => $body, 'body_html' => null, 'headers' => [],
            'label_ids' => ['INBOX'], 'is_unread' => false, 'is_starred' => false, 'has_attachments' => $attachments !== [],
            'size_estimate' => 1, 'sent_at' => now()->subMinutes($n), 'received_at' => now()->subMinutes($n),
        ], $extra));
        foreach ($attachments as $filename => $mime) {
            Attachment::query()->create(['message_id' => $message->id, 'filename' => $filename, 'mime_type' => $mime, 'size' => 10, 'is_inline' => false]);
        }

        return $message;
    }

    private function llm(array $interpretation): array
    {
        return ['host.docker.internal:11434/api/chat' => Http::response(['message' => ['role' => 'assistant', 'content' => json_encode($interpretation)]])];
    }

    public function test_requires_configured_ai(): void
    {
        $account = $this->makeAccount();

        $this->postJson("/api/accounts/{$account->id}/ai/search", ['q' => 'pdf o ispitima'])
            ->assertStatus(503)
            ->assertJsonPath('ai_unavailable', true);
    }

    public function test_filters_by_attachment_kind_and_ranks_by_similarity_within_the_account(): void
    {
        $this->enableAi();
        $a = $this->makeAccount(['email' => 'me@example.com']);
        $b = $this->makeAccount(['email' => 'other@example.com']);

        $syllabusBoth = $this->message($a, 'Silabus kolegija PZI', 'U privitku je silabus.', ['silabus.pdf' => 'application/pdf', 'silabus.docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']);
        $syllabusPdfOnly = $this->message($a, 'Silabus – samo PDF', 'Silabus u pdf-u.', ['silabus.pdf' => 'application/pdf']);
        $lunchBoth = $this->message($a, 'Ručak', 'Idemo na ručak?', ['menu.pdf' => 'application/pdf', 'menu.docx' => 'application/msword']);
        $syllabusNoFiles = $this->message($a, 'Silabus bez privitka', 'Silabus dolazi sutra.');
        $foreign = $this->message($b, 'Silabus kolegija', 'Tuđi silabus.', ['s.pdf' => 'application/pdf', 's.docx' => 'application/msword']);

        $store = new DatabaseVectorStore;
        $store->upsert($syllabusBoth, 'nomic-embed-text:latest', 'h', [1.0, 0.0, 0.0]);
        $store->upsert($syllabusPdfOnly, 'nomic-embed-text:latest', 'h', [0.95, 0.05, 0.0]);
        $store->upsert($lunchBoth, 'nomic-embed-text:latest', 'h', [0.0, 1.0, 0.0]);
        $store->upsert($syllabusNoFiles, 'nomic-embed-text:latest', 'h', [0.9, 0.1, 0.0]);
        $store->upsert($foreign, 'nomic-embed-text:latest', 'h', [1.0, 0.0, 0.0]);

        Http::fake([
            'host.docker.internal:11434/api/show' => Http::response(['capabilities' => ['completion']]),
            'host.docker.internal:11434/api/embed' => Http::response(['embeddings' => [[1.0, 0.0, 0.0]]]),
        ] + $this->llm([
            'topic' => 'silabusi kolegija', 'attachment_types' => ['word', 'pdf'], 'attachments_mode' => 'all',
            'has_attachments' => null, 'from' => null, 'to' => null, 'date_from' => null, 'date_to' => null,
            'unread' => null, 'starred' => null, 'folder' => 'all',
        ]));

        $res = $this->postJson("/api/accounts/{$a->id}/ai/search", ['q' => 'svi mailovi koji imaju word i pdf dokument a odnose se na silabuse kolegija'])
            ->assertOk()
            ->assertJsonPath('meta.ranking', 'semantic')
            ->assertJsonPath('meta.parsed', true)
            ->assertJsonPath('meta.filters.0', 'About: silabusi kolegija')
            ->assertJsonPath('meta.filters.1', 'Attachments: Word + PDF');

        // Only the mail with BOTH a Word and a PDF file that is about syllabi; the lunch mail has
        // both files but a far-away vector, and the foreign account never leaks.
        $ids = array_column($res->json('data'), 'id');
        $this->assertSame([$syllabusBoth->id], $ids);
        $this->assertSame(1.0, (float) $res->json('data.0.score'));

        // The structured filter went to the model with the schema.
        Http::assertSent(fn ($req) => str_contains($req->url(), '/api/chat') && isset($req['format']['properties']['attachment_types']));
    }

    public function test_any_mode_matches_either_kind_and_metadata_only_query_orders_newest_first(): void
    {
        $this->enableAi();
        $a = $this->makeAccount();

        $older = $this->message($a, 'Stari PDF', 'x', ['a.pdf' => 'application/pdf'], ['received_at' => now()->subDays(5)]);
        $newer = $this->message($a, 'Novi Word', 'x', ['b.docx' => 'application/octet-stream'], ['received_at' => now()->subDay()]);
        $this->message($a, 'Slika', 'x', ['c.png' => 'image/png']);
        $this->message($a, 'Bez privitka', 'x');

        Http::fake([
            'host.docker.internal:11434/api/show' => Http::response(['capabilities' => ['completion']]),
        ] + $this->llm([
            'topic' => null, 'attachment_types' => ['pdf', 'word'], 'attachments_mode' => 'any',
            'has_attachments' => null, 'from' => 'ana', 'to' => null, 'date_from' => null, 'date_to' => null,
            'unread' => null, 'starred' => null, 'folder' => 'inbox',
        ]));

        $res = $this->postJson("/api/accounts/{$a->id}/ai/search", ['q' => 'pdf ili word od Ane u inboxu'])
            ->assertOk()
            ->assertJsonPath('meta.ranking', 'recent')
            ->assertJsonPath('meta.total', 2);

        // Extension fallback catches the .docx served with a generic mime type.
        $this->assertSame([$newer->id, $older->id], array_column($res->json('data'), 'id'));
        $this->assertContains('Attachments: PDF or Word', $res->json('meta.filters'));
        $this->assertContains('From: ana', $res->json('meta.filters'));
        $this->assertContains('Folder: Inbox', $res->json('meta.filters'));
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/api/embed'));
    }

    public function test_reasoning_model_reads_the_query_without_thinking_when_switched_off(): void
    {
        (new AiSettings('ollama', 'http://host.docker.internal:11434', 'gemma4:31b-it-qat', true))->with(['thinking' => false])->save();
        $a = $this->makeAccount();
        $this->message($a, 'Stari PDF', 'x', ['a.pdf' => 'application/pdf']);

        Http::fake([
            'host.docker.internal:11434/api/show' => Http::response(['capabilities' => ['completion', 'thinking']]),
        ] + $this->llm([
            'topic' => null, 'attachment_types' => ['pdf'], 'attachments_mode' => 'any',
            'has_attachments' => null, 'from' => null, 'to' => null, 'date_from' => null, 'date_to' => null,
            'unread' => null, 'starred' => null, 'folder' => null,
        ]));

        $this->postJson("/api/accounts/{$a->id}/ai/search", ['q' => 'pdf-ovi'])->assertOk()->assertJsonPath('meta.total', 1);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/chat') && $r['think'] === false);
    }

    public function test_garbage_from_the_model_falls_back_to_full_text_on_the_sentence(): void
    {
        $this->enableAi(embeddingModel: null);
        $a = $this->makeAccount();

        $hit = $this->message($a, 'Termin ispita', 'Ispit je u petak.');
        $this->message($a, 'Ručak', 'Idemo na ručak?');

        Http::fake([
            'host.docker.internal:11434/api/show' => Http::response(['capabilities' => ['completion']]),
            'host.docker.internal:11434/api/chat' => Http::response(['message' => ['role' => 'assistant', 'content' => 'sorry, no json here']]),
        ]);

        $res = $this->postJson("/api/accounts/{$a->id}/ai/search", ['q' => 'Termin ispita'])
            ->assertOk()
            ->assertJsonPath('meta.parsed', false)
            ->assertJsonPath('meta.ranking', 'fulltext')
            ->assertJsonPath('meta.interpretation.topic', 'Termin ispita');

        $this->assertSame([$hit->id], array_column($res->json('data'), 'id'));
    }

    public function test_unsafe_values_from_the_model_are_ignored(): void
    {
        $this->enableAi(embeddingModel: null);
        $a = $this->makeAccount();
        $this->message($a, 'Nešto', 'x', [], ['is_starred' => true]);

        Http::fake([
            'host.docker.internal:11434/api/show' => Http::response(['capabilities' => ['completion']]),
        ] + $this->llm([
            'topic' => null, 'attachment_types' => ['exe', 'pdf; drop table messages'], 'attachments_mode' => 'sometimes',
            'has_attachments' => 'yes', 'from' => str_repeat('a', 500), 'to' => null, 'date_from' => 'next tuesday', 'date_to' => '2026-13-45',
            'unread' => 'maybe', 'starred' => true, 'folder' => '../etc',
        ]));

        $res = $this->postJson("/api/accounts/{$a->id}/ai/search", ['q' => 'zvjezdicom označeni'])->assertOk();

        $i = $res->json('meta.interpretation');
        $this->assertSame([], $i['attachment_types']);
        $this->assertSame('any', $i['attachments_mode']);
        $this->assertNull($i['has_attachments']);
        $this->assertSame(100, mb_strlen($i['from']));
        $this->assertNull($i['date_from']);
        $this->assertNull($i['date_to']);
        $this->assertNull($i['unread']);
        $this->assertTrue($i['starred']);
        $this->assertSame('all', $i['folder']);
    }
}
