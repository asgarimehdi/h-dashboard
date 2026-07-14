# Implementation Plans
Generated: 2026-07-12

## Status Table
| Plan | Title | Priority | Effort | Depends | Status |
|------|-------|----------|--------|---------|--------|
| 001  | Add factories for domain models | P1 | M | - | **DONE** |
| 002  | Add domain tests for Ticket, AccessService, Todo | P1 | M | 001 | **DONE** |
| 003  | Load chart JS assets only on pages that need them | P2 | S | - | **DONE** |
| 004  | Fix AccessService cache key to include session context | P2 | S | - | **DONE** |
| 005  | Add rate limiting to API login endpoint | P2 | S | - | **DONE** |

## Findings Rejected
- in_arry typo in TodoController (5 occurrences): always returns true, bypassing auth. Plan 002 test will catch it.
- XSS: no {!!} found, safe.
- N+1: eager loading already present on tickets query.
- inbox blade god object: defer to after tests land.