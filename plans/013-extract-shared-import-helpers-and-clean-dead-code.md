# Plan 013: Extract shared ImportHelpers + clean dead code

| Field      | Value                              |
|------------|------------------------------------|
| Category   | tech-debt                          |
| Effort     | S                                  |
| Risk       | LOW                                |
| Priority   | P2                                 |
| Depends on | none                               |
| Base SHA   | 5f9c24e                            |
| Branch     | celin                              |

---

## 1. Problem

`PersonImport` and `HardwareImport` duplicate the same string-normalization
helper methods. The codebase also contains dead Blade templates, commented-out
routes, and a duplicate `use` import — all of which create confusion and
drift risk.

## 2. What exists today

| Issue | Location | Detail |
|-------|----------|--------|
| Duplicate `clean()` | `app/Imports/PersonImport.php:461-468`, `HardwareImport.php:415-422` | Identical implementation: null/empty/'\N'/whitespace → `null`, else `trim()`. |
| Duplicate `normalizeForComparison()` | `PersonImport.php:439-446`, `HardwareImport.php:373-390` | **Divergent**: `PersonImport` is a simple null-check + trim. `HardwareImport` adds boolean normalization (0/1/true/false/"بله"/"تایید"). These are NOT identical — the trait method must handle both use-cases or remain split. |
| Duplicate `parseInt()` | `PersonImport.php:448-459`, `HardwareImport.php` (not present) | Only in `PersonImport` — not a shared method. Skip extraction. |
| Dead blade: `glowingcard` | `resources/views/livewire/glowingcard.blade.php` (54 lines) | No route, no references from routes. Pure visual demo card. `GlowingCardLivewireTest.php` tests it via `Livewire::test()`. Must delete test + file + phpstan exclude. |
| Dead blade: `welcome` | `resources/views/welcome.blade.php` (298 lines) | Default Laravel scaffold. No route references it (commented out at `web.php:50`). |
| Dead blade: `register` | `resources/views/livewire/auth/register.blade.php` | Route is commented out at `web.php:18`. No other route references it. Has tests only as a Livewire component render test — need to check if test file exists. |
| Dead blade: `tools/index` | `resources/views/tools/index.blade.php` (76 lines) | Standalone non-Livewire Blade template. Route `/tools` renders `tools.tools` Livewire component instead. Zero references in routes or code. |
| Dead index.blade.php body | `resources/views/livewire/index.blade.php:16-28` | `mount()` always redirects to `/dashboard`. The Blade template below is never rendered. Consider simplifying the entire file. |
| Commented routes | `routes/web.php:17-18` | `Volt::route('/login')` and `Route::livewire('/register')` — both commented out. |
| Commented routes | `routes/web.php:49-54` | Old `welcome` and `dashboard` view routes — commented out. |
| Duplicate import | `app/Services/NotificationService.php:5-6` | `use App\Models\Notification;` aliased as both `Notification` and `NotificationModel`. Only `NotificationModel` is used. |

## 3. Implementation steps

### Step 1 — Extract `ImportHelpers` trait

Create `app/Imports/Concerns/ImportHelpers.php`:

```php
<?php

namespace App\Imports\Concerns;

trait ImportHelpers
{
    /** Trim whitespace; return null for null/empty/N\N/whitespace-only. */
    protected function clean(?string $value): ?string
    {
        if ($value === null || $value === '' || $value === '\\N' || trim((string) $value) === '') {
            return null;
        }
        return trim($value);
    }

    /**
     * Normalize for string comparison. Handles booleans for hardware imports.
     *
     * @param mixed $value
     */
    protected function normalizeForComparison(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === '\\N') {
            return null;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if ($value === false || $value === 0 || $value === '0') {
            return '0';
        }
        if ($value === true || $value === 1 || $value === '1') {
            return '1';
        }
        return trim((string) $value);
    }

    /** Parse a tab-delimited value to int, returning null for null/empty/N\N. */
    protected function parseInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === '\\N') {
            return null;
        }
        $val = trim((string) $value);
        if ($val === '' || $val === '\\N') {
            return null;
        }
        return (int) $val;
    }
}
```

> **Design note on `normalizeForComparison` divergence**: `PersonImport` version
> was a simple trim. `HardwareImport` adds boolean normalization. The trait
> should use the superset (HardwareImport's version). For `PersonImport`,
> booleans never appear in person data, so the extra `is_bool` branches are
> harmless no-ops — acceptable.

### Step 2 — Apply trait to both imports

**`app/Imports/PersonImport.php`**:
1. Add `use App\Imports\Concerns\ImportHelpers;` after existing use statements.
2. Add `use ImportHelpers;` inside the class.
3. Remove `normalizeForComparison()` (lines 439-446), `parseInt()` (lines 448-459), and `clean()` (lines 461-468).

**`app/Imports/HardwareImport.php`**:
1. Add `use App\Imports\Concerns\ImportHelpers;` after existing use statements.
2. Add `use ImportHelpers;` inside the class.
3. Remove `normalizeForComparison()` (lines 373-390), `parseBoolean()` (lines 392-399), `parseDate()` (lines 402-412), and `clean()` (lines 415-422).

> **Note**: `parseBoolean()` and `parseDate()` are hardware-specific — keep
> them in `HardwareImport` if not shared. They are NOT in `PersonImport`.
> Re-check: they are only in `HardwareImport`, so they stay as private methods
> there. Only extract `clean()` and `normalizeForComparison()`.

### Step 3 — Delete dead files

| File | Action |
|------|--------|
| `resources/views/livewire/glowingcard.blade.php` | Delete |
| `resources/views/welcome.blade.php` | Delete |
| `resources/views/livewire/auth/register.blade.php` | Delete (route is commented out, no active route) |
| `resources/views/tools/index.blade.php` | Delete |
| `tests/Feature/GlowingCardLivewireTest.php` | Delete (tests a dead component) |

**Verification before delete**: Run `grep -r "glowingcard\|welcome\|auth\.register\|tools/index" routes/ app/ resources/` to confirm zero live references.

### Step 4 — Simplify index.blade.php

`resources/views/livewire/index.blade.php` always redirects in `mount()`.
Replace the entire file with:

```php
<?php
use Livewire\Component;
return new class extends Component {
    public function mount()
    {
        return redirect('/dashboard');
    }
};
?>
```

The Blade template below the PHP block (lines 16-28) is never rendered.

### Step 5 — Remove commented-out routes

In `routes/web.php`:
1. Delete lines 17-18 (commented `Volt::route('/login')` and `Route::livewire('/register')`).
2. Delete lines 49-54 (commented `welcome` and `dashboard` view routes).
3. Keep line 19 (`// Define the logout` comment — it's a section header, not dead code).

### Step 6 — Fix duplicate import in NotificationService

In `app/Services/NotificationService.php`:
1. Remove line 5: `use App\Models\Notification;`
2. Keep line 6: `use App\Models\Notification as NotificationModel;`

This is the only alias actually used in the file.

### Step 7 — Remove phpstan exclude for glowingcard

In `phpstan.neon`:
1. Delete line 18: `- resources/views/livewire/glowingcard.blade.php`

## 4. Files changed

| File | Action |
|------|--------|
| `app/Imports/Concerns/ImportHelpers.php` | **Create** — new trait |
| `app/Imports/PersonImport.php` | **Modify** — add trait, remove 3 methods |
| `app/Imports/HardwareImport.php` | **Modify** — add trait, remove 2 methods |
| `app/Services/NotificationService.php` | **Modify** — remove duplicate import |
| `routes/web.php` | **Modify** — remove commented-out routes |
| `resources/views/livewire/index.blade.php` | **Modify** — remove dead Blade HTML |
| `resources/views/livewire/glowingcard.blade.php` | **Delete** |
| `resources/views/welcome.blade.php` | **Delete** |
| `resources/views/livewire/auth/register.blade.php` | **Delete** |
| `resources/views/tools/index.blade.php` | **Delete** |
| `tests/Feature/GlowingCardLivewireTest.php` | **Delete** |
| `phpstan.neon` | **Modify** — remove glowingcard exclude |

## 5. Verification

1. `grep -r "assertTrue" tests/` — ensure no test breakage.
2. `composer test` — all tests pass.
3. `grep -rn "glowingcard\|register\|welcome\|tools/index" routes/web.php` — zero matches (only the `// Define the logout` comment remains).
4. `grep -rn "use App\\Models\\Notification;" app/Services/NotificationService.php` — zero matches.
5. `phpstan analyse --no-progress` — passes (glowingcard exclude removed, file deleted).
6. `grep -rn "clean\|normalizeForComparison\|parseInt" app/Imports/` — all references point to the trait or the importing classes.
