# perf: the shipped nginx config has no gzip and no cache headers

> **Plan 019** · from the read-only performance audit at commit `56d794a2` (2026-10-19).
> Labels: `improve, perf, P3` · formerly GitHub issue #1155 (this file is the source of truth).

> **Executor instructions**: Follow this plan step by step. Run every verification command and confirm the expected result before moving to the next step. If any STOP condition occurs, stop and report — do not improvise. This plan is self-contained; the audit session that produced it is not available to you.

**Drift check (run first)**: `git diff --stat 56d794a2..HEAD -- deploy/nginx/h-dashboard.conf install-guid.md`

## Status

- **Priority**: P3
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none (deployment config — requires the operator's nginx access to apply in production)
- **Category**: perf
- **Planned at**: commit `56d794a2`, 2026-10-19

## Why this matters

The nginx site this repo ships has **no gzip/brotli** and **no cache headers**. The main CSS bundle is 348,758 bytes raw (~44 KB gzipped) and the page loads roughly 630 KB of render-blocking CSS/JS/fonts on a cold authenticated load. Without compression every user transfers the raw bytes on each cold load, and hashed `/build/assets/*` files (which are content-addressed and safe to cache forever) are revalidated on every visit instead of served from cache. Both are standard, low-risk nginx directives, and the same file is mirrored in `install-guid.md`, so deployments copy whatever is here.

## Current state

- `deploy/nginx/h-dashboard.conf` — a minimal server block: `client_max_body_size`, `root`, `index`, `try_files`, a PHP location, a dotfile deny, and two `add_header` lines. **No** `gzip`, `brotli`, `expires`, or `Cache-Control` anywhere.
- `install-guid.md` mirrors the same config (install steps for operators), so it must be updated in lockstep.
- Measured on this commit: `public/build/assets/app-B0RThsHM.css` = 348,758 B raw / ~44,130 B gzipped; `public/js/leaflet/leaflet.js` = 144 KB; the UI font = ~50 KB.
- Vite emits content-hashed filenames under `public/build/assets/` (see `public/build/manifest.json`), which is what makes immutable caching safe.
- **Sensitive-data caution**: the committed `server_name` line contains environment-specific hostnames and private IPs. When capturing evidence in this plan or a PR, cite `deploy/nginx/h-dashboard.conf` **by file and line only** — do not paste the `server_name` value into an issue or PR body.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Syntax check (if nginx is installed locally) | `nginx -t -c <path>` | `syntax is ok` |
| Curl a compressed response | `curl -sI -H 'Accept-Encoding: gzip' http://<your-local-host>/ | grep -i content-encoding` | `content-encoding: gzip` |
| Curl a cache header | `curl -sI http://<your-local-host>/build/assets/<hashed-file>.css | grep -i cache-control` | `cache-control: public, max-age=31536000, immutable` |
| Format | `vendor/bin/pint --dirty --format agent` | exit 0 |

> If nginx is not available locally, validate by review and have the operator run `nginx -t` before reload. State in the PR which validation was possible.

## Scope

**In scope**:
- `deploy/nginx/h-dashboard.conf`
- `install-guid.md`

**Out of scope** (do NOT touch):
- The PHP location, dotfile deny, `root`/`try_files`, and the two existing `add_header` lines (and the app-level `SecurityHeaders` middleware — do not duplicate security headers in nginx).
- The `server_name` value.
- Any application code.

## Git workflow

- Branch: `perf/nginx-compression-cache-headers`
- Commit style: `perf: enable gzip and static-asset caching in the shipped nginx config (#019)`
- Do not push or open a PR unless instructed.

## Steps

### Step 1: Add compression

Add to the server block:

```nginx
gzip on;
gzip_vary on;
gzip_comp_level 5;
gzip_min_length 1024;
gzip_proxied any;
gzip_types
    text/plain text/css text/xml text/javascript
    application/javascript application/json application/xml
    image/svg+xml font/woff2;
```

(`font/woff2` is already compressed — excluding it is also fine; if the operator prefers brotli, add the module's `brotli` directives only when the module is present, otherwise nginx fails to start. Prefer gzip-only unless brotli is confirmed.)

**Verify**: `nginx -t` → `syntax is ok` (or operator confirmation).

### Step 2: Add immutable caching for hashed build assets

```nginx
location ^~ /build/assets/ {
    expires 1y;
    add_header Cache-Control "public, max-age=31536000, immutable";
    access_log off;
}

location ~* \.(?:woff2?|ttf|eot)$ {
    expires 30d;
    add_header Cache-Control "public, max-age=2592000";
    access_log off;
}

location ~* ^/(?:js|css|icons|fonts)/ {
    expires 30d;
    add_header Cache-Control "public, max-age=2592000";
    access_log off;
}
```

Only `/build/assets/` is content-hashed; the `public/js|css|fonts|icons` paths are **not** hashed, so they get a bounded 30-day expiry (a stale file is still possible — a shorter TTL or a `?v=` query is the alternative; note the choice in the PR).

**Verify**: `curl -sI http://<host>/build/assets/<file>.css | grep -i cache-control` → immutable header.

### Step 3: Mirror in `install-guid.md`

Apply the same lines to the config shown in the install guide, keeping the two files byte-identical in the shared parts.

**Verify**: `diff <(sed -n '/^server {/,/^}/p' deploy/nginx/h-dashboard.conf) <(sed -n '/^server {/,/^}/p' install-guid.md)` → no differences other than the intentionally different `server_name`.

## Test plan

- No application tests change. If the repo has a config test (check `grep -rln "nginx" tests/`), extend it to assert `gzip on` and the `/build/assets/` cache header are present in both files — otherwise validate by review + `nginx -t`.
- Verification: `nginx -t` (or operator), plus the two curl checks against a local instance.

## Done criteria

- [ ] `grep -n "gzip on" deploy/nginx/h-dashboard.conf install-guid.md` returns one match in each
- [ ] `/build/assets/` responses carry `Cache-Control: public, max-age=31536000, immutable`
- [ ] `nginx -t` passes (or the operator confirms; state it in the PR)
- [ ] The two config files match apart from `server_name`
- [ ] No `server_name` value or private IP appears in the PR body
- [ ] No files outside the in-scope list are modified

## STOP conditions

- Brotli is not confirmed present and the plan is changed to use it — nginx fails to start without the module; keep gzip-only.
- The deployment does **not** use this nginx config (e.g. it runs behind a different proxy) — STOP and report; the fix may belong elsewhere.
- Adding a location block changes an existing route's resolution (run `nginx -T` and check the full merged config before/after).

## Maintenance notes

- Operators must reload nginx (`nginx -s reload`) for this to take effect; the install guide should say so next to the config.
- If assets are later served through Vite's dev server or a CDN, revisit these headers.
- Reviewer should scrutinize: that only `/build/assets/` gets `immutable`, and that no security header is duplicated or removed.
