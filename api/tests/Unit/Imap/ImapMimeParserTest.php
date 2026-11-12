<?php

namespace Tests\Unit\Imap;

use App\Services\Imap\ImapMimeParser;
use PHPUnit\Framework\TestCase;

class ImapMimeParserTest extends TestCase
{
    public function test_headers_bodies_and_attachments_are_extracted(): void
    {
        $raw = "From: =?UTF-8?B?xIxlZG8gxIxvcGnEhw==?= <cedo@example.com>\r\nTo: Me <me@example.com>, ana@example.com\r\n"
            ."Subject: =?UTF-8?Q?Ra=C4=8Dun_za_rujan?=\r\nMessage-ID: <m1@example.com>\r\nIn-Reply-To: <m0@example.com>\r\n"
            ."References: <root@example.com>\r\n <m0@example.com>\r\nDate: Thu, 10 Sep 2026 10:00:00 +0200 (CEST)\r\nMIME-Version: 1.0\r\n"
            ."Content-Type: multipart/mixed; boundary=\"B\"\r\n\r\n--B\r\nContent-Type: multipart/alternative; boundary=\"A\"\r\n\r\n"
            ."--A\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nPozdrav, u privitku.\r\n--A\r\nContent-Type: text/html; charset=utf-8\r\n\r\n<p>Pozdrav, <b>u privitku</b>.</p>\r\n--A--\r\n"
            ."--B\r\nContent-Type: application/pdf; name=\"racun.pdf\"\r\nContent-Disposition: attachment; filename=\"racun.pdf\"\r\nContent-Transfer-Encoding: base64\r\n\r\n".base64_encode('%PDF-1.7 x')."\r\n--B--\r\n";

        $m = (new ImapMimeParser)->parse($raw, 'INBOX::1::7', ['INBOX', 'UNREAD'], '10-Sep-2026 08:01:00 +0000', 'thr');

        $this->assertSame('INBOX::1::7', $m->providerMessageId);
        $this->assertSame('thr', $m->threadId);
        $this->assertSame('Račun za rujan', $m->subject);
        $this->assertSame('cedo@example.com', $m->fromEmail);
        $this->assertSame('Čedo Čopić', $m->fromName);
        $this->assertSame(['me@example.com', 'ana@example.com'], array_column($m->to, 'email'));
        $this->assertSame('<m1@example.com>', $m->messageIdHeader);
        $this->assertSame('<m0@example.com>', $m->inReplyTo);
        $this->assertSame('<root@example.com> <m0@example.com>', $m->references);   // unfolded
        $this->assertSame('2026-09-10 08:00:00', $m->sentAt->format('Y-m-d H:i:s'));  // Date header, in UTC
        $this->assertSame('2026-09-10 08:01:00', $m->receivedAt->format('Y-m-d H:i:s')); // INTERNALDATE
        $this->assertSame('Pozdrav, u privitku.', trim($m->bodyText));
        $this->assertStringContainsString('<b>u privitku</b>', $m->bodyHtml);
        $this->assertTrue($m->isUnread());
        $this->assertCount(1, $m->attachments);
        $this->assertSame(['racun.pdf', 'application/pdf', false], [$m->attachments[0]['filename'], $m->attachments[0]['mime_type'], $m->attachments[0]['is_inline']]);
        $this->assertNotSame('', $m->attachments[0]['provider_attachment_id']);
    }

    public function test_html_only_mail_gets_a_text_version_and_broken_mime_keeps_the_raw_body(): void
    {
        $html = "From: a@b.c\r\nTo: me@b.c\r\nSubject: Hi\r\nContent-Type: text/html; charset=utf-8\r\n\r\n<div>Line one<br>Line <i>two</i></div>";
        $m = (new ImapMimeParser)->parse($html, 'INBOX::1::1', ['INBOX']);
        $this->assertStringContainsString("Line one\nLine two", $m->bodyText);
        $this->assertNull($m->threadId);

        $m2 = (new ImapMimeParser)->parse("Subject: only\r\n\r\njust text", 'INBOX::1::2', ['INBOX']);
        $this->assertSame('only', $m2->subject);
        $this->assertSame('just text', trim((string) $m2->bodyText));
    }
}
