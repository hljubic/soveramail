<?php

namespace Tests\Support;

use App\Models\Account;
use App\Services\Imap\SmtpSender;
use Symfony\Component\Mime\Email;
use Throwable;

class FakeSmtpSender implements SmtpSender
{
    /** @var Email[] */
    public array $sent = [];

    public array $verified = [];

    public ?Throwable $failWith = null;

    public function send(Account $account, Email $email): void
    {
        if ($this->failWith) {
            throw $this->failWith;
        }
        $this->sent[] = $email;
    }

    public function verify(array $smtp): void
    {
        if ($this->failWith) {
            throw $this->failWith;
        }
        $this->verified[] = $smtp;
    }
}
