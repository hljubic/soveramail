<?php

namespace Tests\Feature\Ai;

use App\Models\Account;
use App\Models\Message;
use App\Services\Ai\AiSettings;
use App\Services\Ai\DraftService;
use App\Services\Ai\Language\LanguageDetector;
use App\Services\Ai\Language\Languages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReplyLanguageTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private const HR = 'Poštovani, u privitku šaljem raspored ispita za sljedeći tjedan. Molim Vas da provjerite termine i javite mi ako nešto ne odgovara.';

    private function sent(Account $a, string $body, string $label = 'SENT'): Message
    {
        $n = ++$this->seq;

        return Message::query()->create([
            'account_id' => $a->id, 'provider_message_id' => "gm-{$n}", 'thread_id' => "thr-{$n}", 'subject' => "S{$n}",
            'from_name' => $label === 'SENT' ? 'Me' : 'Ana', 'from_email' => $label === 'SENT' ? $a->email : 'ana@example.com',
            'to_recipients' => [['name' => null, 'email' => 'x@example.com']], 'cc_recipients' => [], 'bcc_recipients' => [], 'reply_to' => [],
            'snippet' => 'x', 'body_text' => $body." ({$n})", 'headers' => [], 'label_ids' => [$label],
            'received_at' => now()->subMinutes($n), 'sent_at' => now()->subMinutes($n),
        ]);
    }

    private function enableAi(): void
    {
        (new AiSettings('ollama', 'http://host.docker.internal:11434', 'qwen3:8b', true))->save();
    }

    private function model(array $answer): array
    {
        return [
            'host.docker.internal:11434/api/show' => Http::response(['capabilities' => ['completion']]),
            'host.docker.internal:11434/api/chat' => Http::response(['message' => ['role' => 'assistant', 'content' => json_encode($answer)]]),
        ];
    }

    public function test_nothing_is_detected_without_a_configured_model_or_enough_sent_mail(): void
    {
        $a = $this->makeAccount();
        $d = app(LanguageDetector::class);
        for ($i = 0; $i < 40; $i++) {
            $this->sent($a, self::HR);
        }

        // no model configured -> not even attempted
        Http::fake();
        $this->assertNull($d->autoSet($a));
        $this->assertNull(AiSettings::load()->replyLanguage);
        Http::assertNothingSent();

        // model configured but too little sent mail -> not asked
        $this->enableAi();
        $b = $this->makeAccount(['email' => 'b@example.com']);
        for ($i = 0; $i < 10; $i++) {
            $this->sent($b, self::HR);
        }
        $this->assertFalse($d->detectForAccount($b)['ready']);
        $this->assertNull($d->autoSet($b));
        Http::assertNothingSent();
    }

    public function test_model_decides_once_and_never_overrides_the_user(): void
    {
        $this->enableAi();
        $a = $this->makeAccount();
        for ($i = 0; $i < 30; $i++) {
            $this->sent($a, self::HR);
        }
        Http::fake($this->model(['language' => 'hr', 'confidence' => 0.96, 'reason' => 'ijekavian Croatian vocabulary']));

        $this->assertSame('hr', app(LanguageDetector::class)->autoSet($a));
        $s = AiSettings::load();
        $this->assertSame('hr', $s->replyLanguage);
        $this->assertSame('auto', $s->replyLanguageSource);
        $this->assertNotNull($s->replyLanguageDetectedAt);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/api/chat')
            && isset($req['format']['properties']['language'])
            && str_contains($req['messages'][1]['content'], 'raspored ispita')
            && ! str_contains($req['messages'][1]['content'], 'wrote:'));

        // only the first time
        Http::fake($this->model(['language' => 'en', 'confidence' => 0.99, 'reason' => 'x']));
        $this->assertNull(app(LanguageDetector::class)->autoSet($a));
        $this->assertSame('hr', AiSettings::load()->replyLanguage);
        Http::assertNothingSent();

        // the user's choice wins for good
        $this->putJson('/api/ai/settings', ['reply_language' => 'en'])->assertOk()
            ->assertJsonPath('data.reply_language', 'en')->assertJsonPath('data.reply_language_source', 'user');
        $this->assertNull(app(LanguageDetector::class)->autoSet($a));
        $this->putJson('/api/ai/settings', ['reply_language' => null])->assertOk()->assertJsonPath('data.reply_language', null);
        $this->assertNull(app(LanguageDetector::class)->autoSet($a));
        $this->putJson('/api/ai/settings', ['reply_language' => 'xx'])->assertStatus(422);
        $this->assertContains('hr', array_column($this->getJson('/api/ai/settings')->json('data.languages'), 'code'));
    }

    public function test_unsure_model_sets_nothing_and_is_not_asked_again_right_away(): void
    {
        $this->enableAi();
        $a = $this->makeAccount();
        for ($i = 0; $i < 30; $i++) {
            $this->sent($a, self::HR);
        }

        // Http::fake() merges stubs, so one sequence answers every /api/chat call of this test.
        Http::fake([
            'host.docker.internal:11434/api/show' => Http::response(['capabilities' => ['completion']]),
            'host.docker.internal:11434/api/chat' => Http::sequence()
                ->push(['message' => ['role' => 'assistant', 'content' => json_encode(['language' => 'unknown', 'confidence' => 0.3, 'reason' => 'mixed'])]])
                ->push(['message' => ['role' => 'assistant', 'content' => json_encode(['language' => 'hr', 'confidence' => 0.5, 'reason' => 'maybe'])]])
                ->push(['message' => ['role' => 'assistant', 'content' => json_encode(['language' => 'hr', 'confidence' => 0.99, 'reason' => 'sure'])]]),
        ]);

        // 1st answer: unknown -> nothing set, but the attempt is remembered
        $this->assertNull(app(LanguageDetector::class)->autoSet($a));
        $s = AiSettings::load();
        $this->assertNull($s->replyLanguage);
        $this->assertNull($s->replyLanguageSource);
        $this->assertNotNull($s->replyLanguageCheckedAt);

        // 2nd answer: a guess below the confidence threshold is discarded too
        $this->assertNull(app(LanguageDetector::class)->detectForAccount($a)['code']);

        // within 24h the model is not asked again (the 3rd, confident answer stays unused)
        $this->assertNull(app(LanguageDetector::class)->autoSet($a));
        Http::assertSentCount(4); // 2 × (show + chat)

        // after a day it is asked again and this time it is sure
        $this->travel(25)->hours();
        $this->assertSame('hr', app(LanguageDetector::class)->autoSet($a));
        $this->assertSame('hr', AiSettings::load()->replyLanguage);
    }

    public function test_unreachable_ollama_sets_nothing_and_throws_nothing(): void
    {
        $this->enableAi();
        $a = $this->makeAccount();
        for ($i = 0; $i < 30; $i++) {
            $this->sent($a, self::HR);
        }
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $this->assertNull(app(LanguageDetector::class)->autoSet($a));
        $this->assertNull(AiSettings::load()->replyLanguage);
        $this->assertNull(AiSettings::load()->replyLanguageSource);
    }

    public function test_manual_detection_endpoint(): void
    {
        $this->enableAi();
        $a = $this->makeAccount();
        for ($i = 0; $i < 30; $i++) {
            $this->sent($a, self::HR);
        }
        Http::fake($this->model(['language' => 'hr', 'confidence' => 0.9, 'reason' => 'Croatian']));

        $this->postJson('/api/ai/language/detect', ['account_id' => $a->id])->assertOk()
            ->assertJsonPath('detection.ready', true)
            ->assertJsonPath('detection.code', 'hr')
            ->assertJsonPath('detection.name', 'Croatian')
            ->assertJsonPath('data.reply_language', 'hr')
            ->assertJsonPath('data.reply_language_source', 'auto');
    }

    public function test_prompt_demands_the_standard_literary_form_of_the_reply_language(): void
    {
        $withHr = DraftService::systemPrompt('Me <me@example.com>', 'reply', '', 'hr');
        $this->assertStringContainsString('Write in Croatian by default, in its STANDARD LITERARY form', $withHr);
        $this->assertStringContainsString('ijekavian, Latin script', $withHr);
        $this->assertStringContainsString('no dialect, no regionalisms, no slang', $withHr);

        $without = DraftService::systemPrompt('Me <me@example.com>', 'reply', '');
        $this->assertStringContainsString('Reply in the language of the e-mail you are answering', $without);
        $this->assertStringNotContainsString('STANDARD LITERARY', $without);

        (new AiSettings('ollama', 'http://host.docker.internal:11434', 'qwen3:8b', true, replyLanguage: 'de'))->save();
        $a = $this->makeAccount();
        $m = $this->sent($a, 'Hallo, passt Donnerstag?', 'INBOX');
        Http::fake([
            'host.docker.internal:11434/api/show' => Http::response(['capabilities' => ['completion']]),
            'host.docker.internal:11434/api/chat' => Http::response(['message' => ['role' => 'assistant', 'content' => 'Entwurf']]),
        ]);
        $this->postJson("/api/messages/{$m->id}/ai/draft", ['kind' => 'reply'])->assertOk();
        Http::assertSent(fn ($req) => str_contains($req->url(), '/api/chat') && str_contains($req['messages'][0]['content'], 'Write in German by default') && str_contains($req['messages'][0]['content'], Languages::standardForm('de')));
    }
}
