<?php

namespace Tests\Feature\Api;

use App\Jobs\SyncAccountJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Sovera Mail never sends or writes mail on its own. Sending happens only when the
 * user clicks Send and confirms (MessageController, confirm_send + idempotency key);
 * no job, command or schedule can reach the sending code. And it fetches mail on a
 * schedule only while its window is open.
 */
class NoAutoSendTest extends TestCase
{
    use RefreshDatabase;

    /** Code that runs without a click: queue jobs, console commands, the schedule. */
    private function backgroundSources(): array
    {
        $files = array_merge(glob(app_path('Jobs/*.php')), glob(app_path('Console/Commands/*.php')), [base_path('routes/console.php')]);

        return array_combine($files, array_map('file_get_contents', $files));
    }

    public function test_no_background_code_can_send_or_create_drafts(): void
    {
        $forbidden = ['OutgoingMailService', 'sendRaw', 'createDraft', 'saveDraft', '->sendNew(', '->reply(', '->forward(', 'SmtpSender', 'MessageController'];
        foreach ($this->backgroundSources() as $file => $code) {
            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString($needle, $code, basename($file)." must not reach the sending code ({$needle})");
            }
        }
    }

    public function test_only_the_message_controller_sends_and_only_with_an_explicit_confirmation(): void
    {
        $callers = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.php') && $f->getFilename() !== 'OutgoingMailService.php'
                && str_contains(file_get_contents($f->getPathname()), 'OutgoingMailService')) {
                $callers[] = $f->getFilename();
            }
        }
        $this->assertSame(['MessageController.php'], $callers);

        $controller = file_get_contents(app_path('Http/Controllers/Api/MessageController.php'));
        $this->assertStringContainsString("'confirm_send' =>", $controller);
        $this->assertStringContainsString("'accepted'", $controller);
    }

    public function test_scheduled_fetching_runs_only_while_the_app_window_is_open(): void
    {
        Queue::fake();
        $this->makeAccount();

        $this->artisan('sovera:sync-accounts', ['--only-while-open' => true])->expectsOutputToContain('not open')->assertSuccessful();
        Queue::assertNotPushed(SyncAccountJob::class);

        // The open window polls the accounts list.
        $this->getJson('/api/accounts')->assertOk();
        $this->artisan('sovera:sync-accounts', ['--only-while-open' => true])->assertSuccessful();
        Queue::assertPushed(SyncAccountJob::class);

        // Closed for longer than a few minutes: nothing again.
        Queue::fake();
        $this->travel(4)->minutes();
        $this->artisan('sovera:sync-accounts', ['--only-while-open' => true])->assertSuccessful();
        Queue::assertNotPushed(SyncAccountJob::class);

        $schedule = file_get_contents(base_path('routes/console.php'));
        $this->assertStringContainsString('sovera:sync-accounts --only-while-open', $schedule);
    }
}
