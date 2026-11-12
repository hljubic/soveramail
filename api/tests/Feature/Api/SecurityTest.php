<?php

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Services\Ai\AiSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The local API must only be reachable by the Sovera Mail client on this machine.
 */
class SecurityTest extends TestCase
{
    use RefreshDatabase;

    private function account(): Account
    {
        return $this->makeAccount();
    }

    public function test_dns_rebinding_host_is_rejected(): void
    {
        $this->account();

        // Symfony derives the Host header from the request URL, so use full URLs here.
        $this->getJson('http://attacker.example/api/accounts')->assertStatus(421);
        $this->getJson('http://attacker.example:8090/api/accounts')->assertStatus(421);
        $this->getJson('http://localhost:8090/api/accounts')->assertOk();
        $this->getJson('http://127.0.0.1:8090/api/accounts')->assertOk();
    }

    public function test_foreign_origin_is_rejected_even_for_reads(): void
    {
        $this->account();

        $this->getJson('/api/accounts', ['Origin' => 'https://evil.example'])->assertStatus(403);
        $this->getJson('/api/accounts', ['Origin' => 'null'])->assertStatus(403);
        $this->getJson('/api/accounts', ['Origin' => 'http://localhost:3000'])->assertOk();
        $this->getJson('/api/accounts', ['Origin' => 'http://127.0.0.1:3000'])->assertOk();
    }

    public function test_state_changing_calls_need_the_client_header(): void
    {
        $account = $this->account();

        $this->flushHeaders(); // simulate a plain browser request without our client header

        // A hidden <form> from a web page: no custom header, urlencoded body.
        $this->post("/api/accounts/{$account->id}/sync", ['mode' => 'incremental'], ['Accept' => 'application/json'])
            ->assertStatus(403);

        $this->postJson('/api/messages/1/reply', ['to' => ['x@y.z'], 'body' => 'pwned'])
            ->assertStatus(403);

        // Reads without the header still work (CORS keeps responses away from other origins).
        $this->getJson('/api/accounts')->assertOk();
    }

    public function test_oauth_callback_and_health_stay_reachable_from_a_plain_browser_tab(): void
    {
        $this->flushHeaders();
        $this->get('/api/auth/google/callback?error=access_denied')->assertOk();
        $this->getJson('/api/health')->assertOk();
    }

    public function test_ai_server_must_be_local(): void
    {
        $this->putJson('/api/ai/settings', ['base_url' => 'https://api.example.com', 'model' => 'x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['base_url']);

        $this->postJson('/api/ai/probe', ['base_url' => 'http://203.0.113.10:11434'])->assertStatus(503);
        $this->postJson('/api/ai/probe', ['base_url' => 'http://169.254.169.254/latest/meta-data'])->assertStatus(503);
        $this->postJson('/api/ai/probe', ['base_url' => 'http://looks-private.internal:11434'])->assertStatus(503);

        // Explicit opt-in allows a remote inference server (e.g. a GPU box at the university).
        $this->putJson('/api/ai/settings', ['base_url' => 'http://gpu.example.org:11434', 'model' => 'x', 'allow_remote' => true])
            ->assertOk()
            ->assertJsonPath('data.base_url', 'http://gpu.example.org:11434')
            ->assertJsonPath('data.allow_remote', true);
        // ...and turning it off again rejects the remote URL on the next save.
        $this->putJson('/api/ai/settings', ['allow_remote' => false])->assertStatus(422);

        $this->putJson('/api/ai/settings', ['base_url' => 'http://192.168.1.20:11434', 'model' => 'x', 'allow_remote' => false])->assertOk();
        $this->putJson('/api/ai/settings', ['base_url' => 'http://mymac.local:11434', 'model' => 'x'])->assertOk();

        $this->assertSame('http://mymac.local:11434', AiSettings::load()->baseUrl);
    }

    public function test_tokens_never_appear_in_api_responses(): void
    {
        $account = $this->account();

        $json = $this->getJson("/api/accounts/{$account->id}")->assertOk()->getContent();
        $this->assertStringNotContainsString('access_token', $json);
        $this->assertStringNotContainsString('refresh_token', $json);
    }
}
