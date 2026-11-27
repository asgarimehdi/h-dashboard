<?php

namespace App\Http\Resources;

use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Issue #1276 — the attachment payload carries an absolute, authenticated
 * download URL.
 *
 * Issue #1953 — `file_path` is gone from the payload. It exposed the private
 * disk layout (`attachments/<server-generated-name>`) and it has no client
 * value left: since #1276 the file lives on the private `local` disk and
 * `/storage/<file_path>` is a 404 (see
 * `TicketAttachmentDownloadTest::test_legacy_storage_url_is_not_served`), so a
 * client that built a URL from it was already broken. `url` is the only
 * supported download reference.
 *
 * The field list below is an explicit allowlist, not `...$model->toArray()`:
 * a column added to `attachments` later must be added here on purpose, or it
 * never reaches an API client (fail-closed, same rule as
 * `HrStatsController::personColumns()`, #1802).
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
            'id' => $model->id,
            'ticket_id' => $model->ticket_id,
            'user_id' => $model->user_id,
            'file_name' => $model->file_name,
            'file_size' => $model->file_size,
            'activity_id' => $model->activity_id,
            'created_at' => $model->created_at?->toISOString(),
            'updated_at' => $model->updated_at?->toISOString(),
            'url' => route('api.tickets.attachments.download', [
                'ticket' => $model->ticket_id,
                'attachment' => $model->id,
            ]),
        ];
    }
}
