<?php

namespace Tests\Feature\Imap;

use App\Jobs\SyncAccountJob;
use App\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ImapFixtures;
use Tests\TestCase;

class ImapAccountTest extends TestCase
{
    use ImapFixtures, RefreshDatabase;

    private function payload(array $extra = []): array
    {
        return $extra + [
            'email' => 'Me@Fastmail.com', 'display_name' => 'Me', 'password' => 'app-password',
            'imap' => ['host' => 'imap.fastmail.com', 'port' => 993, 'encryption' => 'ssl'],
            'smtp' => ['host' => 'smtp.fastmail.com', 'port' => 465, 'encryption' => 'ssl'],
        ];
    }

    public function test_connect_verifies_both_servers_then_stores_encrypted_credentials_and_queues_sync(): void
    {
        Queue::fake();
        $this->fakeImap();

        $this->postJson('/api/accounts/imap', [])->assertStatus(422)->assertJsonValidationErrors(['email', 'password', 'imap.host', 'smtp.host']);

        $this->postJson('/api/accounts/imap', $this->payload(['password' => 'wrong']))->assertStatus(422)->assertJsonValidationErrors(['imap.host']);
        $this->assertDatabaseCount('accounts', 0);

        $response = $this->postJson('/api/accounts/imap', $this->payload())->assertCreated()
            ->assertJsonPath('data.provider', 'imap')
            ->assertJsonPath('data.email', 'me@fastmail.com')
            ->assertJsonPath('data.can_modify', true)
            ->assertJsonPath('data.sync_status', 'queued')
            ->assertJsonPath('data.imap.host', 'imap.fastmail.com')
            ->assertJsonPath('data.imap.username', 'me@fastmail.com');
        $this->assertStringNotContainsString('app-password', $response->getContent());
        $this->assertSame([['host' => 'smtp.fastmail.com', 'port' => 465, 'encryption' => 'ssl', 'username' => 'me@fastmail.com', 'password' => 'app-password']], $this->smtp->verified);

        $account = Account::query()->firstOrFail();
        $this->assertSame('app-password', $account->credentials['imap']['password']);
        $this->assertStringNotContainsString('app-password', (string) DB::table('accounts')->value('credentials')); // encrypted at rest
        Queue::assertPushed(SyncAccountJob::class, fn (SyncAccountJob $j) => $j->accountId === $account->id);

        // Same address again = reconnect, not a duplicate.
        $this->postJson('/api/accounts/imap', $this->payload(['display_name' => 'Me 2']))->assertCreated();
        $this->assertDatabaseCount('accounts', 1);
        $this->assertSame('Me 2', $account->fresh()->display_name);
    }

    public function test_disconnect_wipes_credentials_and_reconnect_restores_them(): void
    {
        Queue::fake();
        $this->fakeImap();
        $this->postJson('/api/accounts/imap', $this->payload())->assertCreated();
        $account = Account::query()->firstOrFail();

        $this->deleteJson("/api/accounts/{$account->id}", ['keep_mail' => true])->assertOk()->assertJsonPath('account.can_modify', false);
        $this->assertNull($account->fresh()->credentials);

        $this->putJson("/api/accounts/{$account->id}/imap", $this->payload())->assertOk()->assertJsonPath('data.sync_status', 'queued');
        $this->assertNotNull($account->fresh()->credentials);
        $this->assertTrue($account->fresh()->canModify());
    }

    public function test_gmail_accounts_cannot_be_turned_into_imap_ones(): void
    {
        $this->fakeImap();
        $gmail = $this->makeAccount();
        $this->putJson("/api/accounts/{$gmail->id}/imap", $this->payload())->assertStatus(409);
    }
}
