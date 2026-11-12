<?php

namespace Tests\Feature\Ai;

use App\Models\Account;
use App\Models\Message;
use App\Services\Ai\AiSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PromptTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function account(): Account
    {
        return $this->makeAccount(['display_name' => 'Davor']);
    }

    private function message(Account $a, array $attrs): Message
    {
        $n = ++$this->seq;

        return Message::query()->create(array_merge([
            'account_id' => $a->id, 'provider_message_id' => "gm-{$n}", 'subject' => 'S', 'from_name' => 'Ana', 'from_email' => 'ana@example.com',
            'to_recipients' => [['name' => null, 'email' => 'me@example.com']], 'cc_recipients' => [], 'bcc_recipients' => [], 'reply_to' => [],
            'snippet' => '', 'body_text' => 'tekst', 'headers' => [], 'label_ids' => ['INBOX'],
            'received_at' => now()->subMinutes($n), 'sent_at' => now()->subMinutes($n),
        ], $attrs));
    }

    public function test_user_prompt_is_saved_and_returned(): void
    {
        $this->putJson('/api/ai/settings', ['user_prompt' => "  Potpiši se kao Davor.\n  "])
            ->assertOk()
            ->assertJsonPath('data.user_prompt', 'Potpiši se kao Davor.');

        $this->getJson('/api/ai/settings')->assertJsonPath('data.user_prompt', 'Potpiši se kao Davor.');
    }

    public function test_draft_prompt_contains_hidden_rules_user_prompt_and_own_replies_to_sender(): void
    {
        (new AiSettings('ollama', 'http://host.docker.internal:11434', 'qwen3:8b', true, null, 'Uvijek dodaj "Lp, Davor" i budi kratak.'))->save();
        $a = $this->account();

        $incoming = $this->message($a, ['subject' => 'Termin', 'body_text' => 'Može li sastanak u četvrtak?']);
        // my earlier reply to Ana (with quoted history that must be stripped)
        $this->message($a, [
            'subject' => 'Re: Prošli put', 'from_name' => 'Davor', 'from_email' => 'me@example.com',
            'to_recipients' => [['name' => 'Ana', 'email' => 'ana@example.com']], 'label_ids' => ['SENT'],
            'body_text' => "Bok Ana,\n\nmože, vidimo se u tjednu nakon.\n\nLp, Davor\n\nOn Mon, Ana wrote:\n> stari citat",
        ]);
        // my reply to someone else: must not be used as a style example for Ana
        $this->message($a, [
            'subject' => 'Re: Drugo', 'from_email' => 'me@example.com',
            'to_recipients' => [['name' => null, 'email' => 'pero@example.com']], 'label_ids' => ['SENT'], 'body_text' => 'Peri poruka',
        ]);

        Http::fake([
            'host.docker.internal:11434/api/chat' => Http::response(['message' => ['role' => 'assistant', 'content' => 'Nacrt']]),
        ]);

        $this->postJson("/api/messages/{$incoming->id}/ai/draft", ['kind' => 'reply'])->assertOk();

        Http::assertSent(function ($r) {
            if (! str_ends_with($r->url(), '/api/chat')) {
                return false;
            }
            $system = $r['messages'][0]['content'];
            $user = $r['messages'][1]['content'];

            return str_contains($system, 'Croatian, Serbian, Bosnian')
                && str_contains($system, 'STANDING INSTRUCTIONS FROM THE USER')
                && str_contains($system, 'Lp, Davor')
                && str_contains($user, 'How the user previously wrote to Ana')
                && str_contains($user, 'vidimo se u tjednu nakon')
                && ! str_contains($user, 'stari citat')
                && ! str_contains($user, 'Peri poruka')
                && str_contains($user, 'Može li sastanak u četvrtak?');
        });
    }
}
