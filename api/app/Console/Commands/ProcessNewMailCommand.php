<?php

namespace App\Console\Commands;

use App\Jobs\AiPassJob;
use App\Models\Account;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Processing\AiPass;
use Illuminate\Console\Command;

class ProcessNewMailCommand extends Command
{
    protected $signature = 'sovera:process {--account= : Only this account id}';

    protected $description = 'Process new mail with the local AI in one pass (category, urgency, summaries, style, knowledge, index)';

    public function handle(): int
    {
        $settings = AiSettings::load();
        if (! $settings->isReady()) {
            $this->warn('No local chat model configured.');

            return self::SUCCESS;
        }
        $query = Account::query();
        if ($id = $this->option('account')) {
            $query->whereKey($id);
        }
        foreach ($query->get() as $account) {
            if ($account->isDisconnected() || AiPass::running($account->id)) {
                continue;
            }
            AiPass::put($account->id, ['state' => 'queued', 'error' => null]);
            AiPassJob::dispatch($account->id, 'threads');
            $this->line("Queued {$account->email}");
        }

        return self::SUCCESS;
    }
}
