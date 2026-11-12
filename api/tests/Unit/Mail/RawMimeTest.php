<?php

namespace Tests\Unit\Mail;

use App\Services\Mail\RawMime;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\Email;

class RawMimeTest extends TestCase
{
    public function test_bcc_survives_for_apis_without_an_envelope(): void
    {
        $email = (new Email)->from('me@example.com')->to('ana@example.com')->bcc('sef@example.com')->subject('x')->text('y');

        $this->assertStringNotContainsString('sef@example.com', $email->toString()); // what Gmail used to get
        $raw = RawMime::withBcc($email);
        $this->assertMatchesRegularExpression('/^Bcc: sef@example.com\r$/m', $raw);
        $this->assertStringContainsString("\r\n\r\ny", $raw);
    }

    public function test_without_bcc_it_is_the_normal_message(): void
    {
        $email = (new Email)->from('me@example.com')->to('ana@example.com')->subject('x')->text('y');
        $email->getHeaders()->addIdHeader('Message-ID', 'fixed@example.com');
        $email->date(new \DateTimeImmutable('2026-01-01 10:00:00'));

        $this->assertStringNotContainsString('Bcc:', RawMime::withBcc($email));
        $this->assertStringContainsString('Message-ID: <fixed@example.com>', RawMime::withBcc($email));
    }
}
