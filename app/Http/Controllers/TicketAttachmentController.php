<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\Ticket;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Issue #1276 — ticket attachments live on the private `local` disk and are
 * only ever served through this authenticated, unit-scoped route.
 *
 * The same controller serves the web route (session cookie) and the API route
 * (Sanctum token). `/api/*` is a separate middleware group, so `SecurityHeaders`
 * does not run there — the download headers are set right here instead.
 */
class TicketAttachmentController extends Controller
{
    use AuthorizesRequests;

    /**
     * Extensions safe to render inside the browser. Everything else — zip,
     * rar, docx, xlsx, anything unknown — is forced to download.
     */
    private const INLINE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'pdf'];

    /**
     * Browser-side caching is deliberately off: these are hospital documents,
     * and a cached copy outlives the unit scope that allowed the download.
     */
    private const DOWNLOAD_HEADERS = [
        'Cache-Control' => 'private, no-store, max-age=0',
        'X-Content-Type-Options' => 'nosniff',
    ];

    public function show(Ticket $ticket, Attachment $attachment): StreamedResponse
    {
        // Nested binding: an attachment of another ticket is not addressable
        // under this ticket at all.
        abort_unless((int) $attachment->ticket_id === (int) $ticket->id, 404);

        // Unit scope, re-checked at download time (not when the link was made).
        $this->authorize('view', $attachment);

        $path = ltrim((string) $attachment->file_path, '/');
        $disk = $this->localDisk();

        // A row whose file is gone must 404, never 500.
        abort_if($path === '' || ! $disk->exists($path), 404);

        $name = $attachment->file_name ?: basename($path);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        // `response()`/`download()` set `Content-Type` from the file on disk
        // (`mimeType()`), never from anything the uploader supplied.
        if (in_array($extension, self::INLINE_EXTENSIONS, true)) {
            return $disk->response($path, $name, self::DOWNLOAD_HEADERS);
        }

        return $disk->download($path, $name, self::DOWNLOAD_HEADERS);
    }

    /**
     * `Storage::disk()` is typed as the filesystem contract, which does not
     * declare `response()`/`download()`. The app's `local` disk is a local
     * driver, so it always resolves to the adapter; the guard turns a config
     * change into a clear error instead of a fatal "method not found".
     */
    private function localDisk(): FilesystemAdapter
    {
        $disk = Storage::disk('local');

        if (! $disk instanceof FilesystemAdapter) {
            throw new RuntimeException('The [local] disk must resolve to a FilesystemAdapter.');
        }

        return $disk;
    }
}
