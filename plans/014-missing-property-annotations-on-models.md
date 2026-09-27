# Plan 14: Missing @property annotations on 22/24 models — root cause of ~350 PHPStan baseline errors

> Written against commit: `db9a2db` (beta/sydney)
> Category: Tech-Debt | Effort: M | Impact: Medium

## Problem

22 of 24 Eloquent models lack `@property` docblocks in their class-level PHPDoc. PHPStan level 6 cannot infer magic property access from `$fillable`/`$casts`, so every `$model->column` access triggers an "Access to an undefined property" error. These errors are currently suppressed via `phpstan-baseline.neon` (6,607 lines, 1,101 entries), making the baseline nearly useless for catching real regressions.

### Evidence

**Baseline error counts by type (Models only):**
- "Access to an undefined property": **147** errors across 15 models
- "Call to an undefined static method" (e.g. `::create()`, `::where()`): **~196** errors — missing `@method static` annotations
- "Access to protected property": **10** errors (ActivityLog declares `protected` typed properties instead of relying on Eloquent magic)

**Top offenders by error count:**
| Model | Undefined Property Errors |
|---|---|
| Hardware | 50 |
| Unit | 22 |
| Person | 17 |
| Ticket | 11 |
| Todo | 10 |
| HardwareAudit | 10 |
| TicketComment | 7 |
| MaintenanceSchedule | 7 |
| User | 5 |

**Models with annotations (reference):**
- `Notification.php` — 13 `@property` lines, zero property-related baseline errors ✅
- `User.php` — 3 `@property` lines (incomplete: missing `settings`, `deleted_at`, etc.)

**Models without annotations (22):**
`ActivityLog`, `ActivityLogArchive`, `Attachment`, `Boundary`, `DailyReport`, `Estekhdam`, `Hardware`, `HardwareAudit`, `MaintenanceSchedule`, `Person`, `Radif`, `Region`, `Semat`, `Tahsil`, `TaskActivity`, `Ticket`, `TicketComment`, `TicketCommentReaction`, `Todo`, `Unit`, `UnitType`, `UnitTypeRelationship`

### Root Cause

Laravel's Eloquent uses `__get()`/`__set()` magic methods for attribute access. PHPStan cannot introspect these at level 6, so it requires explicit `@property` annotations. The project never added them because:
1. PHPStan passed clean with the baseline — no pressure to fix
2. The baseline grew organically as new models/features were added
3. No convention was established early

### Special Case: ActivityLog

`ActivityLog.php` declares `protected` typed properties (e.g., `protected string $type`, `protected ?string $description = null`). These shadow Eloquent's magic accessors, causing "Access to protected property" errors when controllers/Blade templates access `$activityLog->type` externally. This model needs its properties removed (or made dynamic) AND `@property` annotations added.

## Solution

Add comprehensive `@property` and `@method static` PHPDoc annotations to all 24 models (including improving the incomplete `User.php`). Follow the established pattern from `Notification.php`.

### Before (Hardware.php — no annotations)

```php
class Hardware extends Model
{
    use HasFactory;
    use PersianNormalizer;
    // ...
}
```

### After

```php
/**
 * @property int $id
 * @property string $n_code
 * @property string $pc_name
 * @property string $type
 * @property string|null $os
 * @property string|null $ip_valid
 * @property string|null $ip_local
 * @property string|null $mac
 * @property string|null $net_type
 * @property string|null $switch
 * @property string|null $port
 * @property bool $shutdown
 * @property string|null $vlan
 * @property string|null $motherboard
 * @property string|null $cpu
 * @property string|null $ram
 * @property string|null $hdd
 * @property string|null $comments
 * @property bool $mark
 * @property Carbon|null $clean_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @property-read Person|null $person
 * @property-read Collection<int, HardwareAudit> $audits
 *
 * @method static Builder<static> where(string $column, mixed $value)
 * @method static Builder<static> whereIn(string $column, array $values)
 * @method static Builder<static> create(array $attributes)
 * @method static Builder<static> find(mixed $id)
 * @method static Builder<static> chunk(int $count, callable $callback)
 */
class Hardware extends Model
{
```

### Annotation Categories

Each model needs three kinds of annotations:

1. **`@property`** — one per column (from `$fillable` + `$casts` + known DB columns). Use `Carbon` for datetime casts, `bool` for boolean casts, `array` for JSON casts.

2. **`@property-read`** — one per Eloquent relationship (`BelongsTo`, `HasMany`, etc.) and computed attributes (accessors like `getGeojsonAttribute()`).

3. **`@method static`** — common Builder methods called statically: `where()`, `whereIn()`, `find()`, `create()`, `findOrFail()`, `chunk()`, etc. For models using `HasFactory`, also add `factory()`.

### ActivityLog Fix

Remove the `protected` typed property declarations (they serve no purpose — Eloquent uses `$_attributes` internally). Replace with `@property` annotations:

```php
// BEFORE — causes "Access to protected property" errors
class ActivityLog extends Model
{
    protected string $type;
    protected ?string $description = null;
    protected ?int $subject_id = null;
    // ...
}

// AFTER
/**
 * @property int $id
 * @property int|null $user_id
 * @property string $type
 * @property string|null $description
 * @property int|null $subject_id
 * @property string|null $subject_type
 * @property array|null $old_values
 * @property array|null $new_values
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @property-read User|null $user
 * @property-read MorphTo $subject
 *
 * @method static Builder<static> where(string $column, mixed $value)
 * @method static Builder<static> whereIn(string $column, array $values)
 * @method static Builder<static> create(array $attributes)
 */
class ActivityLog extends Model
{
    // Remove all protected typed properties
```

## Files in Scope

- `app/Models/ActivityLog.php` — remove protected properties + add annotations
- `app/Models/ActivityLogArchive.php`
- `app/Models/Attachment.php`
- `app/Models/Boundary.php`
- `app/Models/DailyReport.php`
- `app/Models/Estekhdam.php`
- `app/Models/Hardware.php`
- `app/Models/HardwareAudit.php`
- `app/Models/MaintenanceSchedule.php`
- `app/Models/Person.php`
- `app/Models/Radif.php`
- `app/Models/Region.php`
- `app/Models/Semat.php`
- `app/Models/Tahsil.php`
- `app/Models/TaskActivity.php`
- `app/Models/Ticket.php`
- `app/Models/TicketComment.php`
- `app/Models/TicketCommentReaction.php`
- `app/Models/Todo.php`
- `app/Models/Unit.php`
- `app/Models/UnitType.php`
- `app/Models/UnitTypeRelationship.php`
- `app/Models/User.php` — complete existing incomplete annotations
- `phpstan-baseline.neon` — regenerate after fixes

## Files Out of Scope

- `app/Models/Notification.php` — already fully annotated
- Controllers, Services, Exports (their property errors stem from model annotations, not their own code)
- `UnitScopedRequest.php` — its undefined property errors are a separate issue (form request attributes)

## Steps

### Step 1: Add annotations to simple lookup models (5 files)

These have only `name` + auto-incrementing `id`:
1. `Estekhdam.php` — `@property int $id`, `@property string $name`
2. `Radif.php` — `@property int $id`, `@property string $name`
3. `Semat.php` — `@property int $id`, `@property string $name`
4. `Tahsil.php` — `@property int $id`, `@property string $name`
5. `UnitType.php` — `@property int $id`, `@property string $name`, `@property string|null $description`

### Step 2: Add annotations to medium-complexity models (10 files)

1. `Attachment.php` — `id`, `user_id`, `file_path`, `file_name`, `file_size`, `ticket_id`, `activity_id`
2. `Boundary.php` — `id`, `boundary` (binary), `geojson` accessor
3. `DailyReport.php` — `id`, `unit_id`, `report_date`, `summary`, `payload` (array cast), `generated_by`
4. `HardwareAudit.php` — `id`, `hardware_id`, `user_id`, `action`, `changes` (array cast), `source`, `ip_address`, `user_agent`
5. `MaintenanceSchedule.php` — `id`, `unit_id`, `title`, `frequency`, `recurrence_interval` (int cast), `last_generated_at`, `next_due_at`
6. `Region.php` — `id`, `name`, `type`, `parent_id`, `boundary_id`
7. `TaskActivity.php` — `id`, `ticket_id`, `user_id`, `action`, `description`, `is_internal`, `to_unit_id`, `to_user_id`
8. `TicketComment.php` — `id`, `ticket_id`, `user_id`, `parent_id`, `body`, `body_html`, `is_system` (bool), `system_event`, `created_at`, `deleted_at`
9. `TicketCommentReaction.php` — `id`, `comment_id`, `user_id`, `reaction`
10. `UnitTypeRelationship.php` — `id`, `child_unit_type_id`, `allowed_parent_unit_type_id`

### Step 3: Add annotations to high-complexity models (7 files)

These have many columns, relationships, and accessors:

1. **Hardware.php** — 20 fillable columns + `person` relation + `audits` relation + scope methods
2. **Person.php** — 11 fillable columns + `user`, `estekhdam`, `radif`, `semat`, `tahsil`, `unit` relations + `name` accessor
3. **Ticket.php** — 11 fillable columns + `unit`, `task`, `user`, `assignee`, `attachments`, `activities`, `comments` relations + accessors
4. **Todo.php** — 9 fillable columns + `unit`, `user`, `tickets` relations
5. **Unit.php** — 9 fillable columns + `person`, `unitType`, `region`, `parent`, `children`, `boundary`, `assignedUsers`, `tickets`, `todos` relations + scope methods
6. **ActivityLog.php** — remove `protected` typed properties, add `@property` for all 9 columns + `user` relation + `subject` morphTo
7. **ActivityLogArchive.php** — 12 columns + `user` relation

### Step 4: Complete User.php annotations

Current `User.php` has only `@property int $id`, `@property string $n_code`, `@property string $password`. Add:
- `@property string|null $settings` (array cast)
- `@property Carbon|null $deleted_at` (datetime cast)
- `@property-read Person|null $person`
- `@property-read string $name` (accessor)
- `@property-read string $unit_name` (accessor)
- `@method static Builder<static> where(string $column, mixed $value)`
- etc.

### Step 5: Regenerate PHPStan baseline

```bash
composer phpstan   # Verify all new annotations resolve errors
vendor/bin/phpstan analyse --no-progress --generate-baseline
```

Expected result: baseline shrinks from ~1,101 entries to significantly fewer (property-related errors alone = ~353).

### Step 6: Verify

```bash
composer phpstan    # Must pass clean with new baseline
composer pint       # Format
composer test       # All tests pass
```

## Test Plan

1. **No new tests needed** — this is a docblock-only change; it doesn't alter runtime behavior
2. Run `composer phpstan` after each model batch to verify annotations resolve errors
3. After Step 5, verify the baseline shrinks: `wc -l phpstan-baseline.neon` should show fewer lines
4. Run `composer test` to confirm no regressions
5. Spot-check: `grep -c "@property" app/Models/*.php` — all 24 files should have annotations

## Maintenance Note

- **New models must include `@property` annotations from day one.** Add this to `AGENTS.md` as a convention rule.
- **When adding columns via migrations**, also update the model's `@property` annotations in the same PR.
- **The `@method static` annotations** cover the most common Builder calls. If PHPStan flags a new static method call, add the corresponding `@method static` annotation.
- **ActivityLog's `protected` typed properties** should not be restored. If audit trail data needs typed access, use `@property` annotations instead — they document the contract without conflicting with Eloquent's magic accessors.
- Consider adding a PHPStan rule or CI check to enforce that new model files contain at least one `@property` annotation.

## Done Criteria

- [ ] All 24 models have `@property` annotations for their database columns
- [ ] All 24 models have `@property-read` for relationships and accessors
- [ ] All 24 models have `@method static` for commonly-used static calls
- [ ] `ActivityLog.php` no longer has `protected` typed property declarations
- [ ] `User.php` annotations are complete (not just 3 lines)
- [ ] `phpstan-baseline.neon` is regenerated — baseline shrinks significantly
- [ ] `composer phpstan` passes clean
- [ ] `composer pint` passes clean
- [ ] `composer test` — all 1,352 tests pass
- [ ] `grep -c "@property" app/Models/*.php` returns > 0 for all 24 model files
