<?php

namespace Tests\Feature\Ai;

use App\Models\Account;
use App\Models\AiSuggestion;
use App\Models\Message;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Style\DraftFeedbackService;
use App\Services\Ai\Style\StyleProfileService;
use App\Services\Ai\Style\TextDiff;
use Google\Service\Gmail\Message as GmailMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DraftFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private const SEND_KEY = '0b9d0d7e-0f4b-4c1e-9a1d-2f8f3a2b6c01';

    private function account(): Account
    {
        return $this->makeAccount(['display_name' => 'Davor', 'email' => 'me@example.com']);
    }

    private function incoming(Account $a): Message
    {
        return Message::query()->create([
            'account_id' => $a->id, 'provider_message_id' => 'gm-in', 'thread_id' => 'thr-1', 'subject' => 'Termin',
            'from_name' => 'Ana', 'from_email' => 'ana@example.com',
            'to_recipients' => [['name' => null, 'email' => $a->email]], 'cc_recipients' => [], 'bcc_recipients' => [], 'reply_to' => [],
            'snippet' => 'x', 'body_text' => 'Može li sastanak u četvrtak?', 'headers' => [], 'label_ids' => ['INBOX'],
            'received_at' => now(), 'sent_at' => now(),
        ]);
    }

    private function enableAi(): void
    {
        (new AiSettings('ollama', 'http://host.docker.internal:11434', 'qwen3:8b', true, null))->save();
        Http::fake([
            'host.docker.internal:11434/api/show' => Http::response(['capabilities' => ['completion']]),
            'host.docker.internal:11434/api/chat' => Http::response(['message' => ['role' => 'assistant', 'content' => "Poštovani,\n\nNadam se da ste dobro. Sastanak u četvrtak mi odgovara, molim Vas potvrdite termin.\n\nSrdačan pozdrav,\nDavor"]]),
        ]);
    }

    public function test_text_diff_finds_replacements_removals_spelling_and_greeting_changes(): void
    {
        $before = "Poštovani,\n\nNadam se da ste dobro. Sastanak u cetvrtak mi odgovara, molim Vas potvrdite termin.\n\nSrdačan pozdrav,\nDavor";
        $after = "Bok Ana,\n\nSastanak u četvrtak mi paše, javi termin.\n\nLp\nDavor";

        $d = TextDiff::compare($before, $after);

        $this->assertLessThan(0.6, $d['similarity']);
        $this->assertGreaterThan(0.2, $d['similarity']);
        $this->assertSame(['from' => 'Poštovani,', 'to' => 'Bok Ana,'], $d['greeting']);
        $this->assertNull($d['closing']); // both end with the same "Davor" line

        $byKey = [];
        foreach ($d['hunks'] as $h) {
            $byKey[$h['from'].'|'.$h['to']] = $h;
        }
        $this->assertSame('diacritics', $byKey['cetvrtak|četvrtak']['kind']);
        $this->assertSame('remove', $byKey['Nadam se da ste dobro.|']['type']);
        $this->assertArrayHasKey('Srdačan pozdrav,|Lp', $byKey);
        $this->assertSame('wording', $byKey['Srdačan pozdrav,|Lp']['kind']);

        $same = TextDiff::compare('Isti tekst.', 'Isti tekst.');
        $this->assertSame(1.0, $same['similarity']);
        $this->assertSame([], $same['hunks']);

        $this->assertSame('case', TextDiff::compare('hvala Vam', 'hvala vam')['hunks'][0]['kind']);
        $this->assertSame('spelling', TextDiff::compare('pozdarv svima', 'pozdrav svima')['hunks'][0]['kind']);
        $this->assertSame('punctuation', TextDiff::compare('Bok Ana,', 'Bok Ana!')['hunks'][0]['kind']);
    }

    public function test_ai_draft_is_remembered_and_the_sent_version_is_diffed_against_it(): void
    {
        $this->enableAi();
        $a = $this->account();
        $in = $this->incoming($a);

        $draft = $this->postJson("/api/messages/{$in->id}/ai/draft", ['kind' => 'reply'])->assertOk();
        $suggestionId = $draft->json('data.suggestion_id');
        $this->assertIsInt($suggestionId);
        $this->assertDatabaseHas('ai_suggestions', ['id' => $suggestionId, 'account_id' => $a->id, 'message_id' => $in->id, 'kind' => 'reply', 'model' => 'qwen3:8b']);

        $client = $this->fakeGmail();
        $client->shouldReceive('sendRaw')->once()->andReturn(new GmailMessage(['id' => 'gm-sent', 'threadId' => 'thr-1']));
        $client->shouldReceive('getMessage')->once()->with('gm-sent', 'full')->andReturn($this->gmailMessage('gm-sent', ['SENT'], 'Re: Termin', 'Bok Ana', 'me@example.com', 'thr-1'));

        $final = "Bok Ana,\n\nSastanak u četvrtak mi paše, javi termin.\n\nLp\nDavor";
        $this->postJson("/api/messages/{$in->id}/reply", [
            'to' => ['ana@example.com'], 'body' => $final, 'confirm_send' => true, 'idempotency_key' => self::SEND_KEY, 'ai_suggestion_id' => $suggestionId,
        ])->assertStatus(201);

        $s = AiSuggestion::query()->find($suggestionId);
        $this->assertSame($final, $s->final_text);
        $this->assertNotNull($s->sent_at);
        $this->assertNotNull($s->sent_message_id);
        $this->assertLessThan(0.7, $s->similarity);
        $this->assertSame(['from' => 'Poštovani,', 'to' => 'Bok Ana,'], $s->stats['greeting']);
        $this->assertNotEmpty($s->edits);

        // aggregated into the profile and into the prompt block
        $agg = app(DraftFeedbackService::class)->aggregate($a);
        $this->assertSame(1, $agg['drafts']);
        $this->assertSame('Poštovani,', $agg['greetings'][0]['from']);
        $this->assertSame('Bok Ana,', $agg['greetings'][0]['to']);
        $removed = array_column($agg['removed'], 'from');
        $this->assertContains('Nadam se da ste dobro.', $removed);

        Http::fake(); // no model needed for the stats
        app(StyleProfileService::class)->build($a, withSummary: false);
        // the sent copy is the account's only sent mail, so the profile is built from it and carries the corrections
        $block = app(StyleProfileService::class)->promptBlock($a, 4000);
        $this->assertStringContainsString('profile learned from 1 e-mail the user sent', $block);
        $this->assertStringContainsString('Corrections the user made to 1 earlier AI draft', $block);

        $profile = $a->styleProfile()->first();
        $this->assertSame(1, $profile->stats['feedback']['drafts']);
        $facts = StyleProfileService::describe($profile->stats, 4000);
        $this->assertStringContainsString('Corrections the user made to 1 earlier AI draft before sending', $facts);
        $this->assertStringContainsString('greeting: "Poštovani," → "Bok Ana,"', $facts);
        $this->assertStringContainsString('removes: "Nadam se da ste dobro."', $facts);
    }

    public function test_feedback_never_breaks_sending_and_never_crosses_accounts(): void
    {
        $this->enableAi();
        $a = $this->account();
        $b = $this->makeAccount(['email' => 'other@example.com']);
        $foreign = app(DraftFeedbackService::class)->remember($b, 'new', null, 'm', 'Tuđi prijedlog.');

        $client = $this->fakeGmail();
        $client->shouldReceive('sendRaw')->once()->andReturn(new GmailMessage(['id' => 'gm-new', 'threadId' => 't-new']));
        $client->shouldReceive('getMessage')->once()->with('gm-new', 'full')->andReturn($this->gmailMessage('gm-new', ['SENT'], 'Pozdrav', 'Bok', 'me@example.com'));

        // a suggestion id from another mailbox (or a stale/unknown id) is ignored, the send succeeds
        $this->postJson("/api/accounts/{$a->id}/messages/send", [
            'to' => ['ana@example.com'], 'subject' => 'Pozdrav', 'body' => 'Bok', 'confirm_send' => true, 'idempotency_key' => self::SEND_KEY, 'ai_suggestion_id' => $foreign->id,
        ])->assertStatus(201);

        $this->assertNull($foreign->refresh()->sent_at);
        $this->assertSame('Tuđi prijedlog.', $foreign->suggestion);
        $this->assertNull(app(DraftFeedbackService::class)->aggregate($a));
    }

    public function test_repeated_corrections_are_ranked_and_make_the_profile_stale(): void
    {
        $a = $this->account();
        $fb = app(DraftFeedbackService::class);
        for ($i = 0; $i < 3; $i++) {
            $s = $fb->remember($a, 'reply', null, 'm', "Poštovani,\n\nNadam se da ste dobro. Šaljem Vam dokument u prilogu.\n\nSrdačan pozdrav,\nDavor");
            $fb->recordSend($a, $s->id, "Pozdrav,\n\nŠaljem dokument u privitku.\n\nLp\nDavor");
        }
        $s = $fb->remember($a, 'reply', null, 'm', 'Sve ok.');
        $fb->recordSend($a, $s->id, 'Sve ok.');

        $agg = $fb->aggregate($a);
        $this->assertSame(4, $agg['drafts']);
        $this->assertSame(0.25, $agg['unchanged_share']);
        $this->assertSame(3, $agg['greetings'][0]['count']);
        $pairs = array_map(fn ($r) => "{$r['from']}|{$r['to']}|{$r['count']}", $agg['replacements']);
        $this->assertContains('prilogu.|privitku.|3', $pairs);
        $this->assertContains('Srdačan pozdrav,|Lp|3', $pairs);
        $this->assertContains('Vam', array_column($agg['removed'], 'from'));
        $this->assertGreaterThanOrEqual(0.5, $agg['shortened_share']);

        $lines = DraftFeedbackService::describe($agg);
        $this->assertStringContainsString('Corrections the user made to 4 earlier AI drafts', $lines[0]);
        $this->assertStringContainsString('shortened', $lines[0]);
        $this->assertContains('  greeting: "Poštovani," → "Pozdrav," (3×)', $lines);
        $this->assertContains('  wording: "prilogu." → "privitku." (3×)', $lines);

        // three sent AI drafts since the last build -> rebuild
        $profiles = app(StyleProfileService::class);
        $profiles->build($a, withSummary: false);
        $this->assertFalse($profiles->isStale($a));
        $this->travel(1)->seconds();
        for ($i = 0; $i < 3; $i++) {
            $s = $fb->remember($a, 'new', null, 'm', 'Nacrt.');
            $fb->recordSend($a, $s->id, 'Nacrt!');
        }
        $this->assertTrue($profiles->isStale($a));
    }
}
