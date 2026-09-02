# Improvement Plans

This directory contains structured improvement plans for the h-dashboard project.
Each plan follows a consistent format: Problem → Proposal → Files → Risk.

## Plans

> Reviewed 2026-09-02 against working tree (branch `beta`) + AGENTS.md. All plans verified; stale claims corrected in-place. Status "Ready" = reviewed & corrected, safe to implement.

| # | Title | Risk | Status |
|---|-------|------|--------|
| 001 | Extract Hardware Validation Rules (DRY) | Low | ✅ Ready (store/update asymmetries documented: `shutdown` + `sometimes|required`) |
| 002 | Add Missing Livewire Component Tests | Low | ✅ Ready (Livewire 4 single-file string-name conventions added) |
| 003 | Add Return Types to Model Relationships | Very Low | ✅ Ready (scope narrowed to 9 models; Ticket/Hardware already typed) |
| 004 | Hardware Search Query Scopes | Medium | ✅ Ready (join/whereExists/normalizer constraints + ILIKE caveat documented) |
| 005 | API Rate Limiting | Low | ✅ Ready (mostly already implemented — `throttle:60,1` exists; docs + test only) |

## Priority Order

1. **003** — Type safety, very low risk, IDE benefits, no behavior change
2. **001** — Quick win, DRY improvement, low risk
3. **002** — Test coverage, low risk, additive
4. **005** — Docs + one test, low risk (code part already shipped)
5. **004** — Code organization, medium risk (do last, needs careful behavior comparison)
