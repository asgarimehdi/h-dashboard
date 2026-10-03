import { test, expect, login } from '../shared/fixtures';

/**
 * Issue #734 — the dashboard trend chart header says «۳۰ روز اخیر» (last 30
 * days) but the query ordered by `day` before applying `limit(30)`, so it
 * returned the OLDEST 30 days with data. With more than 30 days of seeded
 * history the axis never reached the present.
 *
 * Probed DOM facts:
 * - #ticketTrendChart renders Highcharts; categories are Jalali `m/d` labels.
 * - Categories/series come from `@json($this->ticketChartData)`, so we read
 *   them off the live Highcharts instance — the same array the chart plots —
 *   rather than guessing at SVG geometry after async init.
 *
 * Since #747 the window is materialised day by day (30 days ending today,
 * empty days as zeros), so the assertions check the
 * properties that define the fix:
 *   1. at most 30 buckets, and the series lines up with the categories;
 *   2. strictly ascending by real date (display order preserved);
 *   3. the window reaches the newest day in the data, not the oldest.
 */

const DAY_MS = 24 * 60 * 60 * 1000;

type TrendData = { categories: string[]; series: number[] };

/** Read the plotted categories/series off the live Highcharts instance. */
async function readTrendChartData(page: import('@playwright/test').Page): Promise<TrendData | null> {
  return page.evaluate(() => {
    const container = document.getElementById('ticketTrendChart');
    if (!container) return null;

    const charts = (window as any).Highcharts?.charts ?? [];
    const chart = charts.find((c: any) => c && c.renderTo === container);
    if (!chart) return null;

    return {
      categories: chart.xAxis[0].categories as string[],
      series: chart.series[0].data.map((p: any) => p.y as number),
    };
  });
}

const persianFormatter = new Intl.DateTimeFormat('en-US-u-ca-persian', {
  timeZone: 'Asia/Tehran',
  year: 'numeric',
  month: '2-digit',
  day: '2-digit',
});

function persianParts(d: Date) {
  const parts = persianFormatter.formatToParts(d);
  const get = (type: Intl.DateTimeFormatPartTypes) => parts.find((p) => p.type === type)!.value;
  return { year: Number(get('year')), month: Number(get('month')), day: Number(get('day')) };
}

/** `m/d` label the PHP side (`Jalalian::fromCarbon(...)->format('m/d')`) emits. */
function labelFor(d: Date): string {
  const { month, day } = persianParts(d);
  return `${String(month).padStart(2, '0')}/${String(day).padStart(2, '0')}`;
}

/**
 * Build a `m/d` label → Date index over the recent past, so resolving a label
 * never has to approximate Nowruz or hand-roll leap-year rules: a date counts
 * only if Intl formats it back to the same label. The LATEST occurrence wins,
 * because Jalali labels repeat every ~365 days and the chart window is a few
 * months wide — the newest match is the one inside it.
 *
 * `lookbackDays` must comfortably exceed the widest window under test: a label
 * whose only match is from a previous year would resolve to a date that looks
 * years stale and break the "reaches the newest day" assertion.
 */
function buildLabelIndex(now: Date, lookbackDays = 200): Map<string, Date> {
  const index = new Map<string, Date>();
  const day0 = Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate());

  for (let back = lookbackDays; back >= 0; back--) {
    index.set(labelFor(new Date(day0 - back * DAY_MS)), new Date(day0 - back * DAY_MS));
  }

  return index;
}

/** Whole days from `d` back to `reference`, ignoring the time of day. */
function daysBefore(d: Date, reference: Date): number {
  const a = Date.UTC(d.getUTCFullYear(), d.getUTCMonth(), d.getUTCDate());
  const b = Date.UTC(reference.getUTCFullYear(), reference.getUTCMonth(), reference.getUTCDate());
  return Math.round((b - a) / DAY_MS);
}

test.describe('dashboard ticket trend chart window (issue #734)', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await page.goto('/dashboard');
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(2500); // Highcharts async init
  });

  test('plots at most the 30 most recent days, oldest first', async ({ page }) => {
    const data = await readTrendChartData(page);

    expect(data, 'Highcharts did not initialise #ticketTrendChart').not.toBeNull();
    expect(data!.categories.length).toBeGreaterThan(0);
    expect(data!.categories.length).toBeLessThanOrEqual(30);
    expect(data!.series).toHaveLength(data!.categories.length);
  });

  test('categories are in ascending date order', async ({ page }) => {
    const data = await readTrendChartData(page);
    expect(data).not.toBeNull();

    const now = new Date();
    const index = buildLabelIndex(now);
    const days = data!.categories.map((c) => {
      const date = index.get(c);
      expect(date, `unresolved Jalali label ${c}`).toBeDefined();
      return daysBefore(date!, now);
    });

    // Strictly ascending: the fix must not reverse the axis.
    const ascending = days.every((v, i) => i === 0 || v < days[i - 1]);
    expect(ascending, `categories not ascending: ${data!.categories.join(', ')}`).toBe(true);
  });

  test('the window reaches the newest day in the data, not the oldest', async ({ page }) => {
    const data = await readTrendChartData(page);
    expect(data).not.toBeNull();

    // The most recent bucket must sit within a few days of today. Before the
    // fix the newest label was the last day of the OLDEST 30-day window, which
    // for the seeded ~90-day history is ~2 months back — nowhere near today.
    const now = new Date();
    const index = buildLabelIndex(now);
    const newestLabel = data!.categories.at(-1)!;
    const newestDate = index.get(newestLabel);
    expect(newestDate, `unresolved Jalali label ${newestLabel}`).toBeDefined();

    const ageInDays = daysBefore(newestDate!, now);
    expect(ageInDays, `newest label ${newestLabel} is ${ageInDays} days old`).toBeLessThanOrEqual(7);
  });
});
