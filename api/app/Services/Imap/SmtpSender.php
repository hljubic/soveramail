<?php

namespace App\Services\Imap;

use App\Models\Account;
use Symfony\Component\Mime\Email;

/** Delivers one message through the account's SMTP server. */
interface SmtpSender
{
    public function send(Account $account, Email $email): void;

    /** Connect + authenticate without sending (used when adding an account). */
    public function verify(array $smtp): void;
}
