<?php

namespace App\Console\Commands;

use App\Jobs\ClassifyAccountJob;
use App\Models\Account;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Classify\ThreadClassifier;
use Illuminate\Console\Command;

class ClassifyMessagesCommand extends Command
{
    protected $signature = 'sovera:classify {--account= : Only this account id}';

    protected $description = 'Sort conversations into local categories and judge urgency of unread mail (local model)';

    public function handle(): int
    {
        if (! ThreadClassifier::isActive(AiSettings::load())) {
            $this->warn('No classification model or no categories yet.');

            return self::SUCCESS;
        }

        $query = Account::query();
        if ($id = $this->option('account')) {
            $query->whereKey($id);
        }
        foreach ($query->get() as $account) {
            if (! $account->isDisconnected()) {
                ClassifyAccountJob::dispatch($account->id);
                $this->line("Queued {$account->email}");
            }
        }

        return self::SUCCESS;
    }
}
