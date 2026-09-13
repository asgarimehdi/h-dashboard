# Audit Plans — Health Dashboard

**Base commit:** `a106d38`
**Generated:** 2026-09-13

## Status Table

| # | Plan | Priority | Effort | Status | Dependencies |
|---|---|---|---|---|---|
| 025 | [Dashboard SQL Injection](025-dashboard-sql-injection.md) | CRITICAL | M | Ready | — |
| 026 | [Hardware Audit Restore ID](026-hardware-audit-restore.md) | HIGH | S | Ready | — |
| 027 | [API Report Permissions](027-api-report-permissions.md) | HIGH | S | Ready | — |
| 028 | [N+1 Queries](028-n1-queries.md) | MEDIUM | M | Ready | — |
| 029 | [Dead Code Removal](029-dead-code.md) | LOW | M | Ready | — |
| 030 | [Failing Tests](030-failing-tests.md) | HIGH | M | Ready | — |
| 031 | [User Fillable Password](031-user-fillable.md) | MEDIUM | S | Ready | — |
| 032 | [CSP Header](032-csp-header.md) | MEDIUM | M | Ready | — |
| 033 | [Map Token Reuse](033-map-token.md) | MEDIUM | S | Ready | — |

## Recommended Execution Order

```
030 (fix tests first — establish green baseline)
  ↓
025 (SQL injection — highest security impact)
  ↓
027 (report permissions — authorization gap)
  ↓
026 (hardware restore — correctness bug)
  ↓
031 (user fillable — security hardening)
  ↓
032 (CSP header — defense in depth)
  ↓
033 (map token — performance)
  ↓
028 (N+1 queries — performance)
  ↓
029 (dead code — tech debt, lowest priority)
```

### Dependency Notes

- **030 first:** Fix tests before any code changes to ensure a green baseline.
- **025 → 029:** If plan 029 removes the `HardwareUpdated` event, it shouldn't conflict with 025 (different file). No hard dependency.
- **031:** Has wide blast radius (touches many test files). Do after test baseline is stable.
- **028 and 033:** Independent performance optimizations, can be done in parallel.
- **032:** Independent security header, can be done anytime.
