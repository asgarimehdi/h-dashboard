#!/bin/bash
# Script to create GitHub issues for all test failures
# Run: chmod +x create_test_failure_issues.sh && ./create_test_failure_issues.sh

REPO="asgarimehdi/h-dashboard"
LABEL="test-failure"

# Create label if not exists
gh label create "$LABEL" --repo "$REPO" --description "Test failure issues" --color "d73a4a" 2>/dev/null || true

# 1. DashboardTodoNotificationTest
gh issue create --repo "$REPO" \
  --title "Test Failures: DashboardTodoNotificationTest (1 failure)" \
  --body "## Test Failures

### Tests\Feature\DashboardTodoNotificationTest

| Test | Error | Root Cause |
|------|-------|------------|
| dashboard renders correctly for authenticated user | ViewException | Missing or broken Livewire component/view rendering

**File:** \`tests/Feature/DashboardTodoNotificationTest.php\`" \
  --label "$LABEL"

# 2. HardwareAuditLivewireTest
gh issue create --repo "$REPO" \
  --title "Test Failures: HardwareAuditLivewireTest (4 failures)" \
  --body "## Test Failures

### Tests\Feature\HardwareAuditLivewireTest

| Test | Error | Root Cause |
|------|-------|------------|
| it loadHistory populates the history array from hardware_audits | QueryException | SQLite schema mismatch / FK constraint |
| it filterHistory filters by action | QueryException | SQLite schema mismatch / FK constraint |
| it rollbackHistoryField restores field value and logs rollback | QueryException | SQLite schema mismatch / FK constraint |
| it rollbackHistoryField errors on invalid audit id | QueryException | SQLite schema mismatch / FK constraint

**File:** \`tests/Feature/HardwareAuditLivewireTest.php\`" \
  --label "$LABEL"

# 3. HardwareAuditTest
gh issue create --repo "$REPO" \
  --title "Test Failures: HardwareAuditTest (3 failures)" \
  --body "## Test Failures

### Tests\Feature\HardwareAuditTest

| Test | Error | Root Cause |
|------|-------|------------|
| history alias endpoint works backward compat | Unknown | API endpoint backward compatibility issue |
| bulk mark logs audit | Unknown | Audit logging not triggered on bulk mark |
| rollback endpoint restores field and logs | Unknown | Rollback endpoint issue

**File:** \`tests/Feature/HardwareAuditTest.php\`" \
  --label "$LABEL"

# 4. ImportsLivewireTest
gh issue create --repo "$REPO" \
  --title "Test Failures: ImportsLivewireTest (3 failures)" \
  --body "## Test Failures

### Tests\Feature\ImportsLivewireTest

| Test | Error | Root Cause |
|------|-------|------------|
| hardware import component shows preview and confirms import | ViewException | Missing Livewire component or view |
| person import component shows preview and confirms import | ViewException | Missing Livewire component or view |
| import components are protected by RBAC | ViewException | Missing Livewire component or view

**File:** \`tests/Feature/ImportsLivewireTest.php\`" \
  --label "$LABEL"

# 5. KargoziniTest
gh issue create --repo "$REPO" \
  --title "Test Failures: KargoziniTest (6 failures)" \
  --body "## Test Failures

### Tests\Feature\KargoziniTest

| Test | Error | Root Cause |
|------|-------|------------|
| kargozini lookup pages render and allow CRUD with ('kargozini.estekhdam', 'estekhdams', 'name') | MethodNotFoundException | Missing method in test helper/component |
| kargozini lookup pages render and allow CRUD with ('kargozini.tahsil', 'tahsils', 'name') | MethodNotFoundException | Missing method in test helper/component |
| kargozini lookup pages render and allow CRUD with ('kargozini.semat', 'semats', 'name') | MethodNotFoundException | Missing method in test helper/component |
| kargozini lookup pages render and allow CRUD with ('kargozini.radif', 'radifs', 'name') | MethodNotFoundException | Missing method in test helper/component |
| persons page allows CRUD and respects organizational scope | Unknown | Scope enforcement issue |
| person search normalizes Persian characters | Unknown | Persian normalization issue in search

**File:** \`tests/Feature/KargoziniTest.php\`" \
  --label "$LABEL"

# 6. MapsVoltTest
gh issue create --repo "$REPO" \
  --title "Test Failures: MapsVoltTest (1 failure)" \
  --body "## Test Failures

### Tests\Feature\MapsVoltTest

| Test | Error | Root Cause |
|------|-------|------------|
| county map page renders shared map container | QueryException | SQLite schema mismatch (GIS tables)

**File:** \`tests/Feature/MapsVoltTest.php\`" \
  --label "$LABEL"

# 7. ReportsPagesTest
gh issue create --repo "$REPO" \
  --title "Test Failures: ReportsPagesTest (2 failures)" \
  --body "## Test Failures

### Tests\Feature\ReportsPagesTest

| Test | Error | Root Cause |
|------|-------|------------|
| reports reflect data presence and organizational scope | Unknown | Scope enforcement / data assertion issue |
| reports pages render Persian labels | Unknown | Persian text rendering issue

**File:** \`tests/Feature/ReportsPagesTest.php\`" \
  --label "$LABEL"

# 8. TicketsPagesTest
gh issue create --repo "$REPO" \
  --title "Test Failures: TicketsPagesTest (4 failures)" \
  --body "## Test Failures

### Tests\Feature\TicketsPagesTest

| Test | Error | Root Cause |
|------|-------|------------|
| tickets inbox renders tickets assigned to user | QueryException | SQLite schema mismatch / FK constraint |
| creating ticket persists data | PublicPropertyNotFoundException | Component property mismatch |
| monitoring page renders tickets and displays Persian status | QueryException | SQLite schema mismatch / FK constraint |
| ticket model helpers return expected values | Unknown | Model helper method issue

**File:** \`tests/Feature/TicketsPagesTest.php\`" \
  --label "$LABEL"

# 9. UsersManagementTest
gh issue create --repo "$REPO" \
  --title "Test Failures: UsersManagementTest (2 failures)" \
  --body "## Test Failures

### Tests\Feature\UsersManagementTest

| Test | Error | Root Cause |
|------|-------|------------|
| users index renders and filters results | QueryException | SQLite missing \`users.name\` column |
| create user persists data and assigns roles | ErrorException | Attempt to read property \"id\" on null (admin roles not seeded)

**File:** \`tests/Feature/UsersManagementTest.php\`" \
  --label "$LABEL"

echo "All 9 issues created successfully!"