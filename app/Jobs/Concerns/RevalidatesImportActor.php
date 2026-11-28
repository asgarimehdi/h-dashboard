<?php

namespace App\Jobs\Concerns;

use App\Models\Unit;
use App\Models\User;
use App\Support\ImportStatus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * #1639: a queued import carries the actor's scope and permission snapshot
 * from dispatch time. A worker has no session and no auth user, so the
 * snapshot must be re-validated against the database before it writes.
 *
 * Deliberately session-free and cache-free: a worker's session is empty while
 * a test still holds the dispatch-time `current_unit_id`, so resolving through
 * `AccessService::accessibleUnitIds()` would behave differently in the two
 * contexts — exactly where the security assertion lives.
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
            $scope = array_values(array_intersect($captured, $this->freshAccessibleUnitIds($user)));

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

    /**
     * Mirror of AccessService::accessibleUnitIds() minus the session base and
     * the caches: in a worker the base is the user_units rows, falling back to
     * the person's HR unit.
     *
     * @return array<int, int>
     */
    private function freshAccessibleUnitIds(User $user): array
    {
        $base = array_map('intval', $user->units()->pluck('units.id')->all());

        if ($base === [] && ($personUnitId = $user->person?->u_id) !== null) {
            $base = [(int) $personUnitId];
        }

        if ($base === []) {
            return [];
        }

        return array_map('intval', Unit::descendantIds($base)->toArray());
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

            // #1981: pass the dispatch token — a null token is a deliberate
            // no-op, so an abort cannot free a newer claim.
            ImportStatus::release($userId, $statusToken);
        }

        Log::warning(static::class.': import aborted — the dispatch-time authorization no longer holds', [
            'reason' => $reason,
            'user_id' => $userId,
            'path' => $path,
        ]);
    }
}
