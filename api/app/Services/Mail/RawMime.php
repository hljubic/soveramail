<?php

namespace App\Services\Mail;

use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\TextPart;

/**
 * The raw RFC 822 text handed to APIs that take a finished message (Gmail
 * messages.send, IMAP APPEND).
 *
 * Symfony's Email::toString() strips Bcc because an SMTP transport puts those
 * recipients on the envelope instead. Gmail has no envelope: it reads
 * the recipients from the headers (and removes Bcc before delivery itself), so
 * without the header every Bcc recipient was silently dropped. The Sent copy in an
 * IMAP folder keeps it too, like every other mail client does.
 */
final class RawMime
{
    public static function withBcc(Email $email): string
    {
        $body = $email->getBody() ?? new TextPart('');
        $headers = $email->getPreparedHeaders();
        if ($bcc = $email->getBcc()) {
            $headers->addMailboxListHeader('Bcc', $bcc);
        }

        return $headers->toString().$body->toString();
    }
}
