<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Services\Mail\AttachmentService;
use Illuminate\Console\Command;

/**
 * Command-line twin of the "Remove account" action in the app. Gmail itself is
 * never touched; the choice is only whether the *local* copy stays on disk.
 */
class RemoveAccountCommand extends Command
{
    protected $signature = 'sovera:remove-account
        {email : The connected mailbox address}
        {--keep-mail : Only forget the Google grant; keep messages, attachments and drafts on disk}
        {--force : Actually do it; without it the command only reports}';

    protected $description = 'Disconnect a mailbox, optionally deleting its locally stored copy (Gmail is not touched)';

    public function handle(AttachmentService $attachments): int
    {
        $email = strtolower(trim((string) $this->argument('email')));

        /** @var Account|null $account */
        $account = Account::query()->where('email', $email)->first();
        if (! $account) {
            $this->error("No connected account with address {$email}.");

            return self::FAILURE;
        }

        $keep = (bool) $this->option('keep-mail');
        $messages = $account->messages()->count();
        $this->line("Account #{$account->id} {$account->email}: {$messages} locally stored message(s).");
        $this->line($keep
            ? 'Only the Google grant (OAuth tokens) is forgotten; the local mailbox copy stays and a later reconnect continues from it.'
            : 'Nothing is removed from Gmail. Only the copy on this computer (messages, attachments, drafts, index, OAuth tokens) goes away.');

        if (! $this->option('force')) {
            $this->warn('Dry run. Re-run with --force to do it.');

            return self::SUCCESS;
        }

        if ($keep) {
            $account->disconnect();
            $this->info("Disconnected {$email}; {$messages} message(s) kept locally.");

            return self::SUCCESS;
        }

        $typed = strtolower(trim((string) $this->ask("Type the address {$email} to confirm deleting the local copy")));
        if ($typed !== $email) {
            $this->error('Confirmation did not match. Nothing was removed.');

            return self::FAILURE;
        }

        $attachments->forgetAccount($account);
        $account->delete(); // FK cascades: messages, labels, contacts, drafts, embeddings, send receipts

        $this->info("Removed the local copy of {$email}.");

        return self::SUCCESS;
    }
}
