<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Services\Ai\Style\StyleProfileService;
use Illuminate\Console\Command;

class StyleProfileCommand extends Command
{
    protected $signature = 'sovera:style-profile {--account= : Only this account id} {--now : Build synchronously} {--force : Rebuild even when nothing changed}';

    protected $description = 'Learn how each mailbox owner writes (from their sent mail) for AI drafts; queues only stale profiles unless --force';

    public function handle(StyleProfileService $profiles): int
    {
        $query = Account::query();
        if ($id = $this->option('account')) {
            $query->whereKey($id);
        }

        foreach ($query->get() as $account) {
            if ($this->option('now')) {
                $this->line("Analysing {$account->email} ...");
                $profile = $profiles->build($account);
                $this->info("  {$profile->sample_count} sent mails".($profile->summary ? ', style guide written' : '').($profile->last_error ? " (note: {$profile->last_error})" : ''));

                continue;
            }
            if ($this->option('force')) {
                $profiles->queue($account);
                $this->line("Queued {$account->email}");
            } elseif ($profiles->refreshIfStale($account)) {
                $this->line("Queued {$account->email} (profile stale)");
            } else {
                $this->line("{$account->email}: up to date");
            }
        }

        return self::SUCCESS;
    }
}
