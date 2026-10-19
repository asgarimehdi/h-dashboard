# perf: font delivery duplicates ~100 KB and omits font-display

> **Plan 021** · from the read-only performance audit at commit `56d794a2` (2026-10-19).
> Labels: `improve, perf, P3` · formerly GitHub issue #1157 (this file is the source of truth).

> **Executor instructions**: Follow this plan step by step. Run every verification command and confirm the expected result before moving to the next step. If any STOP condition occurs, stop and report — do not improvise. This plan is self-contained; the audit session that produced it is not available to you.

**Drift check (run first)**: `git diff --stat 56d794a2..HEAD -- resources/views/components/layouts/app.blade.php resources/css/app.css public/fonts`

## Status

- **Priority**: P3
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: perf
- **Planned at**: commit `56d794a2`, 2026-10-19

## Why this matters

Font delivery has three problems. (1) The `@font-face` in the layout declares only a Regular face, with **no `font-display`**, so first paint can be text-invisible for up to the browser's font block and bold text is synthesized rather than real. (2) There is **no preload**, so the font is discovered late. (3) `app.css` declares a second family (`"Vazir"`) pointing at `/public/fonts/...` with `format("truetype")` on `.woff2` files — a wrong path and a wrong format. Vite resolves those declarations and emits a **second copy** of both `woff2` files (the build manifest lists the hashed assets *and* the `public/fonts/` paths). The result is ~100 KB of duplicate artifacts and an uncacheable inline `@font-face` re-sent in every HTML response.

## Current state

- `resources/views/components/layouts/app.blade.php:35-51` — the inline, uncacheable face, with no Bold and no `font-display`:

```blade
<style>
    @font-face {
        font-family: 'Vazirmatn';
        src: url('/fonts/Vazirmatn-Regular.woff2') format('woff2');
        font-weight: normal;
    }

    body { font-family: 'Vazirmatn', sans-serif !important; direction: rtl; }
    .font-sans { font-family: 'Vazirmatn', sans-serif !important; }
</style>
```

- `resources/css/app.css:45-58` — the duplicate, mislabelled set:

```css
@font-face {
    font-family: "Vazir";
    src: url("/public/fonts/Vazirmatn-Regular.woff2") format("truetype");
    font-weight: normal;
    font-style: normal;
}
@font-face {
    font-family: "Vazir";
    src: url("/public/fonts/Vazirmatn-Bold.woff2") format("truetype");
    font-weight: bold;
    font-style: normal;
}
body { font-family: "Vazir", sans-serif; }
```

- `resources/css/app.css:72` (and the dark variant below it) — `.leaflet-routing-alt { font-family: Vazir; }` — the only real `"Vazir"` consumer.
- `public/fonts/Vazirmatn-Regular.woff2` (50,684 B) and `Vazirmatn-Bold.woff2` (51,020 B) are the real, tracked files; the Bold file is never declared for the family the UI uses.
- `public/build/manifest.json` lists **both** `assets/Vazirmatn-*.woff2` and `public/fonts/Vazirmatn-*.woff2` — the duplicate emission.
- Repo convention: styles are Tailwind-first (`app.css` is the Tailwind entry). Do not add a second CSS file for this.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Build | `npm run build` | exit 0; manifest lists **one** copy of each font |
| Focused tests | `XDEBUG_MODE=off php artisan test tests/Feature/AccessibilityInfrastructureTest.php tests/Feature/PageScriptSplittingTest.php` | all pass |
| Full suite (slow) | `composer test` | all pass |
| Format | `vendor/bin/pint --dirty --format agent` | exit 0 |

## Scope

**In scope**:
- `resources/css/app.css`
- `resources/views/components/layouts/app.blade.php` (the `<style>` block and a `<link rel="preload">` in `<head>`)
- A test if one asserts the layout's head, in the files above

**Out of scope** (do NOT touch):
- `public/fonts/*` — keep the two real files.
- The `.leaflet-routing-alt` styling beyond swapping its family name to match.
- Any other page's `font-family: inherit` inline styles (`maps/map-dashboard.blade.php:538-556` etc.) — `inherit` is already correct.

## Git workflow

- Branch: `perf/font-delivery`
- Commit style: `perf: single Vazirmatn face with font-display and preload (#021)`
- Do not push or open a PR unless instructed.

## Steps

### Step 1: Declare one family, in `app.css`

Replace the two `"Vazir"` blocks with a single `"Vazirmatn"` family declaring both weights with `font-display: swap`, using the real paths and the correct format:

```css
@font-face {
    font-family: "Vazirmatn";
    src: url("/fonts/Vazirmatn-Regular.woff2") format("woff2");
    font-weight: 400;
    font-style: normal;
    font-display: swap;
}
@font-face {
    font-family: "Vazirmatn";
    src: url("/fonts/Vazirmatn-Bold.woff2") format("woff2");
    font-weight: 700;
    font-style: normal;
    font-display: swap;
}
body { font-family: "Vazirmatn", sans-serif; }
```

Update `app.css:72` (and the dark variant) from `font-family: Vazir;` to `font-family: "Vazirmatn", sans-serif;`.

**Verify**: `grep -rn "\"Vazir\"\|font-family: Vazir" resources/css/app.css` → no matches.

### Step 2: Remove the duplicate inline block from the layout

Delete the `<style>` block's `@font-face` and the two `font-family: Vazirmatn … !important` rules (now redundant with `app.css`), keeping `body { direction: rtl; }` if it is not already handled by `dir="rtl"` on `<html>` (the root already sets `dir="rtl"`, so verify before removing). Do **not** keep two `@font-face` declarations for the same family — the browser would use the later one, and Vite would keep emitting the duplicate.

**Verify**: `grep -cn "@font-face" resources/views/components/layouts/app.blade.php` → 0.

### Step 3: Preload the regular face

Add to `<head>` (before `@vite`, so the request starts early):

```blade
<link rel="preload" href="{{ asset('fonts/Vazirmatn-Regular.woff2') }}" as="font" type="font/woff2" crossorigin>
```

**Verify**: `grep -n 'rel="preload"' resources/views/components/layouts/app.blade.php` → one match for the font.

### Step 4: Prove the duplicate emission is gone

Run the build and diff the manifest:

```bash
npm run build
grep -o '"[^"]*Vazirmatn[^"]*"' public/build/manifest.json | sort -u
```

Expected: only the `resources/css/app.css` entry referencing the fonts (or none at all if Vite copies `public/fonts` verbatim — that copy is fine and expected; what must be gone is the app.css-driven duplicate hashed emission). State in the PR which entries remain and why.

**Verify**: the manifest no longer lists both a hashed `assets/Vazirmatn-*` **and** a `public/fonts/Vazirmatn-*` entry caused by `app.css`.

## Test plan

- Update the layout/head test (if one exists in `AccessibilityInfrastructureTest.php`/`PageScriptSplittingTest.php`) to assert: exactly one `@font-face` block for Vazirmatn in the built CSS is out of scope for a PHP test — instead assert the **HTML head** contains the preload link and does **not** contain an inline `@font-face`.
- Manual visual check: load a page, confirm text renders in Vazirmatn (not a fallback serif), bold headings look heavier than before (real bold, not synthesized), and a Leaflet routing popup still uses the app font.
- Verification: focused tests + the manual check.

## Done criteria

- [ ] `grep -rn "\"Vazir\"" resources/css/app.css` returns no matches
- [ ] `grep -c "@font-face" resources/views/components/layouts/app.blade.php` returns 0
- [ ] A preload link for the regular woff2 is in `<head>`
- [ ] `npm run build` succeeds and the manifest no longer double-emits the fonts (state which entries remain)
- [ ] Focused tests pass
- [ ] `vendor/bin/pint --dirty --format agent` leaves no diff

## STOP conditions

- Removing the inline `!important` family rules changes text rendering on a page that relies on them (e.g. a page overriding `font-sans`) — report the page.
- The preload `crossorigin` attribute is required for same-origin fonts in this setup (it is for `as="font"` per the spec) — if the font fails to load after adding it, keep `crossorigin` and report.
- `dir="rtl"` is not set at the root (it is, per `app.blade.php:2`) — only then keep the inline `direction: rtl`.

## Maintenance notes

- Any future font weight must be added to the single `app.css` family with `font-display: swap`; do not reintroduce an inline `@font-face` in the layout (it defeats caching and duplicates the build).
- Reviewer should scrutinize: that bold is real (the Bold face loads) and no console 404 for the font URLs.
