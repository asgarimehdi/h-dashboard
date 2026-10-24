# Issue tracker: GitHub Issues

Issues and specs for this repo live as **GitHub Issues** on
`asgarimehdi/h-dashboard`. There is no local tracker. `.scratch/` is a local
scratch directory — see `.scratch/README.md` — not a queue.

## Conventions

- **The issue number is the reference.** `AGENTS.md`, `docs/agents/*` and code
  comments cite `#NNN` as the reason a rule exists (`#702`, `#836`, `#940`, …).
  Cite the issue when you cite the rule.
- **Titles are plain problem statements** — no `[category]` prefix. The category
  lives in labels (`perf`, `improve`, `security`, `docs`, `tests`, `dx`,
  `direction`, `dependencies`, `bug`, …) and in the milestone
  `ممیزی اکتبر ۲۰۲۶ · <category>` (currently `· perf`, `· improve`,
  `· security`, `· direction`).
- **Priority is `P1` / `P2` / `P3`** (High / Medium / Low), as a label.
- **State is `ready`** («آماده اجرا», an agent may start), **`busy`** («some one
  working on it», do not start) and **`reviewed`** («یک ناظر بررسی کرده»).
- The live label set is the source of truth:
  `gh label list --repo asgarimehdi/h-dashboard`. The role mapping is in
  `docs/agents/triage-labels.md`.
- Repo-wide efforts get a milestone and a `discussions/NNN` thread for notes;
  the implementation PR closes the issue with `Closes #NNN`.

## When a skill says "publish to the issue tracker"

Open a GitHub issue (`gh issue create --repo asgarimehdi/h-dashboard`). The work
is not done until a PR body carries `Closes #NNN`.

## When a skill says "fetch the relevant ticket"

Read the GitHub issue: `gh issue view NNN --repo asgarimehdi/h-dashboard`. The
user will normally pass the number directly.
