<?php

namespace Tests\Feature\Ai;

use App\Models\Account;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Knowledge\KnowledgeBuilder;
use App\Services\Ai\SummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Summaries and knowledge are written in the user's reply language when one is set,
 * otherwise in the language of the mail itself (never a hard-coded language).
 */
class SummaryLanguageTest extends TestCase
{
    use RefreshDatabase;

    public function test_summaries_follow_the_mail_unless_a_reply_language_is_set(): void
    {
        $settings = new AiSettings('ollama', 'http://host.docker.internal:11434', 'qwen3:8b', true);

        $this->assertStringContainsString('Write the summary in the language the e-mails are written in', SummaryService::instructions($settings)[0]);
        $this->assertStringContainsString('Write the summary in German', SummaryService::instructions($settings->with(['reply_language' => 'de']))[0]);
    }

    public function test_knowledge_follows_the_mail_unless_a_reply_language_is_set(): void
    {
        $account = Account::query()->create(['provider' => 'imap', 'email' => 'me@example.com']);
        $task = ['kind' => 'about', 'subject' => '', 'title' => 'About'];
        $settings = new AiSettings('ollama', 'http://host.docker.internal:11434', 'qwen3:8b', true);

        $this->assertStringContainsString('Write in the language most of the e-mails are written in.', KnowledgeBuilder::prompt($task, $account, $settings));
        $this->assertStringContainsString('Write in German.', KnowledgeBuilder::prompt($task, $account, $settings->with(['reply_language' => 'de'])));
    }
}
