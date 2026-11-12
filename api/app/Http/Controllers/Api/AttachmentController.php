<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ReauthRequiredException;
use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Services\Mail\AttachmentService;
use App\Services\Mail\BlockedAttachmentException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AttachmentController extends Controller
{
    /**
     * Stream the attachment. `?download=1` forces a save dialog; otherwise the
     * browser may show it inline (PDF viewer, images, text) inside the app.
     */
    public function show(Request $request, Attachment $attachment, AttachmentService $attachments): Response
    {
        try {
            $path = $attachments->ensureLocal($attachment);
        } catch (BlockedAttachmentException $e) {
            return response()->json(['message' => $e->getMessage(), 'blocked' => true], 403);
        } catch (ReauthRequiredException $e) {
            return response()->json(['message' => $e->getMessage(), 'needs_reauth' => true], 409);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => $e->getMessage()], 502);
        }

        $filename = AttachmentService::safeFilename($attachment);
        $mime = $attachment->mime_type ?: 'application/octet-stream';
        $download = $request->boolean('download') || ! AttachmentService::isPreviewable($mime);

        // Never let the browser sniff an attachment into HTML/JS.
        if (! AttachmentService::isPreviewable($mime)) {
            $mime = 'application/octet-stream';
        }

        $response = new BinaryFileResponse($path, 200, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
            // Only our own UI may embed this (PDF/image preview inside the app).
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; script-src 'none'; frame-ancestors ".implode(' ', (array) config('cors.allowed_origins')),
        ]);

        $disposition = HeaderUtils::makeDisposition(
            $download ? HeaderUtils::DISPOSITION_ATTACHMENT : HeaderUtils::DISPOSITION_INLINE,
            $filename,
            preg_replace('/[^\x20-\x7e]/', '_', $filename) ?: 'attachment',
        );
        $response->headers->set('Content-Disposition', $disposition);

        return $response;
    }
}
