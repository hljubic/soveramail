<?php

namespace Tests\Feature\Imap;

use App\Exceptions\ReauthRequiredException;
use App\Models\Label;
use App\Models\Message;
use App\Services\Mail\Providers\MailProvider;
use App\Services\Mail\Providers\MailProviders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ImapFixtures;
use Tests\TestCase;

class ImapSyncTest extends TestCase
{
    use ImapFixtures, RefreshDatabase;

    public function test_full_sync_maps_folders_to_labels_threads_by_headers_and_skips_virtual_folders(): void
    {
        $this->fakeImap();
        $account = $this->imapAccount();
        $this->imap->addFolder('INBOX')
            ->addFolder('Sent Items', ['\\HasNoChildren'])
            ->addFolder('Projects', ['\\HasNoChildren'])
            ->addFolder('[Gmail]/All Mail', ['\\All'])
            ->addFolder('[Gmail]', ['\\Noselect', '\\HasChildren'])
            ->addFolder('Trash', ['\\Trash']);

        $this->imap->put('Sent Items', 5, $this->raw('Me <me@fastmail.com>', 'ana@example.com', 'Ponuda', 'Šaljem ponudu.', 'sent-1@fastmail.com'), ['\\Seen']);
        $this->imap->put('INBOX', 10, $this->raw('Ana <ana@example.com>', 'me@fastmail.com', 'Re: Ponuda', 'Hvala, može.', 'in-1@example.com', 'sent-1@fastmail.com', '<sent-1@fastmail.com>'), [], '10-Sep-2026 09:00:00 +0000');
        $this->imap->put('INBOX', 11, $this->raw('Pero <pero@example.com>', 'me@fastmail.com', 'Ručak', 'Idemo?', 'in-2@example.com'), ['\\Seen', '\\Flagged']);
        $this->imap->put('Projects', 1, $this->raw('Boss <boss@example.com>', 'me@fastmail.com', 'Plan', 'Plan Q4', 'p-1@example.com'), ['\\Seen']);
        $this->imap->put('[Gmail]/All Mail', 99, $this->raw('x@example.com', 'me@fastmail.com', 'dup', 'dup', 'dup@example.com'));
        $this->imap->put('Trash', 3, $this->raw('spam@example.com', 'me@fastmail.com', 'Old', 'Old', 't-1@example.com'), ['\\Seen']);

        $stats = app(MailProviders::class)->for($account)->sync($account, MailProvider::MODE_FULL, 100);

        $this->assertSame(['mode' => 'full', 'added' => 5, 'updated' => 0, 'deleted' => 0, 'remaining' => 0], $stats);
        $this->assertTrue($this->imap->closed);
        $this->assertSame('idle', $account->fresh()->sync_status);
        $this->assertTrue($account->fresh()->initial_sync_done);

        $byId = Message::query()->where('account_id', $account->id)->get()->keyBy('provider_message_id');
        $this->assertArrayHasKey('INBOX::1::10', $byId->all());
        $this->assertArrayNotHasKey('[Gmail]/All Mail::1::99', $byId->all());

        // Folder + flags -> the labels the UI already understands.
        $this->assertSame(['INBOX', 'UNREAD'], $byId['INBOX::1::10']->label_ids);
        $this->assertSame(['INBOX', 'STARRED'], $byId['INBOX::1::11']->label_ids);
        $this->assertSame(['SENT'], $byId['Sent Items::1::5']->label_ids);
        $this->assertSame(['Projects'], $byId['Projects::1::1']->label_ids);
        $this->assertSame(['TRASH'], $byId['Trash::1::3']->label_ids);
        $this->assertTrue($byId['INBOX::1::10']->is_unread);
        $this->assertTrue($byId['INBOX::1::11']->is_starred);

        // The reply joins the thread of my sent mail (In-Reply-To), the rest start their own.
        $this->assertSame($byId['Sent Items::1::5']->thread_id, $byId['INBOX::1::10']->thread_id);
        $this->assertNotSame($byId['INBOX::1::10']->thread_id, $byId['INBOX::1::11']->thread_id);
        $this->assertSame('mid:<in-2@example.com>', $byId['INBOX::1::11']->thread_id);

        $labels = Label::query()->where('account_id', $account->id)->pluck('type', 'provider_label_id')->all();
        $this->assertSame('system', $labels['INBOX']);
        $this->assertSame('system', $labels['SENT']);
        $this->assertSame('user', $labels['Projects']);
        $this->assertArrayNotHasKey('[Gmail]/All Mail', $labels);

        // The mail list works exactly as for Gmail.
        $this->getJson("/api/accounts/{$account->id}/messages?folder=inbox")->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/accounts/{$account->id}/messages?folder=sent")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/accounts/{$account->id}/messages?label=Projects")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_incremental_sync_adds_new_mail_mirrors_flags_and_hides_but_never_deletes(): void
    {
        $this->fakeImap();
        $account = $this->imapAccount();
        $this->imap->addFolder('INBOX');
        $this->imap->put('INBOX', 1, $this->raw('a@example.com', 'me@fastmail.com', 'One', 'one', 'one@example.com'));
        $this->imap->put('INBOX', 2, $this->raw('b@example.com', 'me@fastmail.com', 'Two', 'two', 'two@example.com'));
        $provider = app(MailProviders::class)->for($account);
        $provider->sync($account, MailProvider::MODE_FULL, 100);

        // Meanwhile on the server: 1 read, 2 gone, 3 new.
        $this->imap->folders['INBOX']['messages'][1]['flags'] = ['\\Seen'];
        unset($this->imap->folders['INBOX']['messages'][2]);
        $this->imap->put('INBOX', 3, $this->raw('c@example.com', 'me@fastmail.com', 'Three', 'three', 'three@example.com'));

        $stats = app(MailProviders::class)->for($account->fresh())->sync($account->fresh());

        $this->assertSame(['mode' => 'incremental', 'added' => 1, 'updated' => 1, 'deleted' => 1, 'remaining' => 0], $stats);
        $this->assertDatabaseCount('messages', 3); // nothing erased
        $this->assertFalse(Message::query()->where('provider_message_id', 'INBOX::1::1')->value('is_unread'));
        $this->assertNotNull(Message::query()->where('provider_message_id', 'INBOX::1::2')->value('remote_deleted_at'));
        $this->getJson("/api/accounts/{$account->id}/messages?folder=inbox")->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame(3, $account->fresh()->load('messages')->messages->count());

        // A renumbered folder (UIDVALIDITY changed) hides the stale copies and re-downloads.
        $this->imap->folders['INBOX']['uidvalidity'] = 2;
        $stats = app(MailProviders::class)->for($account->fresh())->sync($account->fresh());
        $this->assertSame(2, $stats['deleted']);
        $this->assertSame(2, $stats['added']);
        $this->assertSame(2, Message::query()->where('provider_message_id', 'like', 'INBOX::2::%')->count());
        $this->assertDatabaseCount('messages', 5);
    }

    public function test_backfill_pulls_older_mail_and_range_pulls_missing_mail(): void
    {
        $this->fakeImap();
        $account = $this->imapAccount();
        $this->imap->addFolder('INBOX');
        foreach (range(1, 6) as $uid) {
            $this->imap->put('INBOX', $uid, $this->raw('a@example.com', 'me@fastmail.com', "M{$uid}", "m{$uid}", "m{$uid}@example.com"));
        }

        app(MailProviders::class)->for($account)->sync($account, MailProvider::MODE_FULL, 2);
        $this->assertSame(['INBOX::1::5', 'INBOX::1::6'], Message::query()->orderBy('provider_message_id')->pluck('provider_message_id')->all());

        $stats = app(MailProviders::class)->for($account->fresh())->sync($account->fresh(), MailProvider::MODE_BACKFILL, 2);
        $this->assertSame(2, $stats['added']);
        $this->assertSame(['INBOX::1::3', 'INBOX::1::4', 'INBOX::1::5', 'INBOX::1::6'], Message::query()->orderBy('provider_message_id')->pluck('provider_message_id')->all());

        $stats = app(MailProviders::class)->for($account->fresh())->sync($account->fresh(), MailProvider::MODE_RANGE, null, 365);
        $this->assertSame(['added' => 2, 'remaining' => 0], ['added' => $stats['added'], 'remaining' => $stats['remaining']]);
        $this->assertDatabaseCount('messages', 6);
    }

    public function test_bad_password_marks_the_account_for_reconnect(): void
    {
        $this->fakeImap();
        $account = $this->imapAccount();
        $account->forceFill(['credentials' => ['imap' => ['host' => 'x', 'port' => 993, 'encryption' => 'ssl', 'username' => 'me', 'password' => 'wrong'], 'smtp' => []]])->save();

        try {
            app(MailProviders::class)->for($account)->sync($account, MailProvider::MODE_FULL);
            $this->fail('expected ReauthRequiredException');
        } catch (ReauthRequiredException) {
        }
        $this->assertSame('needs_reauth', $account->fresh()->sync_status);
    }
}
