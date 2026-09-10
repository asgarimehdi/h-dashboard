# Plan 001: Email Notifications

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**: `git diff --stat c775883..HEAD -- resources/views/livewire/settings/index.blade.php app/Models/User.php app/Services/NotificationService.php`
> If any of these changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: M
- **Risk**: LOW
- **Depends on**: none
- **Category**: feature
- **Planned at**: commit `c775883`, 2026-09-03

## Why this matters

The settings page has an "emailNotifications" toggle that saves to `users.settings['email_notifications']` but **no code reads this value to decide whether to send emails**. Users see a toggle, think email notifications are controllable, but they can't actually enable or disable them. This is a UX lie — worse than not having the toggle at all.

## Current state

- `resources/views/livewire/settings/index.blade.php` — toggle exists at line 56, saves via `save()` method at line 25
- `app/Models/User.php` — has `settings` cast to array
- `app/Services/NotificationService.php` — sends in-app notifications; no email integration exists
- No email sending infrastructure (no Mailable, no Queue for emails)

## Scope

**In scope:**
- `app/Services/EmailNotificationService.php` (new — send emails based on user settings)
- `app/Mail/NotificationMail.php` (new — Mailable)
- `app/Services/NotificationService.php` (modify — check `email_notifications` before sending)
- `resources/views/emails/notification.blade.php` (new — email template)
- `resources/views/livewire/settings/index.blade.php` (add debug test button)
- `tests/Feature/SettingsEmailNotificationTest.php` (new)

**Out of scope:**
- Push notifications / browser notifications (Plan 002)
- Dashboard auto-refresh (Plan 003)
- Compact mode (Plan 004)

## Git workflow

- Branch: `it-test5`
- Commit per step; messages follow conventional style
- Push after each commit

## Steps

### Step 1: Create EmailNotificationService

Create `app/Services/EmailNotificationService.php`:

```php
<?php

namespace App\Services;

use App\Models\User;
use App\Mail\NotificationMail;
use Illuminate\Support\Facades\Mail;

class EmailNotificationService
{
    public function shouldSendEmail(User $user): bool
    {
        return ($user->settings['email_notifications'] ?? true) === true;
    }

    public function send(User $user, string $title, string $body, ?string $url = null): void
    {
        if (! $this->shouldSendEmail($user)) {
            return;
        }

        if (empty($user->email)) {
            return;
        }

        Mail::to($user->email)->send(new NotificationMail($title, $body, $url));
    }
}
```

**Verify**: `php artisan tinker --execute 'echo class_exists(App\Services\EmailNotificationService::class) ? "OK" : "FAIL";'` → `OK`

### Step 2: Create NotificationMail Mailable

Create `app/Mail/NotificationMail.php`:

```php
<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $title,
        public string $body,
        public ?string $url = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "اعلان: {$this->title}");
    }

    public function content(): Content
    {
        return new Content(htmlView: 'emails.notification');
    }
}
```

Create `resources/views/emails/notification.blade.php`:

```html
<!DOCTYPE html>
<html dir="rtl" lang="fa">
<head><meta charset="utf-8"></head>
<body style="font-family: Vazirmatn, Tahoma, sans-serif; direction: rtl;">
    <h2>{{ $title }}</h2>
    <p>{{ $body }}</p>
    @if($url)
        <a href="{{ $url }}" style="display:inline-block;padding:10px 20px;background:#10b981;color:#fff;text-decoration:none;border-radius:6px;">مشاهده</a>
    @endif
</body>
</html>
```

**Verify**: `php artisan tinker --execute 'echo class_exists(App\Mail\NotificationMail::class) ? "OK" : "FAIL";'` → `OK`

### Step 3: Modify NotificationService to check email setting

In `app/Services/NotificationService.php`, add email sending after in-app notification:

```php
// After creating the in-app notification, add:
if ($user && app(\App\Services\EmailNotificationService::class)->shouldSendEmail($user)) {
    app(\App\Services\EmailNotificationService::class)->send($user, $title, $body, $url);
}
```

**Verify**: `composer test` → all pass (928+)

### Step 4: Add test button in settings

In `resources/views/livewire/settings/index.blade.php`, add a "test email" button after the save button:

```php
// In the Livewire class, add:
public function sendTestEmail(): void
{
    $user = auth()->user();
    if (empty($user->email)) {
        $this->error('ایمیلی برای کاربر تنظیم نشده', position: 'toast-bottom');
        return;
    }

    app(EmailNotificationService::class)->send(
        $user,
        'تست اعلان ایمیلی',
        'این یک ایمیل تستی از داشبورد سلامت است.',
        url('/dashboard')
    );

    $this->success('ایمیل تستی ارسال شد!', position: 'toast-bottom');
}
```

Add button in blade:
```html
<x-button label="تست ایمیل" icon="o-paper-airplane" wire:click="sendTestEmail" class="btn-outline btn-sm" spinner />
```

**Verify**: `php artisan tinker --execute 'echo "OK";'` → `OK`

### Step 5: Write tests

Create `tests/Feature/SettingsEmailNotificationTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use App\Services\EmailNotificationService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

covers(User::class);

class SettingsEmailNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    public function test_email_notification_respects_user_setting(): void
    {
        $unit = Unit::create(['name' => 'تست']);
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'تست', 'l_name' => 'کاربر',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unit->id,
        ]);
        $user = User::create(['n_code' => $nCode, 'password' => bcrypt('password'), 'email' => 'test@test.com']);
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);

        // Disabled
        $user->settings = ['email_notifications' => false];
        $user->save();

        $service = app(EmailNotificationService::class);
        $this->assertFalse($service->shouldSendEmail($user));

        // Enabled
        $user->settings = ['email_notifications' => true];
        $user->save();

        $this->assertTrue($service->shouldSendEmail($user));
    }
}
```

**Verify**: `XDEBUG_MODE=off php artisan test tests/Feature/SettingsEmailNotificationTest.php` → PASS

## Done criteria

- [ ] `composer test` exits 0
- [ ] `EmailNotificationService` checks `users.settings.email_notifications`
- [ ] `NotificationService` sends email when enabled
- [ ] Test button in settings works
- [ ] Tests pass

## STOP conditions

- If `NotificationService` doesn't have a static `send()` method
- If `User` model doesn't have `settings` cast
- If email configuration is not set in `.env`
