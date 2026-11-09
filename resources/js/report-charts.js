/**
 * Highcharts report pages moved out of `@script` blocks (#1352, Step 3 / R3).
 *
 * WHY THIS EXISTS
 * ---------------
 * No `@script` body can execute under the CSP-safe Alpine build (the
 * CSP expression parser cannot parse Livewire's IIFE wrapper). The
 * four report bodies below are plain JS that only ever ran because
 * `@script` evaluated them outside the parser; they move here
 * verbatim, with two mechanical translations:
 *
 * - The four bodies each declared their own `waitForHighcharts` /
 *   `destroyChart` / `render` globals. In one bundle those names
 *   collide, so the shared helpers live here once and the per-page
 *   renderers are named per page.
 * - `$wire.$watch(...)` / `$wire.$id` become `Livewire.find()` resolved
 *   from the data element's enclosing `[wire:id]` root, with the
 *   `morphed` hook guarded on the same id (the originals compared
 *   `component.id === $wire.$id`).
 *
 * The chart payloads were already DOM data (`data-chart` attributes
 * rendered by inline `@php`), so no template data plumbing changed:
 * each render re-reads its data element, which the morph replaces
 * on every server render (issue #1042).
 *
 * Entry points mirror map-pages.js: bootstrap calls
 * `setupReportCharts()` from its `alpine:init` handler, and this
 * module re-runs setup on `livewire:init` / `livewire:navigated`.
 * Rendering is idempotent (each render destroys the previous chart
 * in its container first); watcher/hook registration is guarded
 * once per component id.
 */

const PIE_COLORS_10 = ['#6366f1', '#f59e0b', '#10b981', '#ef4444', '#8b5cf6', '#06b6d4', '#f97316', '#ec4899', '#14b8a6', '#a855f7'];
const PIE_COLORS_8 = ['#6366f1', '#f59e0b', '#10b981', '#ef4444', '#8b5cf6', '#06b6d4', '#f97316', '#ec4899'];
const PIE_COLORS_7 = ['#6366f1', '#f59e0b', '#10b981', '#ef4444', '#8b5cf6', '#06b6d4', '#f97316'];

const watchersRegistered = new Set();
const hooksRegistered = new Set();

function componentFor(element) {
  const root = element.closest('[wire\\:id]');
  if (!root || typeof window.Livewire === 'undefined') {
    return null;
  }
  return window.Livewire.find(root.getAttribute('wire:id'));
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

function destroyChart(id) {
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

function renderPie(containerId, data, colors) {
  destroyChart(containerId);
  const labels = Object.keys(data || {});
  const values = Object.values(data || {});
  if (labels.length > 0) {
    Highcharts.chart(containerId, {
      chart: { type: 'pie' },
      title: { text: '' },
      series: [{ name: 'تعداد', data: labels.map((l, i) => ({ name: l, y: values[i], color: colors[i % colors.length] })) }],
      credits: { enabled: false },
      plotOptions: { pie: { dataLabels: { enabled: true, format: '{point.name}: {y}' } } },
    });
  }
}

function readChartData(elementId) {
  const el = document.getElementById(elementId);
  return el ? JSON.parse(el.dataset.chart) : {};
}

function renderPersonsCharts() {
  waitForHighcharts(() => {
    // Issue #858: read the server-rendered aggregates — the inline
    // @php already ran the payload for this render, so a second
    // method request would just re-run it.
    const data = readChartData('personsChartData');
    renderPie('tahsilChart', data.byTahsil, PIE_COLORS_10);
    renderPie('sematChart', data.bySemat, PIE_COLORS_10);
    renderPie('estekhdamChart', data.byEstekhdam, PIE_COLORS_10);
  });
}

function renderUnitsCharts() {
  waitForHighcharts(() => {
    // Issue #1042: read the server-rendered aggregates — the inline
    // @php already ran the payload for this render, so a second
    // method request would just re-run it.
    const data = readChartData('unitsChartData');
    destroyChart('typeChart');
    const labels = Object.keys(data.byType || {});
    const values = Object.values(data.byType || {});
    if (labels.length > 0) {
      Highcharts.chart('typeChart', {
        chart: { type: 'pie' },
        title: { text: '' },
        series: [{
          name: 'تعداد',
          data: labels.map((l, i) => ({ name: l, y: values[i], color: PIE_COLORS_8[i % PIE_COLORS_8.length] })),
        }],
        credits: { enabled: false },
        plotOptions: {
          pie: { dataLabels: { enabled: true, format: '{point.name}: {y}' } },
        },
      });
    }
  });
}

function renderTodosCharts() {
  waitForHighcharts(() => {
    // Issue #1042: read the server-rendered aggregates — the inline
    // @php already ran the payload for this render, so a second
    // method request would just re-run it.
    const data = readChartData('todosChartData');
    // Status pie
    destroyChart('statusChart');
    const statusLabels = Object.keys(data.byStatus || {});
    const statusValues = Object.values(data.byStatus || {});
    const colors = ['#10b981', '#f59e0b', '#ef4444'];
    if (statusLabels.length > 0) {
      Highcharts.chart('statusChart', {
        chart: { type: 'pie' },
        title: { text: '' },
        series: [{ name: 'تعداد', data: statusLabels.map((l, i) => ({ name: l, y: statusValues[i], color: colors[i] })) }],
        credits: { enabled: false },
        plotOptions: { pie: { dataLabels: { enabled: true, format: '{point.name}: {y}' } } },
      });
    }
    // Unit pie
    renderPie('unitChart', data.byUnit, PIE_COLORS_8);
  });
}

function renderAdvancedCharts() {
  waitForHighcharts(() => {
    // Issue #1042: read the server-rendered aggregates — the view
    // already ran reportData for this render, so a second method
    // request would just re-run it.
    const reportData = readChartData('advancedChartData');
    destroyChart('trendChart');
    destroyChart('unitChart');
    destroyChart('estekhdamChart');
    destroyChart('tahsilChart');
    destroyChart('sematChart');
    if (reportData.byDay && reportData.byDay.length > 0) {
      Highcharts.chart('trendChart', {
        chart: { type: 'areaspline' },
        title: { text: '' },
        xAxis: { categories: reportData.byDay.map((d) => d.day) },
        yAxis: { title: { text: 'تعداد' } },
        series: [{
          name: 'تعداد',
          data: reportData.byDay.map((d) => d.count),
          color: '#6366f1',
          fillColor: { linearGradient: { x1: 0, y1: 0, x2: 0, y2: 1 }, stops: [[0, 'rgba(99,102,241,0.3)'], [1, 'rgba(99,102,241,0)']] },
        }],
        credits: { enabled: false },
      });
    }
    const unitLabels = Object.keys(reportData.byUnit || {});
    const unitValues = Object.values(reportData.byUnit || {});
    if (unitLabels.length > 0) {
      Highcharts.chart('unitChart', {
        chart: { type: 'pie' },
        title: { text: '' },
        series: [{ name: 'تعداد', data: unitLabels.map((label, i) => ({ name: label, y: unitValues[i] })) }],
        credits: { enabled: false },
      });
    }
    const details = reportData.details || {};
    renderPie('estekhdamChart', details.byEstekhdam, PIE_COLORS_7);
    renderPie('tahsilChart', details.byTahsil, PIE_COLORS_7);
    renderPie('sematChart', details.bySemat, PIE_COLORS_7);
  });
}

const JDP_OPTIONS = {
  time: false,
  hasSecond: false,
  format: 'YYYY/MM/DD',
  separatorChars: { date: '/', between: ' ', time: ':' },
};

function initJdpPair(fromId, toId, fromModel, toModel) {
  if (typeof jalaliDatepicker === 'undefined') {
    return;
  }
  jalaliDatepicker.startWatch(JDP_OPTIONS);
  const bindings = [[fromId, fromModel], [toId, toModel]];
  bindings.forEach(([id, model]) => {
    const input = document.getElementById(id);
    if (!input) {
      return;
    }
    if (!input.dataset.jdpBound) {
      input.dataset.jdpBound = '1';
      input.addEventListener('jdp:change', (event) => {
        componentFor(input)?.set(model, event.target.value);
      });
    }
  });
}

function registerPage(dataElementId, render, watchProps, jdpPair) {
  const el = document.getElementById(dataElementId);
  if (!el) {
    return;
  }
  render();
  const component = componentFor(el);
  if (!component) {
    return;
  }
  const id = el.closest('[wire\\:id]')?.getAttribute('wire:id');
  if (!id) {
    return;
  }
  if (!watchersRegistered.has(id)) {
    watchersRegistered.add(id);
    // $watch fires when the reactive store patches (before Livewire
    // morphs the fresh data-chart into the DOM), so render again
    // after morph via the hook below. The date watchers stay
    // because the Jalali datepicker writes the properties via set.
    watchProps.forEach((prop) => component.$watch?.(prop, () => render()));
  }
  if (!hooksRegistered.has(id)) {
    hooksRegistered.add(id);
    Livewire.hook('morphed', ({ component: morphed }) => {
      if (morphed.id === id) {
        render();
      }
    });
  }
  if (jdpPair) {
    initJdpPair(...jdpPair);
  }
}

export function setupReportCharts() {
  registerPage('personsChartData', renderPersonsCharts,
    ['selectedUnitId', 'selectedTahsilId', 'selectedSematId', 'selectedEstekhdamId'], null);
  registerPage('unitsChartData', renderUnitsCharts, [], null);
  registerPage('todosChartData', renderTodosCharts, ['dateFrom', 'dateTo'],
    ['todo_date_from', 'todo_date_to', 'dateFrom', 'dateTo']);
  registerPage('advancedChartData', renderAdvancedCharts, ['dateFrom', 'dateTo'],
    ['advanced_report_date_from', 'advanced_report_date_to', 'dateFrom', 'dateTo']);
}

document.addEventListener('livewire:init', setupReportCharts);
document.addEventListener('livewire:navigated', setupReportCharts);
