# 003 — Add Return Types to Model Relationships

## Problem
Several Eloquent models lack explicit return types on relationship methods. This hurts IDE support, static analysis (PHPStan), and code readability.

Examples found via CodeGraph:
- `UnitType::allowedParentTypes()` — no return type
- `Ticket::unit()` / `Ticket::user()` / `Ticket::assignee()` — need verification
- `Hardware::audits()` — need verification

## Proposal
1. Audit all models under `app/Models/` for missing return types on relationships.
2. Add `Illuminate\Database\Eloquent\Relations\` return types:
   - `BelongsTo`, `HasMany`, `HasOne`, `BelongsToMany`, etc.
3. Run `vendor/bin/pint --dirty --format agent` after changes.
4. Run full test suite to ensure no regressions.

## Files
- `app/Models/*.php` (all models)
- No test changes needed (behavioral no-op)

## Risk: Very Low
Type-only changes. No runtime behavior.
