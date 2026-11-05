<?php

namespace App\Http\Controllers;

use App\Services\ActivityLogService;
use App\Services\Backup\BackupService;
use App\Support\Backup\TableRegistry;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Issue #1375 — the backup downloads.
 *
 * A download is a plain GET: Livewire cannot return a file, so the button on
 * the settings page is an `<a href>` and the file comes from here.
 *
 * The routes sit in their own `auth` + `role:admin` group, OUTSIDE the
 * `unit_context` group: a backup covers a WHOLE table, not the rows of one
 * unit, so the unit context must not gate it. The role is checked again here
 * — a controller action does not lean on the route alone.
 */
class BackupController extends Controller
{
    /**
     * A backup holds the whole table, so it must not be cached by the
     * browser or by a proxy in between.
     */
    private const DOWNLOAD_HEADERS = [
        'Cache-Control' => 'private, no-store, max-age=0',
        'X-Content-Type-Options' => 'nosniff',
    ];

    public function __construct(
        private readonly BackupService $backups,
        private readonly TableRegistry $registry,
    ) {}

    /**
     * Back up ONE table.
     */
    public function table(Request $request, string $table): BinaryFileResponse
    {
        $this->assertAdmin($request);

        // A table that is not part of the registry is not downloadable — the
        // system tables and the Spatie tables stay out of every backup.
        abort_unless($this->registry->isRegistered($table), 404);

        $archive = $this->backups->exportTable($table);

        ActivityLogService::log(
            'backup',
            null,
            "بک‌آپ جدول «{$table}» تهیه شد: {$archive->rowCount()} ردیف."
        );

        return response()->download($archive->path, $archive->filename, self::DOWNLOAD_HEADERS);
    }

    /**
     * Back up EVERY registered table.
     */
    public function full(Request $request): BinaryFileResponse
    {
        $this->assertAdmin($request);

        $archive = $this->backups->exportAll();

        ActivityLogService::log(
            'backup',
            null,
            "بک‌آپ کامل تهیه شد: {$archive->rowCount()} ردیف در ۳۲ جدول."
        );

        return response()->download($archive->path, $archive->filename, self::DOWNLOAD_HEADERS);
    }

    /**
     * The admin role, re-checked at the moment of the download.
     */
    private function assertAdmin(Request $request): void
    {
        abort_unless($request->user()?->hasRole('admin'), 403, 'شما دسترسی به بک‌آپ ندارید.');
    }
}
