# Plan 010 — GIS Maps E2E Tests

**Priority:** P1 · **Base:** `a737a5b` · **Status:** planned · **Depends:** 001

## Why
Leaflet + PostGIS maps (6 pages), 3 layers (units/hardware/tickets), 8 counties. Maps
are notorious for layout/rendering bugs (half-width canvas, broken overlays). AGENTS.md
documents a known `invalidateSize()` + `relative` container gotcha.

## Scenarios (tests/e2e/maps/)

| # | Page | Case | Expected |
|---|------|------|----------|
| 1 | /map | GIS dashboard loads | map canvas renders |
| 2 | /map | toggle layer units/hardware/tickets | markers change |
| 3 | /maps/unit | unit map | markers at unit lat/lng |
| 4 | /maps/route | route map | polyline draws |
| 5 | /maps/route2 | find path | route rendered |
| 6 | /maps/county | county map | boundary polygons (8 counties) |
| 7 | /maps/point | point map | points plotted |
| 8 | all | map not half-width | canvas width == container width |

## Files
`tests/e2e/maps/layers.spec.ts`, `routes.spec.ts`, `county.spec.ts`

## Done criteria
```bash
npx playwright test tests/e2e/maps/ --reporter=list
```

## Note
Assert marker counts via Leaflet's rendered DOM (`.leaflet-marker-icon`) rather than
the JS map object. Maps load async — use generous `waitForTimeout` or poll for markers.