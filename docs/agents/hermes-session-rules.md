# Hermes session rules — h-dashboard instance

Seeded into this repo so every Hermes session on this server starts with the same
workflow. Read this file first, then `AGENTS.md` (authoritative project instructions).

## 1. Location & git

Work in `/home/runner/h-dashboard`. The repo is a fork; do not clone it again and do
not add or edit remotes.

- Current server branch: `baran`
- `origin` = `https://github.com/haileen7/h-dashboard.git` (this server's fork)
- Upstream canonical: `https://github.com/asgarimehdi/h-dashboard.git`, base branch `beta`

Sync the canonical `beta` into this branch, then push:

```bash
cd h-dashboard
git fetch https://github.com/asgarimehdi/h-dashboard.git beta:refs/remotes/canonical/beta
git merge --ff-only canonical/beta
git push origin baran
```

Never merge, force-push, or push development work to `beta`.

## 2. On every code change

Commit and push to `baran` on its existing remote. One clear commit per logical
change, message in the repo's existing style (imperative subject, body explaining
the *why*).

## 3. On `pr`

Open a pull request from `baran` → `beta` on `asgarimehdi/h-dashboard`. Use the
GitHub MCP (`create_pull_request`). Never merge unless explicitly asked.

## 4. Required tools

All four MCP servers are configured in `~/.hermes/config.yaml` and must work:

- **laravel_boost** — `database_query`, `database_schema`, `search_docs`,
  `application_info`, `get_absolute_url`, `browser_logs`, `read_log_entries`
- **context7** — `query_docs` (libraryId `/laravel/docs` for Laravel 13)
- **github** — issues, PRs, code search
- **codegraph** — code-structure questions. The CLI is global npm
  (`@colbymchenry/codegraph`); the MCP server wraps `codegraph serve --mcp`.
  The CLI bin is **not persistent across server rebuilds**: check
  `command -v codegraph`, and if absent run
  `npm install -g @colbymchenry/codegraph` then `codegraph init` in `h-dashboard`.
  The MCP tools only register at session start, so until then use
  `codegraph query|explore` from the terminal.

MCP servers register when the session starts. If one is missing from the tool
catalog, the fix is config in `~/.hermes/config.yaml` — a new session picks it up,
not a retry in this one.

## 5. Skills

- **`read-the-damn-docs`** — always. Read `AGENTS.md` first, then Boost
  `search_docs` / Context7 for anything framework- or version-dependent. This repo
  pins unusual versions (Laravel 13.33, Livewire 4.4, spatie/permission 8.3).
- **`improve`** (shadcn) — installed and available, **not run** in this session. Use
  it on request for a read-only codebase audit that writes plans under `plans/`.
  It must never edit source code itself.

## 6. CodeGraph-first

For structural questions, run `codegraph explore` / `codegraph query` before
grep/glob/read. The debugging order from `AGENTS.md` is: CodeGraph → Boost → Context7
→ Tinker → Pest.

## 7. Known environment facts

- `storage/logs/laravel.log` may not exist on a fresh server, so
  `read_log_entries` reports "Log file not found". Check `storage/logs/` first.
- CodeGraph writes `.codegraph/` locally; it is git-ignored via `.git/info/exclude`.
- `.hermes.md` **is** tracked by `beta` — do not exclude it locally, and remove it
  before a `git merge` so the tracked version can land.
