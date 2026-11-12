<?php

namespace App\Console\Commands;

use App\Jobs\BuildKnowledgeJob;
use App\Models\Account;
use App\Services\Ai\AiSettings;
use Illuminate\Console\Command;

class BuildKnowledgeCommand extends Command
{
    protected $signature = 'sovera:knowledge {--account= : Only this account id}';

    protected $description = 'Build what is missing of what the local AI knows about the user (about, area guides, recurring answers, contact cards)';

    public function handle(): int
    {
        if (! AiSettings::load()->isReady()) {
            $this->warn('No local chat model configured.');

            return self::SUCCESS;
        }
        $query = Account::query();
        if ($id = $this->option('account')) {
            $query->whereKey($id);
        }
        foreach ($query->get() as $account) {
            if (! $account->isDisconnected()) {
                BuildKnowledgeJob::dispatch($account->id);
                $this->line("Queued {$account->email}");
            }
        }

        return self::SUCCESS;
    }
}
