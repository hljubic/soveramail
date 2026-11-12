<?php

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\Message;
use App\Services\Mail\ContactBook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactsTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $email = 'me@example.com'): Account
    {
        return $this->makeAccount(['email' => $email]);
    }

    private function message(Account $a, array $attrs): Message
    {
        static $n = 0;
        $n++;

        return Message::query()->create(array_merge([
            'account_id' => $a->id, 'provider_message_id' => "m{$n}", 'subject' => 's', 'to_recipients' => [], 'cc_recipients' => [], 'bcc_recipients' => [],
            'reply_to' => [], 'headers' => [], 'label_ids' => ['INBOX'], 'received_at' => now()->subMinutes($n), 'sent_at' => now()->subMinutes($n),
        ], $attrs));
    }

    public function test_contacts_are_harvested_ranked_and_isolated_per_account(): void
    {
        $a = $this->account();
        $b = $this->account('other@example.com');
        $book = app(ContactBook::class);

        $book->recordMessage($this->message($a, ['from_name' => 'Ana Kovač', 'from_email' => 'Ana@Example.com', 'to_recipients' => [['name' => null, 'email' => 'me@example.com']]]));
        $book->recordMessage($this->message($a, ['from_email' => 'me@example.com', 'label_ids' => ['SENT'], 'to_recipients' => [['name' => 'Pero Perić', 'email' => 'pero@firma.hr']], 'cc_recipients' => [['name' => null, 'email' => 'ana@example.com']]]));
        $book->recordMessage($this->message($a, ['from_name' => 'GitHub', 'from_email' => 'noreply@github.com']));
        // seen often, but the user never wrote to them
        foreach ([1, 2, 3] as $i) {
            $book->recordMessage($this->message($a, ['from_name' => 'Boss', 'from_email' => 'boss@firma.hr']));
        }
        $book->recordMessage($this->message($b, ['from_name' => 'Tuđi Kontakt', 'from_email' => 'secret@other.org']));

        $list = $this->getJson("/api/accounts/{$a->id}/contacts")->assertOk()->json('data');
        $emails = array_column($list, 'email');

        $this->assertContains('ana@example.com', $emails);
        $this->assertContains('pero@firma.hr', $emails);
        $this->assertNotContains('me@example.com', $emails);          // never suggest yourself
        $this->assertNotContains('noreply@github.com', $emails);      // automated senders skipped
        $this->assertNotContains('secret@other.org', $emails);        // other mailbox never leaks

        // people the user wrote to come before people who only wrote to the user
        $this->assertLessThan(array_search('boss@firma.hr', $emails, true), array_search('pero@firma.hr', $emails, true));
        $this->assertLessThan(array_search('boss@firma.hr', $emails, true), array_search('ana@example.com', $emails, true));
        $this->assertSame('Ana Kovač', collect($list)->firstWhere('email', 'ana@example.com')['name']);

        $this->getJson("/api/accounts/{$a->id}/contacts?q=kova")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.email', 'ana@example.com');
        $this->getJson("/api/accounts/{$a->id}/contacts?q=firma")->assertOk()->assertJsonPath('data.0.name', 'Pero Perić');
    }
}
