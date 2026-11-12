<?php

namespace App\Services\Mail;

use App\Models\Account;
use App\Models\Attachment;
use App\Services\Mail\Providers\MailProviders;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Fetches attachment bytes from the provider on first access and keeps a local copy
 * (storage/app/private/attachments/<account>/<message>/<attachment>).
 */
class AttachmentService
{
    public const DISK = 'local';

    public function __construct(private readonly MailProviders $providers) {}

    public function localPath(Attachment $attachment): string
    {
        $message = $attachment->message;

        return "attachments/{$message->account_id}/{$message->id}/{$attachment->id}";
    }

    /**
     * Drop the cached attachment files of one account (used when its local copy is deleted).
     */
    public function forgetAccount(Account $account): void
    {
        Storage::disk(self::DISK)->deleteDirectory("attachments/{$account->id}");
    }

    /**
     * Absolute filesystem path of the cached file, downloading it if needed.
     */
    public function ensureLocal(Attachment $attachment): string
    {
        if ($attachment->isBlocked()) {
            throw new BlockedAttachmentException('This attachment is blocked: '.$attachment->risk_reason);
        }

        $disk = Storage::disk(self::DISK);
        $path = $this->localPath($attachment);

        if (! $disk->exists($path)) {
            $message = $attachment->message;
            if (! $attachment->provider_attachment_id) {
                throw new RuntimeException('This attachment has no downloadable content.');
            }

            // Only ever reached from an explicit user action (preview, download, forward).
            $bytes = $this->providers->for($message->account)->fetchAttachment($message->account, $message, $attachment);
            if ($bytes === null) {
                throw new RuntimeException('Gmail returned no data for this attachment.');
            }

            $disk->put($path, $bytes);

            // Now that we have the bytes: is it really what the mail claimed?
            if ($reason = AttachmentRisk::sniff($disk->path($path), $attachment->filename, $attachment->mime_type)) {
                $disk->delete($path);
                $attachment->forceFill(['risk_level' => AttachmentRisk::DANGER, 'risk_reason' => $reason])->save();
                $message->forceFill(['is_risky' => true])->save();
                throw new BlockedAttachmentException('This attachment is blocked: '.$reason);
            }
        }

        return $disk->path($path);
    }

    /**
     * A safe, ASCII-only filename for Content-Disposition (RFC 6266 also gets the UTF-8 one).
     */
    public static function safeFilename(Attachment $attachment): string
    {
        $name = trim((string) ($attachment->filename ?: 'attachment'));
        $name = str_replace(["\r", "\n", '"', '\\', '/'], '_', $name);
        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'attachment';
        }

        return mb_substr($name, 0, 200);
    }

    /**
     * Mime types the UI may show inline (everything else is download-only).
     */
    public static function isPreviewable(?string $mime): bool
    {
        $mime = strtolower((string) $mime);

        return $mime === 'application/pdf'
            || str_starts_with($mime, 'image/')
            || in_array($mime, ['text/plain', 'text/csv', 'text/markdown'], true);
    }
}
