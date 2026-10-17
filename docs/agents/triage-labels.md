# Triage labels

Agent skills speak in terms of canonical triage roles. This file maps each role
to the label this repo actually uses.

**The label set is the source of truth, not this table:**

```bash
gh label list --repo asgarimehdi/h-dashboard
```

## Roles → labels

Apply the label from the right-hand column — a role name is *not* a label string.

| Skill role | Label used here | Meaning |
| --- | --- | --- |
| needs triage | *(no dedicated label)* | Triage *is* applying `P1`/`P2`/`P3` plus the category labels; an issue with no priority is untriaged |
| needs info | `question` | «Further information is requested» |
| ready for an AFK agent | `ready` | «آماده اجرا» — specified enough that an agent can start |
| needs a human | *(no dedicated label)* | `ready` means work may start; if only a human can do it, say so in a comment |
| a human is on it | `busy` | «some one working on it» — do not start |
| reviewed | `reviewed` | «یک ناظر بررسی کرده» |
| will not be actioned | `wontfix` | «This will not be worked on»; use `invalid` («This doesn't seem right») for a wrong report |

Creating or renaming labels needs triage permission, so this file follows the
label set instead of defining it; a role with no label is handled by a comment.

## The rest of the vocabulary

- **Priority:** `P1` (High), `P2` (Medium), `P3` (Low)
- **Kind of work:** `perf`, `improve`, `security`, `tests`, `docs`,
  `documentation`, `dx`, `bug`, `dependencies`, `direction`
- **Area:** `api`, `hardware`, `hr`, `personnel`, `kargozini`,
  `vendor-management`, `purchase-order`, `flutter`, `theme`, `ui/ux`, `ux`
- **State:** `ready`, `busy`, `reviewed`, `question`
- **Test gaps:** `missing-test` (no coverage), `need-test` (feature/area lacks
  Pest coverage), `test-failure`
- **Process:** `beta` (targeted at the beta branch), `github_actions`,
  `enhancement`, `feature`, `good first issue`, `help wanted`, `invalid`,
  `wontfix`

Two label descriptions are misleading and are kept only because renaming needs
triage permission: `feature` reads «This issue or pull request already exists»
(that text belongs to GitHub's built-in duplicate label), and `improve` has no
description.
