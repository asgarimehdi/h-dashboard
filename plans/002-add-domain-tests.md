# Plan 002: Add domain tests for Ticket workflow, AccessService, Todo API
Drift: git diff --stat 25c206a4..HEAD -- tests/ app/Services/ app/Http/ app/Models/
Commit: 25c206a4 | Priority: P1 | Effort: M | Risk: LOW | Depends: 001 | Category: tests

## Why
Zero domain tests exist. Regression in ticket state machine, AccessService scope, or Todo API is undetected. TodoController contains in_arry (PHP has no such function) which always returns true, bypassing auth silently. This plan tests for that bug.

## Commands
vendor/bin/pest
vendor/bin/pint --dirty

## Steps
### 1. Enable RefreshDatabase
In tests/Pest.php, uncomment line 18: ->use(RefreshDatabase::class)
Verify: vendor/bin/pest --filter=ExampleTest passes

### 2. TicketWorkflowTest
Create tests/Feature/TicketWorkflowTest.php. Cases: (1) created status, (2) forwarded, (3) accepted sets accepted_at, (4) completed sets completed_at, (5) rejected, (6) cannot complete unless accepted or forwarded, (7) validation min lengths.
Verify: vendor/bin/pest --filter=TicketWorkflowTest (7 pass)

### 3. AccessServiceTest
Create tests/Feature/AccessServiceTest.php. Cases: (1) single-unit user, (2) multi-unit user, (3) indirect descendants, (4) clearCache invalidates.
Verify: vendor/bin/pest --filter=AccessServiceTest (4 pass)

### 4. TodoApiTest
Create tests/Feature/TodoApiTest.php. Cases: (1) list, (2) create in-scope, (3) CANNOT create out-of-scope (in_arry test, will fail until fix), (4) update, (5) delete, (6) toggle-complete.
Verify: vendor/bin/pest --filter=TodoApiTest (6 pass; case 3 expected fail until in_arry fixed)

### 5. Run all
vendor/bin/pest (all pass)

## Done
- [ ] TicketWorkflowTest.php >=7 cases, all pass
- [ ] AccessServiceTest.php >=4 cases, all pass
- [ ] TodoApiTest.php >=6 cases, all pass
- [ ] vendor/bin/pest exits 0
- [ ] plans/README.md status updated

## STOP
Stop if RefreshDatabase fails (factories from 001 missing). Stop if in_arry bug causes case 3 to unexpectedly pass. Stop if factory chain incomplete.