<?php

namespace Tests\Feature\Ai;

use App\Jobs\BuildStyleProfileJob;
use App\Models\Account;
use App\Models\Message;
use App\Models\StyleProfile;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Style\StyleAnalyzer;
use App\Services\Ai\Style\StyleProfileService;
use App\Services\Mail\OwnText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StyleProfileTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private const SIGNATURE = "Davor Horvat\nPrimjer d.o.o., Zagreb\n+385 1 000 0000";

    private function account(): Account
    {
        return $this->makeAccount(['display_name' => 'Davor Horvat', 'email' => 'davor.horvat@example.com']);
    }

    private function message(Account $a, string $body, array $attrs = []): Message
    {
        $n = ++$this->seq;

        return Message::query()->create(array_merge([
            'account_id' => $a->id, 'provider_message_id' => "gm-{$n}", 'thread_id' => "thr-{$n}", 'subject' => "Tema {$n}",
            'from_name' => 'Ana', 'from_email' => 'ana@example.com',
            'to_recipients' => [['name' => null, 'email' => $a->email]], 'cc_recipients' => [], 'bcc_recipients' => [], 'reply_to' => [],
            'snippet' => mb_substr($body, 0, 40), 'body_text' => $body, 'headers' => [], 'label_ids' => ['INBOX'],
            'received_at' => now()->subMinutes($n), 'sent_at' => now()->subMinutes($n),
        ], $attrs));
    }

    private function sent(Account $a, string $body, array $attrs = []): Message
    {
        return $this->message($a, $body, $attrs + [
            'from_name' => 'Davor Horvat', 'from_email' => $a->email, 'label_ids' => ['SENT'],
            'to_recipients' => [['name' => 'Ana', 'email' => 'ana@example.com']],
        ]);
    }

    /** A realistic mailbox: 8 formal mails with signature, 4 informal ones, some quoted history. */
    private function seedMailbox(Account $a): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->sent($a, "Poštovani,\n\nu privitku šaljem raspored ispita za kolegij {$i}. Molim Vas da provjerite termine i javite mi se ako nešto ne odgovara.\n\nLijep pozdrav,\nDavor\n".self::SIGNATURE."\n\nOn Mon, Ana wrote:\n> citirani tekst koji se ne smije brojati\n> molim molim molim");
        }
        for ($i = 0; $i < 4; $i++) {
            $this->sent($a, "Bok Ana,\n\nmože, vidimo se u utorak :) Javi ako ti ne paše.\n\nLP\nDavor");
        }
        // mail from someone else: baseline vocabulary + must not be analysed
        for ($i = 0; $i < 5; $i++) {
            $this->message($a, "Poštovani profesore,\n\nzanima me raspored ispita i molim Vas termin konzultacija.\n\nSrdačan pozdrav,\nAna");
        }
        // a draft in my name is not "sent"
        $this->sent($a, "Poštovani,\n\nnedovršeno", ['label_ids' => ['DRAFT']]);
    }

    public function test_analyzer_finds_greetings_closings_signature_language_and_phrases(): void
    {
        $a = $this->account();
        $this->seedMailbox($a);

        $service = app(StyleProfileService::class);
        $profile = $service->build($a, withSummary: false);
        $s = $profile->stats;

        $this->assertSame(12, $profile->sample_count);
        $this->assertSame(12, $s['mails']);

        $this->assertSame('Poštovani,', $s['greetings'][0]['text']);
        $this->assertSame('Bok {name},', $s['greetings'][1]['text']);
        $this->assertEqualsWithDelta(0.67, $s['greetings'][0]['share'], 0.01);

        $closings = array_column($s['closings'], 'text');
        $this->assertContains('Lijep pozdrav', $closings);
        $this->assertContains('LP', $closings);

        $this->assertSame(explode("\n", self::SIGNATURE), $s['signature']['lines']);
        $this->assertSame('Davor', $s['sign_name']['text']);
        $this->assertEquals(1.0, $s['sign_name']['share']);
        $this->assertEqualsWithDelta(0.67, $s['signature']['share'], 0.01);

        $this->assertGreaterThanOrEqual(0.9, $s['language']['hr']);
        $this->assertGreaterThanOrEqual(0.6, $s['formality']['formal']);

        $phrases = array_column($s['phrases'], 'text');
        $this->assertContains('u privitku', $phrases);
        // quoted history ("molim molim molim") was stripped: "molim" is a stopword anyway, but "citirani" must not appear
        $this->assertNotContains('citirani', array_column($s['frequent_words'], 'word'));
        $this->assertNotContains('davor', array_column($s['frequent_words'], 'word'));

        // "raspored"/"ispita" appear in Ana's mail too -> not distinctive; "privitku"/"kolegij"/"provjerite" are
        $distinctive = array_column($s['distinctive_words'], 'word');
        $this->assertContains('privitku', $distinctive);
        $this->assertNotContains('raspored', $distinctive);

        $this->assertGreaterThan(0, $s['punctuation']['smiley_share']);
        $this->assertNotEmpty($s['sample_ids']);

        // concrete knowledge: openers, requests, register-specific greetings, orthography, domains
        $this->assertSame('u privitku šaljem', $s['openers'][0]['text']);
        $this->assertSame(8, $s['openers'][0]['count']);
        $this->assertContains('Molim Vas da provjerite termine i javite mi se ako nešto ne odgovara.', $s['requests']);
        $this->assertSame('Poštovani,', $s['by_register']['formal']['greetings'][0]['text']);
        $this->assertSame('Bok {name},', $s['by_register']['informal']['greetings'][0]['text']);
        $this->assertSame('LP', $s['by_register']['informal']['closings'][0]['text']);
        $this->assertEquals(0.0, $s['orthography']['ascii_croatian_share']);
        $this->assertEquals(1.0, $s['orthography']['lowercase_after_greeting_share']);
        $this->assertEquals(1.0, $s['orthography']['comma_after_greeting_share']);
        $this->assertSame('example.com', $s['language_by_domain'][0]['domain']);
        $this->assertSame('hr', $s['language_by_domain'][0]['language']);
        $this->assertNotEmpty($s['example_sentences']);

        $facts = StyleProfileService::describe($s, 6000);
        $this->assertStringContainsString('By register: formal contacts get greeting "Poštovani,"', $facts);
        $this->assertStringContainsString('First sentence usually starts with: "u privitku šaljem" (8×)', $facts);
        $this->assertStringContainsString('How the user asks for things (verbatim): "Molim Vas', $facts);
        $this->assertStringContainsString('continues in lower case after the greeting line (100%)', $facts);
    }

    public function test_prompt_block_and_draft_use_the_profile_and_can_be_switched_off(): void
    {
        (new AiSettings('ollama', 'http://host.docker.internal:11434', 'qwen3:8b', true, null))->save();
        $a = $this->account();
        $this->seedMailbox($a);
        app(StyleProfileService::class)->build($a, withSummary: false);
        StyleProfile::query()->where('account_id', $a->id)->update(['summary' => '- Kratko i formalno, uvijek "Lijep pozdrav".']);

        $block = app(StyleProfileService::class)->promptBlock($a, 2500);
        $this->assertStringContainsString('profile learned from 12 e-mails', $block);
        $this->assertStringContainsString('Kratko i formalno', $block);
        $this->assertStringContainsString('"Poštovani," (67%)', $block);
        $this->assertStringContainsString('Signature block', $block);
        $this->assertStringContainsString('Croatian', $block);

        $incoming = $this->message($a, 'Može li sastanak u četvrtak?', ['from_email' => 'pero@example.com', 'from_name' => 'Pero']);
        Http::fake([
            'host.docker.internal:11434/api/show' => Http::response(['capabilities' => ['completion']]),
            'host.docker.internal:11434/api/chat' => Http::response(['message' => ['role' => 'assistant', 'content' => 'Nacrt']]),
        ]);

        $this->postJson("/api/messages/{$incoming->id}/ai/draft", ['kind' => 'reply'])->assertOk();
        Http::assertSent(fn ($req) => str_contains($req->url(), '/api/chat')
            && str_contains($req['messages'][1]['content'], 'How the user writes — profile learned from 12 e-mails')
            && str_contains($req['messages'][0]['content'], 'A block "How the user writes"'));

        $chatPrompts = fn () => collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), '/api/chat'))
            ->map(fn ($pair) => $pair[0]['messages'][1]['content'])
            ->values();

        // switched off per mailbox -> gone from the prompt
        $this->putJson("/api/accounts/{$a->id}/ai/style-profile", ['enabled' => false])->assertOk()->assertJsonPath('data.enabled', false);
        $this->postJson("/api/messages/{$incoming->id}/ai/draft", ['kind' => 'reply'])->assertOk();
        $this->assertStringNotContainsString('How the user writes', $chatPrompts()[1]);

        // switched back on, but the RAG setting disables it globally
        $this->putJson("/api/accounts/{$a->id}/ai/style-profile", ['enabled' => true])->assertOk()->assertJsonPath('data.enabled', true);
        $this->putJson('/api/ai/settings', ['rag' => ['use_style_profile' => false]])->assertOk()->assertJsonPath('data.rag.use_style_profile', false);
        $this->postJson("/api/messages/{$incoming->id}/ai/draft", ['kind' => 'reply'])->assertOk();
        $this->assertStringNotContainsString('How the user writes', $chatPrompts()[2]);
        $this->assertStringContainsString('How the user writes', $chatPrompts()[0]);
    }

    public function test_style_guide_thinks_only_when_the_user_switched_thinking_on(): void
    {
        (new AiSettings('ollama', 'http://host.docker.internal:11434', 'qwen3:8b', true, null))->save();
        $a = $this->account();
        $this->seedMailbox($a);

        Http::fake([
            'host.docker.internal:11434/api/show' => Http::response(['capabilities' => ['completion', 'thinking']]),
            'host.docker.internal:11434/api/chat' => Http::response(['message' => ['role' => 'assistant', 'content' => '- Short and direct.']]),
        ]);

        // Off by default: a reasoning model could think past the timeout of this long answer.
        app(StyleProfileService::class)->build($a);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/api/chat') && $req['think'] === false);
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/api/chat') && $req['think'] === true);

        AiSettings::load()->with(['thinking' => true])->save();
        app(StyleProfileService::class)->build($a);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/api/chat') && $req['think'] === true);
    }

    public function test_summary_is_written_by_the_local_model_and_edits_are_kept(): void
    {
        (new AiSettings('ollama', 'http://host.docker.internal:11434', 'qwen3:8b', true, null))->save();
        $a = $this->account();
        $this->seedMailbox($a);

        Http::fake([
            'host.docker.internal:11434/api/show' => Http::response(['capabilities' => ['completion']]),
            'host.docker.internal:11434/api/chat' => Http::response(['message' => ['role' => 'assistant', 'content' => "<think>x</think>- Formalan, kratak.\n- Uvijek \"Lijep pozdrav\"."]]),
        ]);

        $profile = app(StyleProfileService::class)->build($a);
        $this->assertSame("- Formalan, kratak.\n- Uvijek \"Lijep pozdrav\".", $profile->summary);
        $this->assertSame('qwen3:8b', $profile->summary_model);
        $this->assertNull($profile->last_error);

        // The model saw the statistics and the sample mails, never the quoted history.
        Http::assertSent(fn ($req) => str_contains($req->url(), '/api/chat')
            && str_contains($req['messages'][1]['content'], 'Statistics from 12 sent e-mails')
            && str_contains($req['messages'][1]['content'], 'Representative sent e-mails')
            && ! str_contains($req['messages'][1]['content'], 'citirani tekst'));

        // A hand-edited guide survives rebuilds.
        $this->putJson("/api/accounts/{$a->id}/ai/style-profile", ['summary' => '- Moj ručni opis.'])
            ->assertOk()->assertJsonPath('data.summary_edited', true);
        app(StyleProfileService::class)->build($a);
        $this->assertSame('- Moj ručni opis.', StyleProfile::query()->where('account_id', $a->id)->value('summary'));

        $this->getJson("/api/accounts/{$a->id}/ai/style-profile")->assertOk()
            ->assertJsonPath('data.built', true)
            ->assertJsonPath('data.sample_count', 12)
            ->assertJsonPath('data.summary', '- Moj ručni opis.')
            ->assertJsonPath('data.stats.greetings.0.text', 'Poštovani,')
            ->assertJsonMissingPath('data.stats.sample_ids');
    }

    public function test_profile_goes_stale_with_new_sent_mail_and_is_queued_not_rebuilt_inline(): void
    {
        Queue::fake();
        $a = $this->account();
        $service = app(StyleProfileService::class);

        // nothing sent yet -> nothing to learn
        $this->assertFalse($service->isStale($a));
        $this->seedMailbox($a);
        $this->assertTrue($service->isStale($a));

        $service->build($a, withSummary: false);
        $this->assertFalse($service->isStale($a));

        // four new mails: not yet; the fifth tips it over
        for ($i = 0; $i < 4; $i++) {
            $this->sent($a, "Bok,\n\nnovi mail {$i}.", ['sent_at' => now()->addMinutes($i + 1)]);
        }
        $this->assertFalse($service->isStale($a));
        $this->sent($a, "Bok,\n\npeti mail.", ['sent_at' => now()->addMinutes(10)]);
        $this->assertTrue($service->isStale($a));

        $this->assertTrue($service->refreshIfStale($a));
        Queue::assertPushed(BuildStyleProfileJob::class, 1);
        $this->assertNotNull(StyleProfile::query()->where('account_id', $a->id)->value('queued_at'));
        // queued already -> not pushed twice
        $this->assertFalse($service->refreshIfStale($a));
        Queue::assertPushed(BuildStyleProfileJob::class, 1);

        // old profile + at least one new mail is stale too
        StyleProfile::query()->where('account_id', $a->id)->update(['queued_at' => null, 'built_at' => now()->subDays(8)]);
        $this->assertTrue($service->isStale($a));

        // the API can force a rebuild (the job itself is unique per account, so it is not pushed twice while pending)
        $this->postJson("/api/accounts/{$a->id}/ai/style-profile/rebuild")->assertStatus(202);
        $this->assertNotNull(StyleProfile::query()->where('account_id', $a->id)->value('queued_at'));
    }

    public function test_profile_never_mixes_accounts(): void
    {
        $a = $this->account();
        $b = $this->makeAccount(['email' => 'other@example.com', 'display_name' => 'Other']);
        $this->seedMailbox($a);
        $this->sent($b, "Hi team,\n\nplease find attached the quarterly numbers.\n\nCheers,\nOther", ['to_recipients' => [['name' => null, 'email' => 'x@example.com']]]);

        $service = app(StyleProfileService::class);
        $pa = $service->build($a, withSummary: false);
        $pb = $service->build($b, withSummary: false);

        $this->assertSame(12, $pa->sample_count);
        $this->assertSame(1, $pb->sample_count);
        $this->assertSame('Hi team,', $pb->stats['greetings'][0]['text']);
        $this->assertNotContains('Hi team,', array_column($pa->stats['greetings'], 'text'));
    }

    public function test_gmail_reply_headers_in_croatian_and_english_are_stripped_from_own_text(): void
    {
        $own = "Može, hvala.\n\nLp\nDavor";
        $cases = [
            "{$own}\n\npon, 3. lip 2026. u 10:15 Ivo Kovacic <ivo.kovacic@example.edu> napisao je:\n> citat\n> još citata",
            "{$own}\n\nsri, 8. srp 2026. u 09:02 Ana Anić <\nana.anic@example.com> napisala je:\n\n> citat",
            "{$own}\n\nOn Mon, 3 Jun 2026 at 10:15, Ivo Kovacic <ivo.kovacic@example.edu>\nwrote:\n> citat",
            "{$own}\n\nDana 3. lipnja 2026. u 10:15 Ivo Kovacic napisao je:\n> citat",
            "{$own}\n\n---------- Forwarded message ---------\nFrom: x\nSubject: y\n\ntekst proslijeđene poruke",
            "{$own}\n\nOd: Ana <ana@example.com>\nPoslano: 3. lipnja 2026.\nPredmet: nešto\n\nstari tekst",
            // newer Gmail: no "u" before the time, "je napisao" order
            "{$own}\n\nuto, 8. ruj 2026. 17:48 Ivo Jović <ivo.jovic@example.com> je napisao:\n> citat",
            // as Gmail really renders it: CRLF, the header wrapped onto a second line, then "> " quotes
            "{$own}\r\n\r\npet, 26. lip 2026. u 09:18 Davor Horvat <davor.horvat@example.edu>\r\nnapisao je:\r\n\r\n>\r\n> Pozdrav još jednom,\r\n>\r\n> --\r\n> *Davor Horvat*",
        ];
        foreach ($cases as $i => $case) {
            $this->assertSame($own, OwnText::of($case), "case {$i}");
        }
        // a reply that is nothing but the quote leaves no own text at all
        $this->assertSame('', OwnText::of("pon, 7. ruj 2026. u 13:41 Davor Horvat <davor.horvat@example.edu>\nnapisao je:\n\n> samo citat"));
    }

    public function test_summary_cleaning_removes_looping_bullets(): void
    {
        $raw = "- Formal tone, opens with \"Poštovani,\".\n- Nema izjave s više od jednog izjave, nema izjave.\n- Nema izjave s više od jednog izjave, nema izjave.\n* Formal tone, opens with \"Poštovani,\"!\n\n- Signs with \"Lp, Davor\".\n- nema izjave s vise od jednog izjave nema izjave.";
        $this->assertSame(
            "- Formal tone, opens with \"Poštovani,\".\n- Nema izjave s više od jednog izjave, nema izjave.\n- Signs with \"Lp, Davor\".",
            StyleProfileService::cleanSummary($raw),
        );
    }

    public function test_inline_greetings_delimiters_and_automated_mails_are_handled(): void
    {
        $mk = fn (int $i, string $text) => ['id' => $i, 'text' => $text, 'sent_at' => "2026-01-0{$i}T00:00:00Z", 'to' => ['ana@example.com']];
        $s = (new StyleAnalyzer)->analyse([
            $mk(1, "Poštovanje, rad je na granici, upisao sam 50 bodova.\n\nLijep pozdrav\nDavor"),
            $mk(2, "Poštovanje, u redu, može u 14h.\n\nLp\nDavor"),
            $mk(3, "Pozdrav, u redu, može na 01 234 5678 pa se čujemo tada.\n--\nDavor Horvat\nPRIMJER"),
            $mk(4, "--\nDavor Horvat\nPRIMJER"),
            $mk(5, 'Created and shared using Adobe Scan.'),
            $mk(6, "Invitation: Sastanak @ pon 10:00\n\nThis event has been created."),
            $mk(7, "Poštovanje, može, rezultati su na linku https://example.com/x\n--\nDavor Horvat\nPRIMJER"),
            $mk(8, "https://example.com/only-a-link\n--\nDavor Horvat\nPRIMJER"),
        ], [], ['davor', 'horvat']);

        $this->assertSame(5, $s['mails']); // the two automated mails and the signature-only mail are gone
        $this->assertNotContains('<link>', array_column($s['short_replies'], 'text')); // a bare link is not a reply
        $this->assertContains('može, rezultati su na linku <link>', array_column($s['short_replies'], 'text'));
        $this->assertSame('Poštovanje,', $s['greetings'][0]['text']);
        $this->assertSame(3, $s['greetings'][0]['mails']);
        $this->assertEquals(0.8, $s['inline_greeting_share']); // 4 of 5 real mails
        $this->assertSame(['Davor Horvat', 'PRIMJER'], $s['signature']['lines']);
        $this->assertNotContains('Poštovanje, u redu, može u 14h.', array_column($s['greetings'], 'text')); // six-word line is greeting + sentence
        $closings = array_column($s['closings'], 'text');
        $this->assertNotContains('Pozdrav, u redu, može na 01 234 5678 pa se čujemo tada', $closings);
        $this->assertNotContains('--', array_column($s['short_replies'], 'text'));
        $this->assertStringContainsString('greeting and the first sentence on ONE line', StyleProfileService::describe($s, 6000));
    }

    public function test_analyzer_handles_empty_and_english_input(): void
    {
        $this->assertSame(['mails' => 0], (new StyleAnalyzer)->analyse([]));

        $s = (new StyleAnalyzer)->analyse([
            ['id' => 1, 'text' => "Hi John,\n\nThanks for the update! I will check and get back to you tomorrow.\n\nBest regards,\nDavor", 'sent_at' => '2026-01-01T00:00:00Z', 'to' => []],
            ['id' => 2, 'text' => "Hi Mary,\n\nAttached is the report. Let me know if anything is missing.\n\nBest regards,\nDavor", 'sent_at' => '2026-01-02T00:00:00Z', 'to' => []],
            ['id' => 3, 'text' => "Hi all,\n\nSee you Monday at 10.\n\nBest regards,\nDavor", 'sent_at' => '2026-01-03T00:00:00Z', 'to' => []],
        ], [], ['davor']);

        $this->assertSame(3, $s['mails']);
        $this->assertSame('Hi {name},', $s['greetings'][0]['text']);
        $this->assertSame('Hi all,', $s['greetings'][1]['text']);
        $this->assertSame('Best regards', $s['closings'][0]['text']);
        $this->assertSame(1.0, $s['closings'][0]['share']);
        $this->assertSame([], $s['signature']['lines']);
        $this->assertSame(1.0, $s['language']['en']);
    }
}
