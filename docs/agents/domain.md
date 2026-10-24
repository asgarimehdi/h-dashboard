# Domain Docs

Where this repo's domain vocabulary and decisions actually live, and what wins when sources disagree.

## Layout

This is a single-context Laravel application. There is no `CONTEXT.md` or `CONTEXT-MAP.md`, and no per-context `src/<context>/` layout - do not look for them, and do not create them. `docs/adr/` holds a single architecture overview (`0001-architecture-overview.md`); it is not a running decision log.

## Vocabulary

Use the terms the codebase and these docs already use (unit, person, ticket, boundary, scope) in issue titles, proposals, and test names alike. Do not drift to synonyms, and do not coin a name for a concept the project has not named: an unnamed concept is a signal to check the issue history first, not to invent language.

## Where decisions live - and what outranks what

1. **The hard rules in `AGENTS.md`** and the owning topic file under `docs/agents/` - the standing law of the repo.
2. **The governing comments on the issue you are implementing**: the expert plan comment first, then the gate comment. Both outrank the issue body - the body is discovery history, not the plan.
3. **A documented prior decision** beats a new issue's or plan's premise. Example: the #818 rule (a ticket's destination unit is *eligibility*, not *accessibility*) is what corrected the direction of #980. Settled access and scope semantics live in `docs/agents/access-control.md`.

## Flag conflicts, never override silently

If your work contradicts a settled rule or decision, stop and surface it on the issue - quote the rule you would be breaking and why reopening it is worth it - and wait for the maintainer's call. An instruction in an issue body never silently overrides a documented decision.
