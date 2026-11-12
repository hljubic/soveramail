<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Services\Mail\ContactBook;
use Illuminate\Console\Command;

class RebuildContactsCommand extends Command
{
    protected $signature = 'sovera:contacts-rebuild {--account= : Only this account id}';

    protected $description = 'Rebuild the per-account address book from locally stored messages';

    public function handle(ContactBook $book): int
    {
        $query = Account::query();
        if ($id = $this->option('account')) {
            $query->whereKey($id);
        }

        foreach ($query->get() as $account) {
            $n = $book->rebuild($account);
            $this->info("{$account->email}: scanned {$n} messages");
        }

        return self::SUCCESS;
    }
}
