<?php

namespace App\Console\Commands;

use App\Jobs\SyncAccountJob;
use App\Models\Account;
use App\Services\Mail\Providers\MailProviders;
use App\Support\AppPresence;
use Illuminate\Console\Command;

class SyncAccountsCommand extends Command
{
    protected $signature = 'sovera:sync-accounts
        {--account= : Only this account id}
        {--mode=incremental : incremental|full|backfill|range}
        {--since-days= : For --mode=range: how far back (30, 182, 365 ...)}
        {--limit= : Message limit for full/backfill}
        {--now : Run synchronously instead of queueing}
        {--only-while-open : Skip unless the Sovera Mail window is open (used by the schedule)}';

    protected $description = 'Queue (or run) a mailbox sync for every connected account';

    public function handle(MailProviders $providers): int
    {
        // Scheduled fetching happens only while the app is open: nothing runs in the
        // background after the window is closed (opening it fetches right away).
        if ($this->option('only-while-open') && ! AppPresence::isOpen()) {
            $this->line('Sovera Mail is not open: not fetching.');

            return self::SUCCESS;
        }

        $query = Account::query()->whereNotIn('sync_status', [Account::STATUS_NEEDS_REAUTH, Account::STATUS_DISCONNECTED]);

        if ($id = $this->option('account')) {
            $query->whereKey($id);
        }

        $mode = (string) $this->option('mode');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $sinceDays = $this->option('since-days') !== null ? (int) $this->option('since-days') : null;

        $accounts = $query->get();
        if ($accounts->isEmpty()) {
            $this->info('No accounts to sync.');

            return self::SUCCESS;
        }

        foreach ($accounts as $account) {
            if ($this->option('now')) {
                $this->line("Syncing {$account->email} ({$mode}) ...");
                try {
                    $stats = $providers->for($account)->sync($account, $mode, $limit, $sinceDays);
                    $this->info(sprintf('  %s: +%d added, %d updated, %d deleted', $stats['mode'], $stats['added'], $stats['updated'], $stats['deleted']));
                } catch (\Throwable $e) {
                    $this->error('  failed: '.$e->getMessage());
                }
            } else {
                if ($account->sync_status === Account::STATUS_IDLE || $account->sync_status === Account::STATUS_ERROR) {
                    $account->forceFill(['sync_status' => Account::STATUS_QUEUED])->save();
                }
                SyncAccountJob::dispatch($account->id, $mode, $limit, $sinceDays);
                $this->line("Queued {$account->email} ({$mode})");
            }
        }

        return self::SUCCESS;
    }
}
