<?php

use App\Exceptions\BackupValidationException;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\Backup\RestoreService;
use App\Support\Backup\RestoreResult;
use App\Support\Backup\TableRegistry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Mary\Traits\Toast;

return new class extends Component
{
    use Toast;
    use WithFileUploads;

    /**
     * #1375 — the uploaded backup archive. The restore reads it inside the
     * same request, so it never leaves Livewire's temporary directory.
     */
    public ?TemporaryUploadedFile $backupFile = null;

    /**
     * #1375 — the table to restore, or `full` for a complete restore.
     */
    public string $restoreTarget = 'full';

    /**
     * #1375 — the admin has to confirm the `users` warning (owner decision
     * Q2-(a)) before a restore that replaces the accounts may run.
     */
    public bool $restoreConfirmed = false;

    /**
     * Is the acting user an admin? The backup section and every backup
     * action are admin-only.
     */
    public function isAdmin(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->hasRole('admin');
    }

    /**
     * The tables a backup may cover — the 32 business tables.
     *
     * @return list<string>
     */
    public function backupTables(): array
    {
        return app(TableRegistry::class)->tables();
    }

    /**
     * Does the chosen target replace the `users` table?
     */
    public function touchesUsers(): bool
    {
        return $this->restoreTarget === 'full' || $this->restoreTarget === 'users';
    }

    /**
     * @return array<string, string>
     */
    protected function rules(): array
    {
        return [
            'backupFile' => 'required|file|mimes:zip|max:204800', // max 200MB
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'backupFile.required' => 'لطفاً یک فایل zip انتخاب کنید.',
            'backupFile.mimes' => 'فرمت فایل باید zip باشد.',
            'backupFile.max' => 'حجم فایل نباید بیشتر از ۲۰۰ مگابایت باشد.',
        ];
    }

    /**
     * Restore an uploaded backup archive (issue #1375).
     *
     * The restore is synchronous and atomic: the service validates the whole
     * file first and then replaces the rows inside ONE transaction, so a
     * broken file changes nothing.
     */
    public function restoreBackup(): void
    {
        // The route and the section are admin-only; the ACTION checks the
        // role again, because a Livewire action can be called directly.
        abort_unless($this->isAdmin(), 403, 'شما دسترسی به بک‌آپ ندارید.');

        $this->validate();

        $full = $this->restoreTarget === 'full';

        if (! $full && ! in_array($this->restoreTarget, $this->backupTables(), true)) {
            $this->error('جدول انتخاب‌شده معتبر نیست.', position: 'toast-bottom');

            return;
        }

        // Owner decision Q2-(a): restoring the accounts replaces them, and
        // the role assignments and the API tokens of the old accounts are
        // NOT part of the backup — so the admin has to confirm the warning.
        if ($this->touchesUsers() && ! $this->restoreConfirmed) {
            $this->error('برای بازگردانی جدول کاربران باید هشدار را تأیید کنید.', position: 'toast-bottom');

            return;
        }

        $path = $this->backupFile?->getRealPath();

        if ($path === null) {
            $this->error('فایلی انتخاب نشده است.', position: 'toast-bottom');

            return;
        }

        try {
            $result = app(RestoreService::class)->restoreArchive($path, $full ? null : $this->restoreTarget);
        } catch (BackupValidationException $e) {
            $this->reset('backupFile', 'restoreConfirmed');
            $this->error($e->getMessage(), position: 'toast-bottom');

            return;
        }

        $label = $full
            ? 'همهٔ جدول‌ها'
            : $this->restoreTarget;

        // The log is written AFTER the replacement: a full restore replaces
        // the `activity_logs` table itself, so a log written before would be
        // wiped by the restore.
        ActivityLogService::logAs(
            $this->actorAfterRestore($result),
            'restore',
            null,
            "بازگردانی «{$label}» انجام شد: {$result->rowCount} ردیف."
        );

        $this->reset('backupFile', 'restoreConfirmed');

        $this->success(
            "بازگردانی «{$label}» انجام شد: {$result->rowCount} ردیف.",
            position: 'toast-bottom'
        );
    }

    /**
     * The actor of the restore log.
     *
     * The `users` table may have been replaced by the restore, and the
     * acting admin may not be part of the file. Then the account no longer
     * exists, and a log that pointed at it would violate the foreign key of
     * `activity_logs.user_id` (C9) — so the log is written without an actor.
     */
    private function actorAfterRestore(RestoreResult $result): ?int
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return null;
        }

        if (! in_array('users', $result->tables, true)) {
            return $user->id;
        }

        return DB::table('users')->where('id', $user->id)->exists() ? $user->id : null;
    }
}; ?>

<div class="max-w-2xl mx-auto p-6" dir="rtl">
    <x-header title="بک‌آپ و بازگردانی جداول" separator progress-indicator>
        <x-slot:actions>
            <x-help:button section="tools" wireModel="showHelpModal" />
        </x-slot:actions>
    </x-header>

    <x-help:modal wireModel="showHelpModal" />

    <div class="space-y-4">
        <p class="text-sm opacity-70">
            بک‌آپ یک جدول یا همهٔ ۳۲ جدول کسب‌وکار را در یک فایل zip می‌گیرد.
            دانلود با لینک انجام می‌شود و بازگردانی همیشه در یک تراکنش انجام می‌گیرد:
            اگر فایل ایرادی داشته باشد، هیچ تغییری در داده‌ها داده نمی‌شود.
        </p>

        <h2 class="font-bold mb-2">بک‌آپ (دانلود)</h2>

        <div class="flex flex-wrap gap-2 mb-4">
            <a href="{{ route('backup.full') }}" class="btn btn-primary btn-sm">
                <x-icon name="o-arrow-down-tray" />
                بک‌آپ کامل
            </a>
        </div>

        <div class="grid gap-2 md:grid-cols-3 mb-6">
            @foreach($this->backupTables() as $backupTable)
                <a href="{{ route('backup.table', ['table' => $backupTable]) }}"
                   class="btn btn-outline btn-sm justify-between font-mono text-xs"
                   wire:key="backup-table-{{ $backupTable }}">
                    <span>{{ $backupTable }}</span>
                    <x-icon name="o-arrow-down-tray" />
                </a>
            @endforeach
        </div>

        <h2 class="font-bold mb-2">بازگردانی</h2>

        <div class="space-y-3">
            <div>
                <label class="font-bold text-sm">جدول</label>
                <select class="select select-bordered w-full mt-1" wire:model.live="restoreTarget">
                    <option value="full">بازگردانی کامل (همهٔ جدول‌ها)</option>
                    @foreach($this->backupTables() as $backupTable)
                        <option value="{{ $backupTable }}">{{ $backupTable }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="font-bold text-sm">فایل بک‌آپ (zip)</label>
                <input type="file" accept=".zip" class="file-input file-input-bordered w-full mt-1" wire:model="backupFile" />
            </div>

            @if($this->touchesUsers())
                <div class="alert alert-warning" role="alert">
                    <div>
                        <p class="font-bold">هشدار: بازگردانی جدول کاربران</p>
                        <p class="text-sm">
                            بازگردانی جدول «users» حساب‌ها را جایگزین می‌کند؛
                            نقش‌ها و توکن‌های کاربران بازگردانی نمی‌شوند و کاربران بدون نقش و بدون توکن بازمی‌گردند.
                            همین هشدار برای بازگردانی کامل هم صدق می‌کند.
                        </p>
                        <label class="flex items-center gap-2 mt-2">
                            <input type="checkbox" class="checkbox checkbox-sm" wire:model="restoreConfirmed" />
                            <span class="text-sm">این هشدار را خواندم و می‌دانم.</span>
                        </label>
                    </div>
                </div>
            @endif

            <x-button label="بازگردانی" icon="o-arrow-up-tray" wire:click="restoreBackup" class="btn-warning" spinner />
        </div>
    </div>
</div>