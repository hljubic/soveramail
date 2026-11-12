<?php

namespace App\Console\Commands;

use App\Jobs\EmbedAccountJob;
use App\Models\Account;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Retrieval\EmbeddingService;
use Illuminate\Console\Command;

class EmbedMessagesCommand extends Command
{
    protected $signature = 'sovera:embed {--account= : Only this account id} {--now : Run synchronously}';

    protected $description = 'Build / update the local vector index of synced messages';

    public function handle(EmbeddingService $embeddings): int
    {
        if (! AiSettings::load()->canEmbed()) {
            $this->warn('No embedding model configured (Configure AI).');

            return self::SUCCESS;
        }

        $query = Account::query();
        if ($id = $this->option('account')) {
            $query->whereKey($id);
        }

        foreach ($query->get() as $account) {
            if ($this->option('now')) {
                $this->line("Indexing {$account->email} ...");
                $result = $embeddings->indexAccount($account);
                $this->info("  embedded {$result['embedded']}, remaining {$result['remaining']}");
            } else {
                EmbedAccountJob::dispatch($account->id);
                $this->line("Queued {$account->email}");
            }
        }

        return self::SUCCESS;
    }
}
