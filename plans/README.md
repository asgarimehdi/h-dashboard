# Performance plans — local backlog

Read-only performance audit of **h-dashboard** at commit `56d794a2` (2026-10-19), turned into
23 self-contained plans. These files are the source of truth; the matching GitHub issues
(#1137–#1159) are mirrors only.

**No source file was modified by the audit.** Nothing here is committed.

---

## How to use a plan

Each plan is written for an executor model with **zero context** — it contains paths, code
excerpts, commands, verification gates, boundaries, and STOP conditions. To execute one:

1. Read the plan's **Drift check** line and run it. If in-scope files changed since `56d794a2`,
   re-verify the "Current state" excerpts before proceeding.
2. Create the branch named in the plan's **Git workflow** section.
3. Follow the steps in order. After every step, run its verification command and confirm the
   expected result. Do not proceed on a failed gate.
4. If a **STOP condition** matches, stop and report — do not improvise.
5. Finish with the plan's **Done criteria** checklist.

Repo-wide verification (from `AGENTS.md`):

```bash
composer test                                  # full Pest suite (slow)
XDEBUG_MODE=off php artisan test <file>        # one file
vendor/bin/pint --dirty --format agent         # formatting
vendor/bin/phpstan analyse --no-progress       # static analysis
composer verify                                # all gates before push
```

---

## Index

### P1 — do these first

| Plan | Title | Effort | Risk |
|---|---|---|---|
| [001](001-dashboard-auto-refresh-broken.md) | Dashboard auto-refresh never fires (`$wire.mount()` is blocked) | S | LOW |
| [002](002-help-modal-payload.md) | Help modal ships ~90 KB of hidden content on every Livewire update | S | LOW |
| [003](003-folded-search-defeats-trgm-indexes.md) | Folded Persian ticket search cannot use the trgm indexes | M | LOW-MED |
| [008](008-deleted-hardware-trash-scope-paginate.md) | Deleted-hardware trash loads all history **and** ignores unit scope | M | MED |
| [004](004-imports-bulk-writes-queued-job.md) | Both Excel imports write one row per Eloquent call | M (queue: L) | MED |
| [005](005-import-preview-public-payload.md) | Import preview keeps every parsed row in public Livewire state | M | MED |

### P2

| Plan | Title | Effort | Risk |
|---|---|---|---|
| [009](009-gis-tickets-select-columns.md) | GIS ticket layer hydrates full ticket rows to emit 7 fields | S | LOW |
| [010](010-hardware-selection-round-trips.md) | Hardware row selection and `n_code` lookup fire a round trip each | S-M | LOW-MED |
| [006](006-todo-calendar-bounded-first-render.md) | Todo calendar's first render is unbounded | M | MED |
| [007](007-unit-tree-picker-render-cost.md) | Unit-tree picker rebuilds the whole tree on every render | M | LOW-MED |
| [012](012-gis-geometry-out-of-livewire-state.md) | Map pages carry boundary geometry in public state twice | M | MED |
| [013](013-ticket-history-bounded-and-eager-loads.md) | Ticket history unbounded; eager loads lost on hydration | M | LOW-MED |
| [014](014-ticket-comment-payload-shape.md) | Ticket/comment API list payloads ship full bodies | M | MED |
| [018](018-leaflet-datepicker-page-scoped.md) | Leaflet + jalalidatepicker load globally, blocking every page | M | MED |
| [022](022-ci-skip-vite-build-in-test-jobs.md) | Skip the Vite build in the PHP test jobs | S | LOW |

### P3

| Plan | Title | Effort | Risk |
|---|---|---|---|
| [011](011-hr-dashboard-duplicate-arrays.md) | HR dashboard serializes its aggregates twice per render | S | LOW |
| [015](015-prune-orphan-attachments-bulk.md) | Orphan-attachment prune scans the table once per file | S | LOW |
| [016](016-headcount-trend-single-pass.md) | Headcount trend runs a correlated subquery per month | S | LOW |
| [017](017-scope-cache-key-normalization.md) | Per-scope cache keys depend on the id list's order and type | S | LOW |
| [019](019-nginx-compression-cache-headers.md) | Shipped nginx config has no gzip and no cache headers | S | LOW |
| [020](020-highcharts-local-asset.md) | One chart page loads Highcharts from a public CDN | S | LOW |
| [021](021-font-delivery.md) | Font delivery duplicates ~100 KB and omits `font-display` | S | LOW |

### Informational

| Plan | Title |
|---|---|
| [023](023-considered-not-planned.md) | Findings considered and **not** planned, with the reasoning |

---

## Coupling and ordering notes

- **004 + 005** are the two halves of the import path (write batching vs. preview payload).
  Either order works, but land both before declaring imports fixed.
- **013 + 014** both touch ticket detail data; 013 is Livewire-side, 014 is API-side. Independent,
  but sharing a review pass is sensible.
- **014 is conditional.** The Flutter client is not in this repo. The plan bounds threaded replies
  and column selection first, and **refuses to drop `content` / `body` / `body_html`** until the
  mobile contract is confirmed. Expect it to stop at step 2 in the worst case.
- **008 is a scoping fix as much as a perf fix** — the trash query ignores `accessibleUnitIds()`.
  Treat its "deny-by-default" test as a correctness gate, not a perf assertion.
- **003**'s index expressions must be generated from `PersianNormalizer::foldSeparatorsSql()`, never
  hand-copied, so index and search can never diverge.
- **018** depends on the `@stack('scripts')` pattern from #984 (already merged) and must not touch
  `bootstrap.js` / `map-store.js` — a dynamic import there causes blank maps (#028).
- **022** removes the Node/Vite phase from the PHP test jobs; it does **not** touch the e2e job,
  which serves real assets.

## Audit caveats

The audit was a static, read-only review. It did **not** include live `EXPLAIN ANALYZE` on
production-scale data, load testing, runtime query-count profiling on a seeded beta database, or
inspection of the separate Flutter client. Findings are ranked by estimated leverage; verify the
hot path with a profiler before investing in the P3 items.

Two audit claims were corrected during vetting and are recorded in [023](023-considered-not-planned.md)
so they are not re-audited: the lookup-table caching idea (vetoed on measured table sizes: 6/5/56/55
rows) and the `tickets/⚡create` search hook (already debounced at 300 ms).
