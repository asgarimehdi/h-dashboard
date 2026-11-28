<?php

namespace App\Jobs\Concerns;

use App\Models\User;
use App\Services\AccessService;
use App\Support\ImportStatus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * #1639: a queued import carries the actor's scope and permission snapshot
 * from dispatch time. A worker has no session and no auth user, so the
 * snapshot must be re-validated against the database before it writes.
 *
 * #1972: the re-check resolves the actor's scope through the shared
 * `AccessService::freshAccessibleUnitIds()` entry point, which is
 * session-free by construction (no session, no memo, no `accessible_units:`
 * cache). The web path and the worker path therefore use one base-unit rule,
 * and a test that still holds the dispatch-time `current_unit_id` in its
 * session cannot mask a worker that would see an empty scope in production.
 */
trait RevalidatesImportActor
{
    /** @var array<string, string> shown after «ایمپورت ناموفق بود: …» */
    private const IMPORT_ABORT_MESSAGES = [
        'user_missing' => 'حساب کاربری شما دیگر در دسترس نیست.',
        'permission_revoked' => 'مجوز شما برای انجام این ایمپورت لغو شده است.',
        'scope_empty' => 'دسترسی شما به واحدهای این فایل وجود ندارد.',
        'scope_narrowed' => 'دسترسی شما به بخشی از واحدهای این فایل تغییر کرده است؛ هیچ ردیفی اعمال نشد. فایل را دوباره بارگذاری کنید.',
    ];

    /**
     * @param  array<int, int>  $capturedUnitIds
     * @return array<int, int>|null null = refused and already cleaned up
     */
    private function authorizedImportScope(
        string $permission,
        ?int $userId,
        array $capturedUnitIds,
        string $path,
        ?string $statusToken,
    ): ?array {
        $captured = array_values(array_unique(array_map('intval', $capturedUnitIds)));
        $reason = null;
        $scope = [];

        $user = $userId === null ? null : User::find($userId);

        if ($user === null) {
            $reason = 'user_missing';
        } elseif (! $user->can($permission)) {
            $reason = 'permission_revoked';
        } elseif ($captured === []) {
            $reason = 'scope_empty';
        } else {
            $scope = array_values(array_intersect($captured, app(AccessService::class)->freshAccessibleUnitIds($user)));

            if ($scope === []) {
                $reason = 'scope_empty';
            } elseif (count($scope) !== count($captured)) {
                $reason = 'scope_narrowed';
            }
        }

        if ($reason === null) {
            return $scope;
        }

        $this->abortUnauthorizedImport($reason, $userId, $statusToken, $path);

        return null;
    }

    private function abortUnauthorizedImport(string $reason, ?int $userId, ?string $statusToken, string $path): void
    {
        // #1759: this refusal happens before the sheet is read — no row was
        // written and the UI asks for a re-upload — so it is deleted at once,
        // unlike a failed run, which keeps the sheet for diagnosis.
        Storage::delete($path);

        if ($userId !== null) {
            if ($statusToken !== null) {
                ImportStatus::publishFailure(
                    $userId,
                    $statusToken,
                    self::IMPORT_ABORT_MESSAGES[$reason] ?? 'اجرای ایمپورت مجاز نیست.',
                );
            }

            ImportStatus::release($userId);
        }

        Log::warning(static::class.': import aborted — the dispatch-time authorization no longer holds', [
            'reason' => $reason,
            'user_id' => $userId,
            'path' => $path,
        ]);
    }
}
