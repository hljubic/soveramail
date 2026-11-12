<?php

namespace Tests\Feature\Ai;

use App\Jobs\BuildKnowledgeJob;
use App\Models\Account;
use App\Models\Category;
use App\Models\Contact;
use App\Models\KnowledgeItem;
use App\Models\Message;
use App\Models\ThreadCategory;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Knowledge\KnowledgeBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class KnowledgeTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private Account $account;

    private Category $studenti;

    protected function setUp(): void
    {
        parent::setUp();
        (new AiSettings('ollama', 'http://host.docker.internal:11434', 'gemma4:31b-it-qat', true))->save();
        $this->account = $this->makeAccount(['display_name' => 'Test Profesor']);
        $this->studenti = Category::query()->create(['name' => 'Studenti', 'description' => 'Nastava', 'color' => '#d97706', 'position' => 1]);
    }

    private function mail(string $thread, bool $mine, string $subject, string $body, string $other = 'ana@student.example', array $extra = []): Message
    {
        $n = ++$this->seq;

        return Message::query()->forceCreate(array_merge([
            'account_id' => $this->account->id, 'provider_message_id' => "m{$n}", 'thread_id' => $thread,
            'subject' => $subject, 'from_name' => $mine ? 'Test Profesor' : 'Ana Anić', 'from_email' => $mine ? $this->account->email : $other,
            'to_recipients' => [['name' => $mine ? 'Ana Anić' : 'Test Profesor', 'email' => $mine ? $other : $this->account->email]],
            'cc_recipients' => [], 'bcc_recipients' => [], 'reply_to' => [], 'headers' => [],
            'body_text' => $body, 'snippet' => mb_substr($body, 0, 40),
            'label_ids' => $mine ? ['SENT'] : ['INBOX'], 'is_unread' => false,
            'sent_at' => now()->subMinutes(500 - $n), 'received_at' => now()->subMinutes(500 - $n),
        ], $extra));
    }

    /** Four student threads: question, then the professor's answer. */
    private function seedStudentMail(): void
    {
        foreach (range(1, 4) as $i) {
            $this->mail("s{$i}", false, "Rok ispita {$i}", 'Poštovani profesore, kada je ispit iz Baza podataka?');
            $this->mail("s{$i}", true, "Re: Rok ispita {$i}", 'Poštovana, ispit je 20. 1. u 10 sati u dvorani 12. Lp, Test');
            ThreadCategory::query()->create(['account_id' => $this->account->id, 'thread_id' => "s{$i}", 'category_id' => $this->studenti->id, 'source' => 'ai']);
        }
        Contact::query()->create(['account_id' => $this->account->id, 'email' => 'ana@student.example', 'name' => 'Ana Anić', 'sent_count' => 4, 'seen_count' => 4]);
    }

    private function fakeModel(callable $answer): void
    {
        Http::fake([
            'host.docker.internal:11434/api/show' => Http::response(['capabilities' => ['completion']]),
            'host.docker.internal:11434/api/chat' => fn (Request $r) => Http::response(['message' => ['role' => 'assistant', 'content' => $answer($r)]]),
            'host.docker.internal:11434/api/generate' => Http::response(['done' => true]),
        ]);
    }

    public function test_builds_about_area_guide_recurring_answers_and_contact_cards_from_the_right_mail(): void
    {
        $this->seedStudentMail();
        $this->fakeModel(fn (Request $r) => match (true) {
            str_contains($r['messages'][0]['content'], 'factual profile') => '- ROLE: profesor',
            str_contains($r['messages'][0]['content'], 'one area of their work') => '- REGISTER: "Poštovana"',
            str_contains($r['messages'][0]['content'], 'AGAIN AND AGAIN') => '- Q: Kada je ispit? → A: "ispit je 20. 1. u 10 sati"',
            default => '- WHO: studentica',
        });

        $result = app(KnowledgeBuilder::class)->buildPending($this->account);

        $this->assertSame(['built' => 4, 'remaining' => 0], $result);
        $items = KnowledgeItem::query()->get()->keyBy(fn ($i) => $i->kind.'|'.$i->subject);
        $this->assertSame('- ROLE: profesor', $items['about|']->content);
        $this->assertStringContainsString('Poštovana', $items['audience|'.$this->studenti->id]->content);
        $this->assertStringContainsString('Q: Kada je ispit?', $items['faq|'.$this->studenti->id]->content);
        $this->assertSame('Ana Anić', $items['contact|ana@student.example']->title);

        // The model got the right material, wrote in the user's language and was told to leave secrets out.
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/chat') && str_contains($r['messages'][0]['content'], 'factual profile')
            && str_contains($r['messages'][1]['content'], 'Re: Rok ispita')
            && str_contains($r['messages'][0]['content'], 'Never write down passwords'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/chat') && str_contains($r['messages'][0]['content'], 'AGAIN AND AGAIN')
            && str_contains($r['messages'][1]['content'], 'RECEIVED from Ana Anić')
            && str_contains($r['messages'][1]['content'], 'THE USER REPLIED:'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/chat') && str_contains($r['messages'][0]['content'], 'short card')
            && str_contains($r['messages'][1]['content'], 'They wrote to the user')
            && str_contains($r['messages'][1]['content'], 'The user wrote to them'));
    }

    public function test_nothing_recurring_is_stored_empty_and_not_asked_again(): void
    {
        $this->seedStudentMail();
        $this->fakeModel(fn () => 'NONE');

        app(KnowledgeBuilder::class)->buildPending($this->account);
        $this->assertSame('', KnowledgeItem::query()->where('kind', 'faq')->value('content'));
        $this->assertSame(0, count(app(KnowledgeBuilder::class)->pendingTasks($this->account)));
    }

    public function test_hand_edits_survive_rebuilds_and_drafts_use_the_knowledge(): void
    {
        $this->seedStudentMail();
        $this->fakeModel(fn () => '- from the model');
        app(KnowledgeBuilder::class)->buildPending($this->account);
        $about = KnowledgeItem::query()->where('kind', 'about')->first();

        $this->putJson("/api/ai/knowledge/{$about->id}", ['content' => '- ROLE: izv. prof. na PRIMJER'])->assertOk()->assertJsonPath('data.source', 'user');

        Queue::fake();
        $this->postJson("/api/accounts/{$this->account->id}/ai/knowledge/rebuild", ['all' => true])->assertStatus(202);
        Queue::assertPushed(BuildKnowledgeJob::class);
        $this->assertSame(['about'], KnowledgeItem::query()->pluck('kind')->all(), 'only the hand-edited item is left');

        // Rebuild the rest, then draft a reply to Ana in a "Studenti" thread.
        $this->fakeModel(fn (Request $r) => str_contains($r['messages'][0]['content'], 'short card') ? '- ADDRESS: "Poštovana"' : '- built again');
        app(KnowledgeBuilder::class)->buildPending($this->account);
        $this->assertSame('- ROLE: izv. prof. na PRIMJER', $about->fresh()->content);

        $question = Message::query()->where('thread_id', 's1')->where('from_email', 'ana@student.example')->first();
        $this->fakeModel(fn () => 'Poštovana, ispit je 20. 1.');
        $this->postJson("/api/messages/{$question->id}/ai/draft", ['kind' => 'reply'])->assertOk();

        Http::assertSent(function (Request $r) {
            $user = $r['messages'][1]['content'] ?? '';

            return str_ends_with($r->url(), '/api/chat')
                && str_contains($user, 'What the user is and knows')
                && str_contains($user, '- ROLE: izv. prof. na PRIMJER')
                && str_contains($user, 'How the user writes in "Studenti"')
                && str_contains($user, 'Questions that keep coming in "Studenti"')
                && str_contains($user, 'About Ana Anić <ana@student.example> (who you are writing to)');
        });
    }

    public function test_listing_shows_progress_and_reset_lets_the_model_write_it_again(): void
    {
        $this->seedStudentMail();
        $this->getJson("/api/accounts/{$this->account->id}/ai/knowledge")->assertOk()
            ->assertJsonPath('meta.progress.total', 4)->assertJsonPath('meta.progress.done', 0);

        $this->fakeModel(fn () => '- x');
        app(KnowledgeBuilder::class)->buildPending($this->account);
        $item = KnowledgeItem::query()->where('kind', 'contact')->first();

        Queue::fake();
        $this->postJson("/api/ai/knowledge/{$item->id}/reset")->assertStatus(202);
        $this->assertNull($item->fresh());
        $this->getJson("/api/accounts/{$this->account->id}/ai/knowledge")->assertJsonPath('meta.progress.done', 3);
    }
}
