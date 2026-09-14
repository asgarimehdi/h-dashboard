# Plan 012: E2E That Actually Gates (CI job, no sleeps, real asserts)

> **Executor instructions**: Follow step by step. Run every verification command and confirm expected output before moving on. If STOP conditions occur, stop and report — do not improvise.
>
> **Drift check (run first)**: `git diff --stat bdd8e10..HEAD -- tests/e2e/ playwright.config.ts scripts/e2e-test.sh .env.e2e.example AGENTS.md .github/workflows/test.yml` → expect no output. If output exists, compare excerpts below against live code first.

## Status

- **Priority**: P3 (32 specs rot silently; sleeps flake under load; weak asserts pass broken tabs)
- **Effort**: M
- **Risk**: MED (new CI job + harness surgery; `trap` changes failure modes — test the failure path deliberately)
- **Depends on**: 001 (unit gate first; E2E second), 011 (env-template cleanup — coordinate `.env.e2e` naming)
- **Category**: tests
- **Planned at**: commit `bdd8e10`, 2026-09-14

## Why this matters

32 Playwright specs run in zero CI jobs. They assert with fixed `waitForTimeout` sleeps (flaky by construction, masked by `retries: 2`) and too-weak assertions (captures tab text, asserts only visibility). The harness itself can destroy the dev `.env` on failure (`set -e`, no `trap`, restore step skipped) and fails on fresh clone (`.env.e2e` missing, only `.example` committed; AGENTS.md says `.env.test`, config reads `.env.e2e`).

## Current state (audit-verified; re-check counts live)

- `.github/workflows/`: only `deploy.yml` + `test.yml`; `grep -rn 'playwright\|e2e' .github/workflows/` empty; `deploy.yml:83` runs pest only. `tests/e2e/*/` 16 dirs / 32 specs.
- Sleeps: `search/global.spec.ts:28,36,44` (1200/1800ms), `personnel/list.spec.ts` 7 sleeps, `tickets/inbox.spec.ts` 1200ms after tab click.
- Weak: inbox tab test captures `before` text, asserts `toBeVisible()` only; "inbox loads" asserts `count() > 0` despite seeded "50 total / 20 per page" comment.
- `scripts/e2e-test.sh`: `set -e`, no `trap`; `cp .env.e2e .env` but only `.env.e2e.example` committed; step-10 restore skipped on failure. `AGENTS.md:198` says `.env.test` vs `playwright.config.ts` reading `.env.e2e`.

## Commands you will need

| Purpose | Command | Expected |
|---|---|---|
| Spec count | `find tests/e2e -name '*.spec.ts' \| wc -l` | 32 (or live number — update plan if drifted) |
| Sleep hunt | `grep -rn 'waitForTimeout' tests/e2e/ \| wc -l` | → 0 after fix |
| Harness dry | `bash -n scripts/e2e-test.sh` | syntax OK |

## Scope

**In scope**: files in drift check + `test.yml` (new `e2e` job) + touched specs only.
**Out of scope**: new E2E coverage for new features (direction plans own theirs); E2E data-independence DB work (deleted plan 024's territory — if absolute-count asserts still exist, convert to seeded-exact or relative within this plan, but do NOT build the `:8001` isolated env here; note it as follow-up if needed).

## Steps

### Step 1: Harness that can't destroy dev env

Add `trap restore EXIT`; copy from `.env.e2e.example` when `.env.e2e` absent; fix `.env.test` naming in AGENTS.md → `.env.e2e`. Deliberately TEST the failure path (failing spec still restores `.env`).
**Verify**: simulated failure leaves `git diff --stat .env` empty; fresh-clone path works.

### Step 2: Sleeps → web-first asserts

Replace every `waitForTimeout` with `await expect(row).toHaveCount(n)` / `page.waitForResponse(/livewire/)`; add CI grep ban (`waitForTimeout` → fail).
**Verify**: sleep count → 0; suite passes 2× consecutively (flakiness check).

### Step 3: Asserts that bite + CI job

Tab test asserts `after !== before` (or URL/query change); inbox asserts exact seeded counts per tab. Add `e2e` job to `test.yml` (PHP+Node setup, seeded user, `bash scripts/e2e-test.sh --project=chromium`) — or explicitly document E2E as manual (decision, not drift).
**Verify**: break a tab filter deliberately → E2E fails (proves it gates); revert; green.

## Test plan

- Harness failure-path test, 2× consecutive green runs, deliberate-break gating proof.

## Done criteria

- [ ] No `waitForTimeout` (CI-banned); 2× green runs
- [ ] Tab/count asserts exact, not `> 0`/visible-only
- [ ] `e2e` CI job live OR manual-status documented
- [ ] Harness failure restores `.env` (proven, not assumed)
- [ ] Scope clean

## STOP conditions

- E2E needs seeded infra absent in CI (PostGIS/Redis/services) → stop, report job requirements.
- Isolated-DB need resurfaces (cross-run pollution) → stop, note as follow-up; don't build here.
- Verification fails twice → stop, report.
