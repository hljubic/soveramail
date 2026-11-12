<?php

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\Message;
use App\Services\Gmail\GmailClient;
use App\Services\Google\GoogleClientFactory;
use App\Services\Sync\GmailSyncService;
use Google\Service\Gmail\ListHistoryResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Mockery\MockInterface;
use ReflectionClass;
use Tests\TestCase;

/**
 * Sovera Mail never deletes mail: not in Gmail, not locally. These tests are
 * tripwires so the guarantee cannot be lost by accident.
 */
class NoDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function account(): Account
    {
        return $this->makeAccount();
    }

    private function message(Account $account, string $id): Message
    {
        return Message::query()->create([
            'account_id' => $account->id, 'provider_message_id' => $id, 'subject' => 'S '.$id, 'from_email' => 'a@b.c',
            'to_recipients' => [], 'cc_recipients' => [], 'bcc_recipients' => [], 'reply_to' => [], 'headers' => [],
            'label_ids' => ['INBOX', 'UNREAD'], 'is_unread' => true, 'received_at' => now(), 'sent_at' => now(),
        ]);
    }

    public function test_the_api_has_no_delete_endpoint_for_accounts_or_messages(): void
    {
        $deleteRoutes = [];
        foreach (Route::getRoutes() as $route) {
            if (in_array('DELETE', $route->methods(), true)) {
                $deleteRoutes[] = $route->uri();
            }
        }

        // DELETE exists only for an account (with an explicit keep/delete choice),
        // for the user's own unsent local draft text and for a local category (a
        // label that exists only in Sovera Mail: its mail stays, it is sorted again).
        // Never for a message.
        sort($deleteRoutes);
        $this->assertSame(['api/accounts/{account}', 'api/categories/{category}', 'api/messages/{message}/local-draft'], $deleteRoutes);

        $account = $this->account();
        $message = $this->message($account, 'm1');
        $this->deleteJson("/api/messages/{$message->id}")->assertStatus(405);
        // Removing an account without saying what happens to the mail is refused.
        $this->deleteJson("/api/accounts/{$account->id}")->assertStatus(422)->assertJsonValidationErrors(['keep_mail']);
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseCount('accounts', 1);
    }

    public function test_gmail_client_has_no_trash_or_delete_operation(): void
    {
        $methods = array_map(fn ($m) => $m->getName(), (new ReflectionClass(GmailClient::class))->getMethods());
        foreach ($methods as $name) {
            $this->assertDoesNotMatchRegularExpression('/trash|delete|remove|batchModify/i', $name, "GmailClient::{$name} looks like a destructive operation");
        }

        // No code path anywhere in the app calls a destructive Gmail endpoint.
        foreach (File::allFiles(app_path()) as $file) {
            $src = $file->getContents();
            foreach (['users_messages->trash', 'users_messages->untrash', 'users_messages->delete', 'users_messages->batchDelete',
                'users_messages->batchModify', 'users_threads->trash', 'users_threads->delete', 'users_drafts->delete', 'users_labels->delete'] as $needle) {
                $this->assertStringNotContainsString($needle, $src, "{$file->getRelativePathname()} calls a destructive Gmail endpoint");
            }
        }
    }

    public function test_label_changes_are_limited_to_read_and_star(): void
    {
        config(['sovera.google.client_id' => 'id', 'sovera.google.client_secret' => 'secret']);
        $client = new GmailClient($this->account(), app(GoogleClientFactory::class));

        foreach (['TRASH', 'SPAM', 'INBOX', 'IMPORTANT', 'Label_12'] as $label) {
            foreach ([[[$label], []], [[], [$label]]] as [$add, $remove]) {
                try {
                    $client->modifyLabels('gm-1', $add, $remove);
                    $this->fail("modifyLabels accepted {$label}");
                } catch (InvalidArgumentException $e) {
                    $this->assertStringContainsString($label, $e->getMessage());
                }
            }
        }
    }

    public function test_messages_deleted_in_gmail_are_hidden_but_kept_locally(): void
    {
        $account = $this->account();
        $gone = $this->message($account, 'gone');
        $this->message($account, 'still-there');

        /** @var MockInterface&GmailClient $client */
        $client = $this->fakeGmail();
        $client->shouldReceive('labels')->andReturn([]);
        $client->shouldReceive('listHistory')->once()->with('1', null)->andReturn(new ListHistoryResponse([
            'historyId' => '2',
            'history' => [['id' => '2', 'messagesDeleted' => [['message' => ['id' => 'gone', 'threadId' => 't']]]]],
        ]));
        $client->shouldNotReceive('getMessages');

        $stats = app(GmailSyncService::class)->sync($account);

        $this->assertSame(1, $stats['deleted']);
        $this->assertDatabaseCount('messages', 2);                 // nothing erased
        $this->assertNotNull($gone->fresh()->remote_deleted_at);
        $this->assertSame('2', $account->fresh()->history_id);

        // Hidden from every view and counter ...
        foreach (['inbox', 'unread', 'all'] as $folder) {
            $ids = collect($this->getJson("/api/accounts/{$account->id}/messages?folder={$folder}")->assertOk()->json('data'))->pluck('provider_message_id')->all();
            $this->assertSame(['still-there'], $ids, "folder {$folder}");
        }
        $this->getJson("/api/accounts/{$account->id}")->assertOk()->assertJsonPath('data.messages_count', 1)->assertJsonPath('data.unread_count', 1);

        // ... but still readable by id, e.g. from a link in an old AI context.
        $this->getJson("/api/messages/{$gone->id}")->assertOk();
    }

    public function test_local_drafts_are_the_only_thing_the_ui_may_delete(): void
    {
        $account = $this->account();
        $message = $this->message($account, 'm1');

        $this->putJson("/api/messages/{$message->id}/local-draft", ['kind' => 'reply', 'body' => 'unsent text'])->assertOk();
        $this->deleteJson("/api/messages/{$message->id}/local-draft")->assertOk();

        $this->assertDatabaseCount('local_drafts', 0);
        $this->assertDatabaseCount('messages', 1);
    }
}
