# chore: performance findings considered and not planned (2026-10-19 audit)

> **Plan 023** · from the read-only performance audit at commit `56d794a2` (2026-10-19).
> Labels: `improve, documentation` · formerly GitHub issue #1159 (this file is the source of truth).

# Findings considered and rejected (performance audit, 2026-10-19)

This records the improvement opportunities a performance audit at commit `56d794a2` examined and **deliberately did not plan**, so the same ideas are not re-audited every run. Each is one line of reasoning. No action is expected.

This issue is informational. It is not a task list; there is nothing to implement.

## 1. PHP 8.6 preview job runs on every PR

**Examined**: `.github/workflows/test.yml:592-690` — `test-php86` is `continue-on-error: true`, yet runs checkout, composer install (with `--ignore-platform-req=php`), a Node/Vite build, migrations and the whole Pest suite on every pull request.

**Verdict — rejected, by design.** The job's own comment states the intent explicitly:

> Same precedent as the mutation job — never blocks a merge; it surfaces 8.6 incompatibilities and deprecations while there is still time to fix them.

That is a deliberate cadence choice: the value is *early* detection before PHP 8.6 GA, which a weekly schedule would not provide. The Node/build phase will be removed independently if the "skip Vite in PHP test jobs" plan lands, which is where the real saving is.

**Revisit**: at PHP 8.6 GA, the job's purpose (surface pre-release incompatibilities) ends and it should be either removed or folded into the normal `test` job's PHP matrix.

## 2. GIS viewport cache key cardinality

**Examined**: `app/Http/Controllers/Api/GisController.php:71-127` — bbox keys are rounded to 4 decimals (~11 m) for the key while the SQL filter uses the raw bbox; each pan can mint new keys, each holding up to 1000 features for 60 minutes; `map-dashboard` refetches on a 300 ms-debounced `moveend`.

**Verdict — real, but not worth changing now.** Three reasons: (a) 4-decimal rounding is ~11 m, so "nearby reuses returning a clipped feature set" is a theoretical mismatch, not an observed one; (b) each distinct viewport legitimately owns its own feature set, so coarsening the key trades memory for *wrong* (wider) results; (c) the honest mitigation is **operational** — monitor Redis memory and session/queue latency — rather than a code change that quietly broadens map data.

**If it becomes a problem**: the right lever is server-side clustering or a low-zoom feature cap (a product change), not key coarsening. Track Redis memory before touching this.

## 3. Caching the personnel lookup tables

**Examined**: `resources/views/livewire/kargozini/person.blade.php:388-422` `with()` runs `Tahsil::all()`, `Estekhdam::all()`, `Semat::all()`, `Radif::all()` on every render, including every keystroke in the page's live inputs.

**Verdict — rejected on measurement.** Measured table sizes on this commit: `tahsil` **6** rows, `estekhdam` **5**, `semat` **56**, `radif` **55**. Four tiny unindexed full reads per render are not a measurable cost, and adding a cache introduces an invalidation surface (each lookup model's save/delete) for no gain.

A related claim from the same audit pass was also **not** a finding: `resources/views/livewire/reports/persons.blade.php:38-40` was reported as re-reading these tables per render, but it does so in `mount()` only — one read per page load, which is correct.

**Revisit**: only if a lookup table grows by an order of magnitude, or if the personnel page's render profile shows these reads dominating.

---

## Note on scope of the audit

The audit was a **read-only static review** at commit `56d794a2`. It did not include: live `EXPLAIN ANALYZE` on production-scale data, load testing, runtime query-count profiling on a seeded beta database, or inspection of the separate Flutter mobile client's field usage (which is why the ticket/comment payload plan is conditional on operator confirmation). Findings are ordered by leverage in the sibling plan issues.

Two audit findings were corrected during vetting and are recorded here so the corrections are not lost:

- The lookup-table finding above (vetoed on measurement).
- The `tickets/⚡create` search hook (`:273`) was reported as re-querying per keystroke; it is already `wire:model.live.debounce.300ms`, so only the hardware `n_code` field was a real finding.
