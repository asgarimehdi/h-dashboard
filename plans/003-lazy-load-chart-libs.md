# Plan 003: Load chart JS assets only on pages that need them
Drift: git diff --stat 25c206a4..HEAD -- resources/
Commit: 25c206a4 | Priority: P2 | Effort: S | Risk: LOW | Category: perf

## Why
Highcharts + treemap/treegraph/exporting (~800KB) loaded on every page including login/settings. Only /dashboard and /units/chart need them.

## Current state
app.blade.Php:17-21 loads all chart libs unconditionally.

## Steps
### 1. Add @stack('scripts') before </body>
In resources/views/components/layouts/app.blade.php, add @stack('scripts') before the closing </body> tag.
Verify: grep -n "@stack.*scripts" resources/views/components/layouts/app.blade.php

### 2. Remove chart script tags from layout
Delete lines 17-21 (highcharts. js, treemap. js, treegraph. js, exporting. js) from app.blade.php.
Verify: grep -n "highcharts" resources/views/components/layouts/app.blade.php (no match)

### 3. Push libs from dashboard
In resources/views/livewire/dashboard.blade.php, add at bottom:
@push('scripts')
<script src="{{ asset('js/chart/highcharts. js') }}"></script>
<script src="{{ asset('js/chart/treemap. js') }}"></script>
<script src="{{ asset('js/chart/treegraph. js') }}"></script>
<script src="{{ asset('js/chart/exporting. js') }}"></script>
@endpush
Verify: grep -n "@push" resources/views/livewire/dashboard.blade.php

### 4. Push libs from chart view
Same as step 3, in resources/views/livewire/units/chart.blade.php.
Verify: grep -n "@push" resources/views/livewire/units/chart.blade.php

### 5. Smoke test
Open /dashboard and /units/chart — charts render normally.

## Done
- [ ] grep highcharts in app.blade.php returns nothing
- [ ] grep @push in dashboard.blade.php returns a match
- [ ] grep @push in units/chart.blade.php returns a match
- [ ] /dashboard renders without JS errors
- [ ] /units/chart renders without JS errors
- [ ] Non-chart pages load no chart JS (browser network tab)

## STOP
Stop if chart page fails to render after push. Stop if @stack is not accepted by Livewire Volt (some Volt pages use inline script — in that case use @this->addScript() or alternative).