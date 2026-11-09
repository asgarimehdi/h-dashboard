<?php

namespace App\Http\Resources;

use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Issue #1276 — the attachment payload carries an absolute, authenticated
 * download URL.
 *
 * Every field the old raw-model serialisation exposed stays exactly as it was
 * (`...$model->toArray()`), so nothing an installed client reads disappears.
 * `file_path` is DEPRECATED — it still points at a path that is no longer
 * publicly served, and it is kept only until the app release that reads `url`
 * is rolled out; it must not be rebuilt into a `/storage/...` URL any more.
 *
 * The URL deliberately targets the API route: the web route sits behind
 * `unit_context`, which redirects a sessionless (token-only) request to
 * `/select-context` before it ever reaches the file.
 *
 * @property-read int $id
 * @property-read int|null $ticket_id
 */
class AttachmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Attachment $model */
        $model = $this->resource;

        return [
            ...$model->toArray(),
            'url' => route('api.tickets.attachments.download', [
                'ticket' => $model->ticket_id,
                'attachment' => $model->id,
            ]),
        ];
    }
}
