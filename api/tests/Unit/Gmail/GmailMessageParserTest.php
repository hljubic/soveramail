<?php

namespace Tests\Unit\Gmail;

use App\Services\Gmail\GmailMessageParser;
use Google\Service\Gmail\Message;
use PHPUnit\Framework\TestCase;

class GmailMessageParserTest extends TestCase
{
    private function b64url(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private function message(array $payload, array $extra = []): Message
    {
        return new Message(array_merge([
            'id' => 'abc123',
            'threadId' => 'thr1',
            'historyId' => '4242',
            'internalDate' => '1725789600000', // 2024-09-08T10:00:00Z
            'snippet' => 'Hello &amp; welcome',
            'sizeEstimate' => 1234,
            'labelIds' => ['INBOX', 'UNREAD', 'STARRED'],
            'payload' => $payload,
        ], $extra));
    }

    public function test_parses_multipart_alternative_with_attachment(): void
    {
        $payload = [
            'mimeType' => 'multipart/mixed',
            'headers' => [
                ['name' => 'From', 'value' => '"Ivan Horvat" <ivan@example.com>'],
                ['name' => 'To', 'value' => 'Ana <ana@example.com>, pero@example.com'],
                ['name' => 'Cc', 'value' => '"Last, First" <lf@example.com>'],
                ['name' => 'Subject', 'value' => 'Test poruka'],
                ['name' => 'Date', 'value' => 'Sun, 8 Sep 2024 12:00:00 +0200 (CEST)'],
                ['name' => 'Message-ID', 'value' => '<m1@example.com>'],
                ['name' => 'Received', 'value' => 'should be ignored'],
            ],
            'parts' => [
                [
                    'mimeType' => 'multipart/alternative',
                    'parts' => [
                        ['partId' => '0.0', 'mimeType' => 'text/plain', 'body' => ['data' => $this->b64url("Bok Ana,\nOvo je test.")]],
                        ['partId' => '0.1', 'mimeType' => 'text/html', 'body' => ['data' => $this->b64url('<p>Bok <b>Ana</b>,</p><p>Ovo je test.</p>')]],
                    ],
                ],
                [
                    'partId' => '1',
                    'mimeType' => 'application/pdf',
                    'filename' => 'ponuda.pdf',
                    'headers' => [['name' => 'Content-Disposition', 'value' => 'attachment; filename="ponuda.pdf"']],
                    'body' => ['attachmentId' => 'ATT-1', 'size' => 9876],
                ],
            ],
        ];

        $parsed = (new GmailMessageParser)->parse($this->message($payload));

        $this->assertSame('abc123', $parsed->providerMessageId);
        $this->assertSame('thr1', $parsed->threadId);
        $this->assertSame('Test poruka', $parsed->subject);
        $this->assertSame('Ivan Horvat', $parsed->fromName);
        $this->assertSame('ivan@example.com', $parsed->fromEmail);
        $this->assertSame([
            ['name' => 'Ana', 'email' => 'ana@example.com'],
            ['name' => null, 'email' => 'pero@example.com'],
        ], $parsed->to);
        $this->assertSame([['name' => 'Last, First', 'email' => 'lf@example.com']], $parsed->cc);
        $this->assertSame("Bok Ana,\nOvo je test.", $parsed->bodyText);
        $this->assertStringContainsString('<b>Ana</b>', $parsed->bodyHtml);
        $this->assertSame('Hello & welcome', $parsed->snippet);
        $this->assertSame('<m1@example.com>', $parsed->messageIdHeader);
        $this->assertArrayNotHasKey('received', $parsed->headers);
        $this->assertTrue($parsed->isUnread());
        $this->assertTrue($parsed->isStarred());
        $this->assertTrue($parsed->hasAttachments());
        $this->assertCount(1, $parsed->attachments);
        $this->assertSame('ponuda.pdf', $parsed->attachments[0]['filename']);
        $this->assertSame('ATT-1', $parsed->attachments[0]['provider_attachment_id']);
        $this->assertFalse($parsed->attachments[0]['is_inline']);
        $this->assertSame('2024-09-08T10:00:00+00:00', $parsed->sentAt?->toIso8601String());
        $this->assertSame('2024-09-08T10:00:00+00:00', $parsed->receivedAt->toIso8601String());
    }

    public function test_html_only_message_gets_text_fallback_and_charset_conversion(): void
    {
        $latin2 = mb_convert_encoding('<p>Šišmiš &amp; čaša</p>', 'ISO-8859-2', 'UTF-8');
        $payload = [
            'mimeType' => 'text/html',
            'headers' => [
                ['name' => 'Content-Type', 'value' => 'text/html; charset="iso-8859-2"'],
                ['name' => 'From', 'value' => 'noreply@example.com'],
            ],
            'body' => ['data' => $this->b64url($latin2)],
        ];

        $parsed = (new GmailMessageParser)->parse($this->message($payload, ['labelIds' => []]));

        $this->assertSame('Šišmiš & čaša', $parsed->bodyText);
        $this->assertStringContainsString('Šišmiš', $parsed->bodyHtml);
        $this->assertNull($parsed->fromName);
        $this->assertSame('noreply@example.com', $parsed->fromEmail);
        $this->assertFalse($parsed->isUnread());
        $this->assertFalse($parsed->hasAttachments());
    }

    public function test_inline_image_is_marked_inline_and_does_not_count_as_attachment(): void
    {
        $payload = [
            'mimeType' => 'multipart/related',
            'headers' => [['name' => 'From', 'value' => 'a@b.c']],
            'parts' => [
                ['partId' => '0', 'mimeType' => 'text/html', 'body' => ['data' => $this->b64url('<img src="cid:logo">')]],
                [
                    'partId' => '1',
                    'mimeType' => 'image/png',
                    'filename' => 'logo.png',
                    'headers' => [
                        ['name' => 'Content-ID', 'value' => '<logo>'],
                        ['name' => 'Content-Disposition', 'value' => 'inline; filename="logo.png"'],
                    ],
                    'body' => ['attachmentId' => 'ATT-2', 'size' => 100],
                ],
            ],
        ];

        $parsed = (new GmailMessageParser)->parse($this->message($payload));

        $this->assertCount(1, $parsed->attachments);
        $this->assertTrue($parsed->attachments[0]['is_inline']);
        $this->assertSame('logo', $parsed->attachments[0]['content_id']);
        $this->assertFalse($parsed->hasAttachments());
    }
}
