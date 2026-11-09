/**
 * Dashboard + HR-dashboard Highcharts moved out of `@script` blocks
 * (#1352, Step 3 / R3).
 *
 * WHY THIS EXISTS
 * ---------------
 * No `@script` body can execute under the CSP-safe Alpine build (the
 * CSP expression parser cannot parse Livewire's IIFE wrapper). Both
 * bodies are plain JS that only ever ran because `@script` evaluated
 * them outside the parser; they move here verbatim, with mechanical
 * translations:
 *
 * - Dashboard: `@json($this->ticketChartData)` /
 *   `@json($this->ticketStatusData)` become one inert JSON block
 *   (`#dashboard-chart-data`) rendered inside the component root,
 *   next to the chart containers. Each render re-reads the block,
 *   so a theme switch after a morph paints the fresh payload —
 *   the same direction the #1042 report pattern already chose
 *   (the original re-used the init-time literal).
 * - HR: `await $wire.chartPayload()` becomes
 *   `component.call('chartPayload')` on the component resolved
 *   from a chart container's enclosing `[wire:id]` root.
 *   `$wire.$on('$refresh')` is kept when the component object
 *   offers `$on`; the `morphed` hook (same id guard as the
 *   reports) covers the refresh path regardless.
 * - Both `theme-changed` window listeners are once-guarded: the
 *   module re-runs setup on every navigation, while the originals
 *   registered once per `@script` evaluation.
 *
 * Entry points mirror map-pages.js: bootstrap calls
 * `setupDashboardCharts()` from its `alpine:init` handler, and
 * this module re-runs setup on `livewire:init` / `livewire:navigated`.
 */

const themeListenersRegistered = new Set();
const hrHooksRegistered = new Set();
const hrOnRegistered = new Set();
let hrChartData = null;

function componentFor(element) {
  const root = element.closest('[wire\\:id]');
  if (!root || typeof window.Livewire === 'undefined') {
    return null;
  }
  return window.Livewire.find(root.getAttribute('wire:id'));
}

/* ---------------- main dashboard: ticket charts ---------------- */

const statusLabels = { created: 'جدید', accepted: 'پذیرفته شده', completed: 'تکمیل شده', forwarded: 'ارجاع شده', rejected: 'رد شده' };

// #1224: series colours follow the active theme family. daisyUI's
// `color-scheme` is the same light/dark signal App\Support\ThemeFamilies
// uses, so a newly enabled theme needs no entry here. The old hard-coded
// sky-500 line measured 2.74:1 on the light poles (below the 3:1
// non-text minimum); the pairs below clear 4:1 on every enabled theme.
const CHART_COLORS = {
  light: {
    trend: '#0284c7',
    created: '#2563eb',
    accepted: '#b45309',
    completed: '#15803d',
    forwarded: '#6d28d9',
    rejected: '#b91c1c',
    text: '#3f3f46',
  },
  dark: {
    trend: '#38bdf8',
    created: '#60a5fa',
    accepted: '#fbbf24',
    completed: '#4ade80',
    forwarded: '#a78bfa',
    rejected: '#f87171',
    text: '#d4d4d8',
  },
};

function chartPalette() {
  const style = getComputedStyle(document.documentElement);
  const scheme = (style.colorScheme || '').includes('dark') ? 'dark' : 'light';
  return {
    ...CHART_COLORS[scheme],
    text: style.getPropertyValue('--color-base-content').trim() || CHART_COLORS[scheme].text,
  };
}

function waitForHighcharts(callback, attempts = 0) {
  if (typeof window.Highcharts !== 'undefined') {
    callback();
    return;
  }
  if (attempts > 100) {
    console.error('Highcharts failed to load');
    return;
  }
  setTimeout(() => waitForHighcharts(callback, attempts + 1), 50);
}

function destroyChartEl(container) {
  if (!container || typeof Highcharts === 'undefined') {
    return;
  }
  const existing = Highcharts.charts?.find((c) => c && c.renderTo === container);
  if (existing) {
    existing.destroy();
  }
  container.innerHTML = '';
}

function renderTicketCharts() {
  waitForHighcharts(() => {
    const block = document.getElementById('dashboard-chart-data');
    let payload = {};
    try {
      payload = block ? JSON.parse(block.textContent || '{}') : {};
    } catch (e) {
      payload = {};
    }
    const trendData = payload.trend || {};
    const statusData = payload.status || {};
    const palette = chartPalette();

    const trendContainer = document.getElementById('ticketTrendChart');
    const statusContainer = document.getElementById('ticketStatusChart');

    if (!trendContainer || !statusContainer) {
      return;
    }

    destroyChartEl(trendContainer);
    destroyChartEl(statusContainer);

    Highcharts.chart(trendContainer, {
      chart: { type: 'areaspline', backgroundColor: 'transparent' },
      title: { text: null },
      credits: { enabled: false },
      xAxis: {
        categories: trendData.categories?.length ? trendData.categories : ['بدون داده'],
        labels: { style: { fontSize: '10px' } },
      },
      yAxis: { title: { text: 'تعداد' }, min: 0, allowDecimals: false },
      plotOptions: {
        areaspline: {
          fillColor: {
            linearGradient: { x1: 0, y1: 0, x2: 0, y2: 1 },
            stops: [[0, Highcharts.color(palette.trend).setOpacity(0.3).get('rgba')], [1, Highcharts.color(palette.trend).setOpacity(0.05).get('rgba')]],
          },
          lineColor: palette.trend,
          marker: { enabled: false },
        },
      },
      series: [{ name: 'تیکت‌ها', data: trendData.series?.length ? trendData.series : [0], color: palette.trend }],
      legend: { enabled: false },
    });

    const pieData = Object.keys(statusData).length
      ? Object.entries(statusData).map(([key, val]) => ({ name: statusLabels[key] || key, y: val, color: palette[key] || '#6b7280' }))
      : [{ name: 'بدون داده', y: 0, color: '#6b7280' }];

    Highcharts.chart(statusContainer, {
      chart: { type: 'pie', backgroundColor: 'transparent' },
      title: { text: null },
      credits: { enabled: false },
      plotOptions: {
        pie: {
          innerSize: '55%',
          dataLabels: {
            enabled: true,
            format: '<b>{point.name}</b>: {point.y}',
            // Highcharts defaults to #333 with a white halo —
            // unreadable on the dark poles.
            style: { color: palette.text, textOutline: 'none' },
          },
        },
      },
      series: [{ name: 'تیکت‌ها', data: pieData }],
    });
  });
}

/* ---------------- HR dashboard: bar charts ---------------- */

// Bars need a per-family palette (daisyUI's own `color-scheme` is the same
// signal App\Support\ThemeFamilies uses: light/cupcake/fantasy = light,
// dark/synthwave/coffee/luxury = dark). Text and grid read the active
// theme's tokens, so a newly enabled theme needs no table entry here.
const HR_CHART_COLORS = {
  light: ['#4f46e5', '#047857', '#0369a1', '#b45309'],
  dark: ['#a78bfa', '#34d399', '#38bdf8', '#fbbf24'],
};

function hrPalette() {
  const root = document.documentElement;
  const style = getComputedStyle(root);
  const scheme = (style.colorScheme || '').includes('dark') ? 'dark' : 'light';
  const token = (name, fallback) => style.getPropertyValue(name).trim() || fallback;
  return {
    colors: HR_CHART_COLORS[scheme],
    text: token('--color-base-content', scheme === 'dark' ? '#d4d4d8' : '#3f3f46'),
    grid: token('--color-base-300', scheme === 'dark' ? '#3f3f46' : '#e4e4e7'),
  };
}

function destroyHrChart(id) {
  const container = document.getElementById(id);
  if (!container || typeof Highcharts === 'undefined') {
    return;
  }
  const existing = Highcharts.charts?.find((c) => c && c.renderTo === container);
  if (existing) {
    existing.destroy();
  }
  container.innerHTML = '';
}

function renderHrBar(id, rows, color, palette) {
  destroyHrChart(id);
  const data = (rows || [])
    .slice()
    .sort((a, b) => b.total - a.total)
    .map((r) => ({ name: r.name, y: r.total }));

  if (!data.length || typeof Highcharts === 'undefined') {
    return;
  }

  Highcharts.chart(id, {
    chart: { type: 'bar' },
    title: { text: '' },
    xAxis: {
      type: 'category',
      labels: { style: { fontSize: '12px', color: palette.text } },
    },
    yAxis: {
      title: { text: 'تعداد', style: { color: palette.text } },
      labels: { style: { color: palette.text } },
      gridLineColor: palette.grid,
      allowDecimals: false,
      minRange: 1,
    },
    legend: { enabled: false },
    credits: { enabled: false },
    tooltip: { style: { color: palette.text } },
    series: [{
      name: 'تعداد پرسنل',
      data,
      color,
      dataLabels: {
        enabled: true,
        align: 'left',
        format: '{y}',
        style: { color: palette.text },
      },
    }],
  });
}

// Cached so a theme switch repaints instantly without a server round-trip.
function paintHrCharts() {
  if (!hrChartData) {
    return;
  }
  const palette = hrPalette();
  renderHrBar('hrChartByUnit', hrChartData.byUnit, palette.colors[0], palette);
  renderHrBar('hrChartBySemat', hrChartData.bySemat, palette.colors[1], palette);
  renderHrBar('hrChartByTahsil', hrChartData.byTahsil, palette.colors[2], palette);
  renderHrBar('hrChartByEstekhdam', hrChartData.byEstekhdam, palette.colors[3], palette);
}

async function renderHrCharts() {
  const anchor = document.getElementById('hrChartByUnit');
  const component = anchor ? componentFor(anchor) : null;
  if (!component) {
    return;
  }
  hrChartData = await component.call('chartPayload');
  paintHrCharts();
}

function waitForHighchartsHr(fn) {
  if (typeof Highcharts !== 'undefined') {
    return fn();
  }
  setTimeout(() => waitForHighchartsHr(fn), 100);
}

function setupHrCharts() {
  const anchor = document.getElementById('hrChartByUnit');
  if (!anchor) {
    return;
  }
  waitForHighchartsHr(renderHrCharts);
  const component = componentFor(anchor);
  const id = anchor.closest('[wire\\:id]')?.getAttribute('wire:id');
  if (component && id) {
    if (!hrOnRegistered.has(id)) {
      hrOnRegistered.add(id);
      component.$on?.('$refresh', () => renderHrCharts());
    }
    if (!hrHooksRegistered.has(id) && typeof window.Livewire !== 'undefined') {
      hrHooksRegistered.add(id);
      Livewire.hook('morphed', ({ component: morphed }) => {
        if (morphed.id === id) {
          renderHrCharts();
        }
      });
    }
  }
  if (!themeListenersRegistered.has('hr')) {
    themeListenersRegistered.add('hr');
    // maryUI x-theme-toggle dispatches this bubbling CustomEvent on switch.
    window.addEventListener('theme-changed', () => paintHrCharts());
  }
}

export function setupDashboardCharts() {
  if (document.getElementById('dashboard-chart-data')) {
    // Runs on full page load and Livewire wire:navigate.
    renderTicketCharts();
    if (!themeListenersRegistered.has('dashboard')) {
      themeListenersRegistered.add('dashboard');
      // maryUI x-theme-toggle dispatches this bubbling CustomEvent on switch.
      window.addEventListener('theme-changed', renderTicketCharts);
    }
  }
  setupHrCharts();
}

document.addEventListener('livewire:init', setupDashboardCharts);
document.addEventListener('livewire:navigated', setupDashboardCharts);
