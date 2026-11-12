<?php

namespace App\Services\Imap;

use App\Services\Gmail\ParsedMessage;
use App\Services\Mail\Utf8;
use App\Support\AddressParser;
use Carbon\CarbonImmutable;
use Throwable;
use Webklex\PHPIMAP\Message as WebklexMessage;

/**
 * Raw RFC 822 bytes -> ParsedMessage. Headers are parsed here (unfolded and
 * RFC 2047-decoded); bodies and attachment parts come from webklex's MIME
 * walker. Attachments are referenced by MIME part number, so they can be
 * re-fetched later from the same message.
 */
class ImapMimeParser
{
    private const HEADER_MAX = 8192;

    /**
     * @param  string[]  $labelIds  Gmail-style labels the caller derived from folder + flags
     */
    public function parse(string $raw, string $providerMessageId, array $labelIds, ?string $internalDate = null, ?string $threadId = null): ParsedMessage
    {
        $headers = $this->headerMap($raw);

        $text = null;
        $html = null;
        $attachments = [];
        try {
            $m = WebklexMessage::fromString($raw);
            $text = trim((string) $m->getTextBody()) !== '' ? (string) $m->getTextBody() : null;
            $html = trim((string) $m->getHTMLBody()) !== '' ? (string) $m->getHTMLBody() : null;
            foreach ($m->getAttachments() as $a) {
                $disposition = strtolower((string) ($a->disposition ?? ''));
                $contentId = $a->id ? trim((string) $a->id, '<> ') : null;
                $attachments[] = [
                    'provider_attachment_id' => (string) $a->part_number,
                    'part_id' => (string) $a->part_number,
                    'filename' => $a->name !== null && $a->name !== '' ? mb_substr((string) $a->name, 0, 512) : null,
                    'mime_type' => $a->content_type ? mb_substr(strtolower((string) $a->content_type), 0, 191) : null,
                    'size' => (int) ($a->size ?? 0),
                    'content_id' => $contentId !== null && $contentId !== '' ? mb_substr($contentId, 0, 512) : null,
                    'is_inline' => $disposition === 'inline' || ($contentId !== null && $contentId !== '' && ($a->name === null || $a->name === '')),
                ];
            }
        } catch (Throwable $e) {
            // Unparseable MIME: keep the raw body as text so nothing is lost.
            $text = self::bodyOf($raw);
        }
        $text = $text !== null ? Utf8::fix($text) : null;
        $html = $html !== null ? Utf8::fix($html) : null;
        if ($text === null && $html !== null) {
            $text = trim(html_entity_decode(strip_tags(preg_replace('/<(br|\/p|\/div|\/tr|\/li)[^>]*>/i', "\n", $html) ?? $html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        $from = AddressParser::parseOne($headers['from'] ?? null);
        $sentAt = self::parseDate($headers['date'] ?? null);
        $receivedAt = self::parseDate($internalDate) ?? $sentAt ?? CarbonImmutable::now('UTC');

        $snippet = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($text ?? '')) ?? ''), 0, 200);

        return new ParsedMessage(
            providerMessageId: $providerMessageId,
            threadId: $threadId,
            historyId: null,
            messageIdHeader: self::trimOrNull($headers['message-id'] ?? null),
            inReplyTo: self::trimOrNull($headers['in-reply-to'] ?? null),
            references: self::trimOrNull($headers['references'] ?? null),
            subject: self::trimOrNull($headers['subject'] ?? null),
            fromName: $from['name'] ?? null,
            fromEmail: $from['email'] ?? null,
            to: AddressParser::parseList($headers['to'] ?? null),
            cc: AddressParser::parseList($headers['cc'] ?? null),
            bcc: AddressParser::parseList($headers['bcc'] ?? null),
            replyTo: AddressParser::parseList($headers['reply-to'] ?? null),
            snippet: $snippet !== '' ? $snippet : null,
            bodyText: $text,
            bodyHtml: $html,
            headers: $headers,
            labelIds: array_values(array_unique($labelIds)),
            sizeEstimate: strlen($raw),
            sentAt: $sentAt ?? $receivedAt,
            receivedAt: $receivedAt,
            attachments: $attachments,
        );
    }

    /** Unfolded, decoded headers keyed by lower-case name (last occurrence wins, except References/Received which are joined). */
    public function headerMap(string $raw): array
    {
        $head = preg_split("/\r?\n\r?\n/", $raw, 2)[0] ?? '';
        $head = preg_replace("/\r?\n[ \t]+/", ' ', $head) ?? $head; // unfold
        $map = [];
        foreach (preg_split("/\r?\n/", $head) ?: [] as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $name = strtolower(trim($name));
            // Before any mb_* call: those turn raw 8-bit bytes into "?" for good.
            $value = trim(self::decodeHeader(Utf8::fix(trim($value))));
            if ($name === '' || strlen($name) > 100) {
                continue;
            }
            $value = mb_substr($value, 0, self::HEADER_MAX);
            $map[$name] = isset($map[$name]) && in_array($name, ['references', 'received'], true) ? $map[$name].' '.$value : $value;
        }

        return $map;
    }

    /** RFC 2047 encoded words (=?UTF-8?B?...?=) to UTF-8. */
    public static function decodeHeader(string $value): string
    {
        if (! str_contains($value, '=?')) {
            return $value;
        }
        $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return $decoded !== false ? $decoded : $value;
    }

    private static function bodyOf(string $raw): ?string
    {
        $parts = preg_split("/\r?\n\r?\n/", $raw, 2);

        return isset($parts[1]) && trim($parts[1]) !== '' ? $parts[1] : null;
    }

    private static function parseDate(?string $value): ?CarbonImmutable
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $value = preg_replace('/\s*\([^)]*\)\s*$/', '', $value) ?? $value; // "(CEST)" comments
        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            $ts = strtotime($value);

            return $ts !== false ? CarbonImmutable::createFromTimestamp($ts, 'UTC') : null;
        }
    }

    private static function trimOrNull(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
