# 003 — Add Return Types to Model Relationships

## Problem
Several Eloquent models lack explicit return types on relationship methods. This hurts IDE support, static analysis (PHPStan), and code readability.

### ✅ Verified scope (2026-09-02 audit of `app/Models/`)
Most models are already typed (Hardware, Ticket, Person, Unit, User.pivot, TicketComment, Notification, …).
The original plan's examples `Ticket::unit()/user()/assignee()` and `Hardware::audits()` are **already typed** — do not touch them.
Relationships still missing return types:

| Model | Method |
|---|---|
| `ActivityLog` | `subject()` |
| `Attachment` | `user()`, `ticket()` |
| `Region` | `parent()`, `children()`, `units()`, `boundary()` |
| `TaskActivity` | `user()`, `attachments()` |
| `Todo` | `tickets()` |
| `Unit` | `childrenRecursive()` |
| `UnitType` | `allowedParentTypes()` |
| `UnitTypeRelationship` | `childUnitType()`, `allowedParentUnitType()` |
| `User` | `unit()` (accessor-style; verify it returns a relation) |

## Proposal
1. Add `Illuminate\Database\Eloquent\Relations\` return types (`BelongsTo`, `HasMany`, `HasOne`, `BelongsToMany`, `MorphTo`, …) to the methods in the table above. Note `UnitType::allowedParentTypes()` and `UnitTypeRelationship::childUnitType()` may return `BelongsToMany`.
2. Re-grep for any stragglers at implementation time (the audit is point-in-time).
3. Run `vendor/bin/pint --dirty --format agent` after changes.
4. Run full test suite (`composer test`) to ensure no regressions.

## Files
- `app/Models/ActivityLog.php`, `Attachment.php`, `Region.php`, `TaskActivity.php`, `Todo.php`, `Unit.php`, `UnitType.php`, `UnitTypeRelationship.php`, `User.php`
- No test changes needed (behavioral no-op)

## Risk: Very Low
Type-only changes. No runtime behavior.
