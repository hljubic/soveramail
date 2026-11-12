<?php

namespace App\Services\Gmail;

use App\Support\AddressParser;
use Carbon\CarbonImmutable;
use Google\Service\Gmail\Message as GmailMessage;
use Google\Service\Gmail\MessagePart;
use Throwable;

/**
 * Turns a Gmail API `Message` (format=full) into a ParsedMessage.
 */
class GmailMessageParser
{
    private const HEADER_MAX = 8192;

    public function parse(GmailMessage $message): ParsedMessage
    {
        $payload = $message->getPayload();
        $headers = $payload ? $this->headerMap($payload) : [];

        $text = null;
        $html = null;
        $attachments = [];

        if ($payload) {
            $this->walk($payload, $text, $html, $attachments);
        }

        // Single-part messages whose only part is the payload itself.
        if ($text === null && $html === null && $payload && $payload->getBody() && $payload->getBody()->getData()) {
            $decoded = $this->decodeBody($payload);
            if ($decoded !== null) {
                if (str_starts_with(strtolower((string) $payload->getMimeType()), 'text/html')) {
                    $html = $decoded;
                } else {
                    $text = $decoded;
                }
            }
        }

        if ($text === null && $html !== null) {
            $text = $this->htmlToText($html);
        }

        $from = AddressParser::parseOne($headers['from'] ?? null);

        $internalMs = (int) ($message->getInternalDate() ?? 0);
        $receivedAt = $internalMs > 0
            ? CarbonImmutable::createFromTimestampMs($internalMs, 'UTC')
            : CarbonImmutable::now('UTC');

        $sentAt = $this->parseDate($headers['date'] ?? null) ?? $receivedAt;

        return new ParsedMessage(
            providerMessageId: (string) $message->getId(),
            threadId: $message->getThreadId() ?: null,
            historyId: $message->getHistoryId() !== null ? (string) $message->getHistoryId() : null,
            messageIdHeader: $this->trimOrNull($headers['message-id'] ?? null),
            inReplyTo: $this->trimOrNull($headers['in-reply-to'] ?? null),
            references: $this->trimOrNull($headers['references'] ?? null),
            subject: $this->trimOrNull($headers['subject'] ?? null),
            fromName: $from['name'] ?? null,
            fromEmail: $from['email'] ?? null,
            to: AddressParser::parseList($headers['to'] ?? null),
            cc: AddressParser::parseList($headers['cc'] ?? null),
            bcc: AddressParser::parseList($headers['bcc'] ?? null),
            replyTo: AddressParser::parseList($headers['reply-to'] ?? null),
            snippet: $this->trimOrNull(html_entity_decode((string) $message->getSnippet(), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
            bodyText: $text,
            bodyHtml: $html,
            headers: $headers,
            labelIds: array_values(array_map('strval', $message->getLabelIds() ?? [])),
            sizeEstimate: (int) ($message->getSizeEstimate() ?? 0),
            sentAt: $sentAt,
            receivedAt: $receivedAt,
            attachments: $attachments,
        );
    }

    /**
     * Depth-first walk over MIME parts collecting first text/plain, first
     * text/html and any attachments.
     */
    private function walk(MessagePart $part, ?string &$text, ?string &$html, array &$attachments, int $depth = 0): void
    {
        if ($depth > 32) {
            return;
        }

        $mime = strtolower((string) $part->getMimeType());
        $filename = (string) $part->getFilename();
        $body = $part->getBody();
        $partHeaders = $this->headerMap($part);
        $disposition = strtolower((string) ($partHeaders['content-disposition'] ?? ''));
        $contentId = isset($partHeaders['content-id']) ? trim($partHeaders['content-id'], '<> ') : null;

        $isAttachment = $filename !== ''
            || ($body && $body->getAttachmentId())
            || str_starts_with($disposition, 'attachment');

        if ($isAttachment) {
            $attachments[] = [
                'provider_attachment_id' => $body?->getAttachmentId(),
                'part_id' => $part->getPartId() !== null ? (string) $part->getPartId() : null,
                'filename' => $filename !== '' ? mb_substr($filename, 0, 512) : null,
                'mime_type' => $mime !== '' ? mb_substr($mime, 0, 191) : null,
                'size' => (int) ($body?->getSize() ?? 0),
                'content_id' => $contentId !== null && $contentId !== '' ? mb_substr($contentId, 0, 512) : null,
                'is_inline' => str_starts_with($disposition, 'inline') || ($contentId !== null && $contentId !== '' && $filename === ''),
            ];

            return;
        }

        if (str_starts_with($mime, 'multipart/')) {
            foreach ($part->getParts() ?? [] as $child) {
                $this->walk($child, $text, $html, $attachments, $depth + 1);
            }

            return;
        }

        if ($mime === 'text/plain' && $text === null) {
            $text = $this->decodeBody($part);

            return;
        }

        if ($mime === 'text/html' && $html === null) {
            $html = $this->decodeBody($part);

            return;
        }

        // Nested message/rfc822 etc. can still carry parts.
        foreach ($part->getParts() ?? [] as $child) {
            $this->walk($child, $text, $html, $attachments, $depth + 1);
        }
    }

    private function decodeBody(MessagePart $part): ?string
    {
        $data = $part->getBody()?->getData();
        if ($data === null || $data === '') {
            return null;
        }

        $raw = base64_decode(strtr($data, '-_', '+/'), false);
        if ($raw === false) {
            return null;
        }

        $charset = $this->charsetOf($part);
        if ($charset !== null && ! in_array($charset, ['utf-8', 'utf8', 'us-ascii', 'ascii'], true)) {
            try {
                $converted = @mb_convert_encoding($raw, 'UTF-8', $charset);
                if (is_string($converted) && $converted !== '') {
                    $raw = $converted;
                }
            } catch (Throwable) {
                // keep raw
            }
        }

        if (! mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'UTF-8');
        }

        return $raw;
    }

    private function charsetOf(MessagePart $part): ?string
    {
        foreach ($part->getHeaders() ?? [] as $h) {
            if (strcasecmp((string) $h->getName(), 'Content-Type') === 0
                && preg_match('/charset\s*=\s*"?([A-Za-z0-9._-]+)"?/i', (string) $h->getValue(), $m)) {
                return strtolower($m[1]);
            }
        }

        return null;
    }

    /**
     * @return array<string, string> lower-cased header name => value (first occurrence wins,
     *                               except Received which is ignored to keep the JSON small)
     */
    private function headerMap(MessagePart $part): array
    {
        $map = [];
        foreach ($part->getHeaders() ?? [] as $h) {
            $name = strtolower(trim((string) $h->getName()));
            if ($name === '' || $name === 'received' || isset($map[$name])) {
                continue;
            }
            $map[$name] = mb_substr((string) $h->getValue(), 0, self::HEADER_MAX);
        }

        return $map;
    }

    private function parseDate(?string $value): ?CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        // Strip "(CEST)" style comments that trip up strtotime.
        $clean = trim((string) preg_replace('/\s*\([^)]*\)\s*$/', '', $value));

        try {
            return CarbonImmutable::parse($clean)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private function htmlToText(string $html): string
    {
        $html = preg_replace('#<(script|style|head)[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        $html = preg_replace('#</(p|div|tr|li|h[1-6]|blockquote|pre|table)>#i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t\x{00A0}]+/u", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function trimOrNull(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $v = trim($v);

        return $v === '' ? null : $v;
    }
}
