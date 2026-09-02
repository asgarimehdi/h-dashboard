# Improvement Plans

This directory contains structured improvement plans for the h-dashboard project.
Each plan follows a consistent format: Problem → Proposal → Files → Risk.

## Plans

> Reviewed 2026-09-02 against working tree (branch `beta`) + AGENTS.md. All plans verified; stale claims corrected in-place. Status "Ready" = reviewed & corrected, safe to implement.

| # | Title | Risk | Status |
|---|-------|------|--------|
| 001 | Extract Hardware Validation Rules (DRY) | Low | ✅ DONE (`HardwareValidationRules` trait) |
| 002 | Add Missing Livewire Component Tests | Low | ✅ DONE (Networks, Wireless, Bell, Reports, Maps tests) |
| 003 | Add Return Types to Model Relationships | Very Low | ✅ DONE (Relationships & scope return types on 9 models) |
| 004 | Hardware Search Query Scopes | Medium | ✅ DONE (`scopeFilterSearch`, `scopeFilterAttributes`, etc.) |
| 005 | API Rate Limiting | Low | ✅ DONE (API & login throttle verified + tests) |

## Priority Order

1. **003** — Type safety, very low risk, IDE benefits, no behavior change
2. **001** — Quick win, DRY improvement, low risk
3. **002** — Test coverage, low risk, additive
4. **005** — Docs + one test, low risk (code part already shipped)
5. **004** — Code organization, medium risk (do last, needs careful behavior comparison)
