<?php

use App\Exceptions\BackupValidationException;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\Backup\RestoreService;
use App\Support\Backup\RestoreResult;
use App\Support\Backup\TableRegistry;
use App\Support\ThemeFamilies;
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

    public bool $browserNotifications = false;

    public int $dashboardRefresh = 0; // 0 = off

    public bool $compactMode = false;

    public bool $showHelpModal = false;

    /** #1224 — light pole; one of ThemeFamilies::LIGHT. */
    public string $lightTheme = ThemeFamilies::DEFAULT_LIGHT;

    /** #1224 — dark pole; one of ThemeFamilies::DARK. */
    public string $darkTheme = ThemeFamilies::DEFAULT_DARK;

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

    public function mount(): void
    {
        $settings = auth()->user()->settings ?? [];
        $this->browserNotifications = $settings['browser_notifications'] ?? false;
        $this->dashboardRefresh = $settings['dashboard_refresh'] ?? 0;
        $this->compactMode = $settings['compact_mode'] ?? false;

        // Stale or hand-edited values must never reach the toggle's Alpine
        // strings (see ThemeFamilies), so validate and fall back here too.
        $this->lightTheme = ThemeFamilies::poleOrDefault($settings['light_theme'] ?? null, 'light');
        $this->darkTheme = ThemeFamilies::poleOrDefault($settings['dark_theme'] ?? null, 'dark');
    }

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

    /**
     * @return list<array{value: string, label: string}>
     */
    public function lightOptions(): array
    {
        return ThemeFamilies::lightOptions();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function darkOptions(): array
    {
        return ThemeFamilies::darkOptions();
    }

    public function save(): void
    {
        // Whitelist BEFORE touching the database. These values are interpolated
        // into single-quoted Alpine expressions on every page, so an unknown
        // theme would silently fall back to the :root theme with no warning.
        if (! ThemeFamilies::isValid($this->lightTheme, 'light')) {
            $this->error('تم روشن انتخاب‌شده معتبر نیست.', position: 'toast-bottom');

            return;
        }

        if (! ThemeFamilies::isValid($this->darkTheme, 'dark')) {
            $this->error('تم تیره انتخاب‌شده معتبر نیست.', position: 'toast-bottom');

            return;
        }

        $user = auth()->user();
        // Rewrite the whole array: `settings` is a single JSON column, so a
        // partial array here would delete every key this save does not name.
        $user->settings = [
            'browser_notifications' => $this->browserNotifications,
            'dashboard_refresh' => $this->dashboardRefresh,
            'compact_mode' => $this->compactMode,
            'light_theme' => $this->lightTheme,
            'dark_theme' => $this->darkTheme,
        ];
        $user->save();

        // Scenario 1 (issue comment 6364662792): the applied theme may not be
        // one of the two new poles. The page's Alpine handler migrates it to
        // the same-family pole and re-broadcasts `theme-changed`.
        $this->dispatch('theme-poles-saved', lightTheme: $this->lightTheme, darkTheme: $this->darkTheme);

        $this->success('تنظیمات ذخیره شد!', position: 'toast-bottom');
    }

    public function notifyPermissionDenied(): void
    {
        $this->error('دسترسی اعلان مرورگر رد شد. لطفاً از تنظیمات مرورگر اعلان را فعال کنید.', position: 'toast-bottom');
    }

    public function notifyUnsupported(): void
    {
        $this->error('مرورگر شما از اعلان‌ها پشتیبانی نمی‌کند.', position: 'toast-bottom');
    }

    public function sendTestNotification(): void
    {
        if (! $this->browserNotifications) {
            $this->error('اعلان مرورگر غیرفعال است', position: 'toast-bottom');

            return;
        }

        $this->dispatch('browser-notification', [
            'title' => 'تست اعلان',
            'body' => 'این یک اعلان تستی از داشبورد سلامت است.',
            'url' => '/dashboard',
        ]);

        $this->success('اعلان ارسال شد!', position: 'toast-bottom');
    }

    public function testDashboardRefresh(): void
    {
        if ($this->dashboardRefresh === 0) {
            $this->error('بروزرسانی خودکار غیرفعال است', position: 'toast-bottom');

            return;
        }

        $this->success("داشبورد هر {$this->dashboardRefresh} ثانیه بروزرسانی می‌شود", position: 'toast-bottom');
    }

    public function testCompactMode(): void
    {
        if ($this->compactMode) {
            $this->success('حالت فشرده فعال شد', position: 'toast-bottom');
        } else {
            $this->success('حالت عادی فعال شد', position: 'toast-bottom');
        }
    }
}; ?>

    <div class="max-w-2xl mx-auto p-6" dir="rtl">
        <x-header title="تنظیمات" separator progress-indicator>
            <x-slot:actions>
                <x-help:button section="settings" wireModel="showHelpModal" />
                <x-theme-selector/>
            </x-slot:actions>
        </x-header>

        <x-help:modal wireModel="showHelpModal" />

        <x-card shadow>
            <h2 class="font-bold mb-4">اعلان‌ها</h2>
            <div class="space-y-4">
                <label class="flex items-center justify-between cursor-pointer">
                    <span>اعلان مرورگر</span>
                    <input type="checkbox" class="toggle toggle-primary" wire:model.live="browserNotifications"
                           x-on:change="if($event.target.checked && 'Notification' in window) { Notification.requestPermission().then(p => { if(p !== 'granted') { $wire.set('browserNotifications', false); $wire.notifyPermissionDenied(); } }) } else if($event.target.checked && !('Notification' in window)) { $wire.set('browserNotifications', false); $wire.notifyUnsupported(); }" />
                </label>
            </div>
        </x-card>

        <x-card shadow class="mt-4">
            <h2 class="font-bold mb-4">نمای داشبورد</h2>
            <div class="space-y-4">
                <div>
                    <label class="font-bold text-sm">بروزرسانی خودکار</label>
                    <select class="select select-bordered w-full" wire:model.live="dashboardRefresh">
                        <option value="0">غیرفعال</option>
                        <option value="15">هر ۱۵ ثانیه</option>
                        <option value="30">هر ۳۰ ثانیه</option>
                        <option value="60">هر ۱ دقیقه</option>
                    </select>
                </div>
                <label class="flex items-center justify-between cursor-pointer">
                    <span>حالت فشرده</span>
                    <input type="checkbox" class="toggle toggle-primary" wire:model.live="compactMode" />
                </label>
            </div>
        </x-card>

        <x-card shadow class="mt-4">
            <h2 class="font-bold mb-4">ظاهر (تم)</h2>
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label class="font-bold text-sm">تم روشن</label>
                    <x-select name="lightTheme" wire:model.live="lightTheme" :options="$this->lightOptions()"
                              option-value="value" option-label="label" class="mt-1" />
                </div>
                <div>
                    <label class="font-bold text-sm">تم تیره</label>
                    <x-select name="darkTheme" wire:model.live="darkTheme" :options="$this->darkOptions()"
                              option-value="value" option-label="label" class="mt-1" />
                </div>
            </div>
            <p class="mt-3 text-xs opacity-60">
                دکمهٔ تم در نوار بالا بین همین دو تم جابه‌جا می‌شود.
            </p>
        </x-card>

        <div class="mt-6 flex justify-end gap-2">
            <x-button label="تست اعلان" icon="o-bell" wire:click="sendTestNotification" class="btn-outline btn-sm" spinner />
            <x-button label="تست بروزرسانی" icon="o-arrow-path" wire:click="testDashboardRefresh" class="btn-outline btn-sm" spinner />
            <x-button label="تست نما" icon="o-eye" wire:click="testCompactMode" class="btn-outline btn-sm" spinner />
            <x-button label="ذخیره تنظیمات" icon="o-check" wire:click="save" class="btn-primary" spinner />
        </div>

        {{-- Issue #1375 — بک‌آپ و بازگردانی جداول. فقط مدیر سیستم آن را می‌بیند. --}}
        @if($this->isAdmin())
            <x-card shadow class="mt-4">
                <h2 class="font-bold mb-4">پشتیبان‌گیری و بازگردانی جداول</h2>

                <p class="text-sm opacity-70 mb-3">
                    بک‌آپ یک جدول یا همهٔ ۳۲ جدول کسب‌وکار را در یک فایل zip می‌گیرد.
                    دانلود با لینک انجام می‌شود و بازگردانی همیشه در یک تراکنش انجام می‌گیرد:
                    اگر فایل ایرادی داشته باشد، هیچ تغییری در داده‌ها داده نمی‌شود.
                </p>

                <div class="flex flex-wrap gap-2 mb-4">
                    <a href="{{ route('settings.backup.full') }}" class="btn btn-primary btn-sm">
                        <x-icon name="o-arrow-down-tray" />
                        بک‌آپ کامل
                    </a>
                </div>

                <div class="grid gap-2 md:grid-cols-3 mb-6">
                    @foreach($this->backupTables() as $backupTable)
                        <a href="{{ route('settings.backup.table', $backupTable) }}"
                           class="btn btn-outline btn-sm justify-between font-mono text-xs"
                           wire:key="backup-table-{{ $backupTable }}">
                            <span>{{ $backupTable }}</span>
                            <x-icon name="o-arrow-down-tray" />
                        </a>
                    @endforeach
                </div>

                <h3 class="font-bold mb-2">بازگردانی</h3>

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
            </x-card>
        @endif

        {{-- Inert data for the theme-poles listener in page-scripts.js.
             Must sit INSIDE the root element: Livewire drops trailing
             content after the root close tag (proven: the block placed
             after </div> never reached the DOM). --}}
        <script type="application/json" nonce="{{ Vite::cspNonce() }}" id="theme-families-data">@json(['light' => \App\Support\ThemeFamilies::LIGHT, 'dark' => \App\Support\ThemeFamilies::DARK])</script>
    </div>
