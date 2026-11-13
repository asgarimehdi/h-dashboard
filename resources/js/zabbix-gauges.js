// @ts-check
/**
 * Zabbix signal gauges + the shared wireless poller, moved out of
 * `@script` blocks (#1352, Step 3 / R3).
 *
 * WHY THIS EXISTS
 * ---------------
 * No `@script` body can execute under the CSP-safe Alpine build (the
 * CSP expression parser cannot parse Livewire's IIFE wrapper). The
 * bodies below are plain JS that only ever ran because `@script`
 * evaluated them outside the parser. They move here with mechanical
 * translations:
 *
 * - The traffic chart's `x-data` called `initTrafficChart(...)` as a
 *   global too — the same dead call, missed in the first wave and
 *   fixed in #1469 (`registerTrafficChart`).
 * - The gauge template called the factory as a global function in
 *   its `x-data` (`window.signalGauge(...)`). Global-function calls
 *   in directives are DEAD under the CSP build (Step-1 global-call
 *   probe), so the factory becomes an `Alpine.data('signalGauge')`
 *   registration and the nine arguments move to `data-*`
 *   attributes, read in `init()` from `this.$el.dataset`. The
 *   object shape — fields, getters, `init`/`apply`/`destroy` —
 *   is verbatim.
 * - The poller's `@js($this->itemIds())` becomes one inert JSON
 *   block (`#wireless-item-ids`) rendered inside the wireless
 *   root. The fetch loop, chunking, #703 error mapping, timer
 *   reset, and publish/subscribe contract are verbatim.
 *
 * The shared state (`window.zabbixGauges`) is created idempotently
 * at module scope, so a gauge may initialise before the poller
 * (the original comment's requirement), on either side.
 *
 * Entry points: bootstrap registers the factory on `alpine:init`
 * and calls `setupZabbixPoller()` there; this module re-runs the
 * poller setup on `livewire:init` / `livewire:navigated` (each run
 * clears the previous timer and resets the state, matching the
 * original IIFE).
 */

function ensureGaugeState() {
  // Shared state: whoever runs first creates it; gauges may be
  // built before the page poller, so creating it must be
  // idempotent on both sides.
  return (window.zabbixGauges = window.zabbixGauges || {
    values: {},
    error: null,
    loading: false,
    loaded: false,
    subscribers: [],
    timer: null,
  });
}

ensureGaugeState();

/** @param {AlpineStatic} Alpine */
export function registerSignalGauge(Alpine) {
  Alpine.data('signalGauge', () => ({
    signalItemId: '',
    frequencyItemId: '',
    responseTimeItemId: '',
    min: 0,
    max: 100,
    unit: '%',
    frequencyUnit: 'MHz',
    responseTimeUnit: 'ms',
    title: '',
    /** @type {number | null} */
    signal: null,
    /** @type {number | null} */
    frequency: null,
    /** @type {number | null} */
    responseTime: null,
    loading: false,
    /** @type {string | null} */
    error: null,
    circumference: 2 * Math.PI * 45,
    /** @type {(() => void) | null} */
    bound: null,

    init() {
      const ds = this.$el.dataset;
      this.signalItemId = ds.signalItemId || '';
      this.frequencyItemId = ds.frequencyItemId || '';
      this.responseTimeItemId = ds.responseTimeItemId || '';
      const min = parseFloat(ds.min ?? '');
      const max = parseFloat(ds.max ?? '');
      this.min = Number.isFinite(min) ? min : 0;
      this.max = Number.isFinite(max) ? max : 100;
      this.unit = ds.unit || '%';
      this.frequencyUnit = ds.frequencyUnit || 'MHz';
      this.responseTimeUnit = ds.responseTimeUnit || 'ms';
      this.title = ds.title || '';

      const state = ensureGaugeState();
      this.bound = this.apply.bind(this);
      state.subscribers.push(this.bound);
      this.apply();
    },

    /** Reads the values from the shared state; the only place that touches data. */
    apply() {
      const state = ensureGaugeState();
      const values = state.values || {};

      this.loading = state.loading;
      this.signal = values[this.signalItemId] ?? null;
      this.frequency = values[this.frequencyItemId] ?? null;
      this.responseTime = values[this.responseTimeItemId] ?? null;

      this.error = state.error;

      // Until the first fetch completes, "no data found" is premature;
      // previously only "—" was shown in that state.
      if (
        !this.error &&
        state.loaded &&
        this.signal === null &&
        this.frequency === null &&
        this.responseTime === null
      ) {
        this.error = 'داده‌ای یافت نشد';
      }
    },

    get displaySignal() {
      return this.signal !== null ? this.signal.toFixed(1) : '—';
    },

    get displayFrequency() {
      return this.frequency !== null ? this.frequency.toFixed(1) : '—';
    },

    get displayResponseTime() {
      if (this.responseTime === null) {
        return '—';
      }
      const ms = this.responseTime * 1000;
      return ms >= 1 ? Math.round(ms) : ms.toFixed(1);
    },

    get responseTimeColorClass() {
      if (this.responseTime === null) {
        return 'text-base-content';
      }
      const ms = this.responseTime * 1000;
      if (ms < 3) {
        return 'text-success';
      }
      if (ms >= 3 && ms <= 7) {
        return 'text-warning';
      }
      return 'text-error';
    },

    get dashOffset() {
      if (this.signal === null) {
        return this.circumference;
      }
      const percent = Math.min(100, Math.max(0, ((this.signal - this.min) / (this.max - this.min)) * 100));
      return this.circumference - (percent / 100) * this.circumference;
    },

    destroy() {
      const state = window.zabbixGauges;
      if (!state || !this.bound) {
        return;
      }
      const at = state.subscribers.indexOf(this.bound);
      if (at !== -1) {
        state.subscribers.splice(at, 1);
      }
    },
  }));
}

/**
 * Traffic chart factory (#1469), the same migration as `signalGauge` above.
 *
 * The chart template called `initTrafficChart(...)` as a global in its
 * `x-data`, which is DEAD under the CSP build (no `window` globals are
 * visible to the expression parser: `Undefined variable: initTrafficChart`).
 * No chart was created, no fetch ran, and `error` was never set — 25 blank
 * charts on /it/networks, including the #703 friendly message. The factory is
 * an `Alpine.data('trafficChart')` registration now; the item ids, the title
 * and the initial duration move to `data-*` and are read in `init()`. The
 * object shape — `start`/`reload`/`load`/`destroy` and the #703 mapping — is
 * verbatim.
 *
 * @param {AlpineStatic} Alpine
 */
export function registerTrafficChart(Alpine) {
  Alpine.data('trafficChart', () => ({
    outItemId: '',
    inItemId: '',
    chartTitle: '',
    duration: 3600,
    loading: false,
    /** @type {string | null} */
    error: null,
    /** @type {any} */
    chart: null,
    /** @type {ReturnType<typeof setInterval> | null} */
    timer: null,

    init() {
      const ds = this.$el.dataset;
      this.outItemId = ds.outItemId || '';
      this.inItemId = ds.inItemId || '';
      this.chartTitle = ds.title || 'Traffic';
      const duration = parseInt(ds.duration ?? '', 10);
      this.duration = Number.isFinite(duration) ? duration : 3600;

      this.start();
    },

    start() {
      this.$nextTick(() => {
        const container = this.$refs.container;
        if (!container) {
          return;
        }

        container.innerHTML = '';

        this.chart = Highcharts.chart(container, {
          chart: {
            type: 'spline',
            backgroundColor: 'transparent',
            animation: false,
          },
          title: {
            text: this.chartTitle,
            style: { color: 'var(--color-primary)' },
          },
          xAxis: { type: 'datetime' },
          yAxis: { title: { text: 'Mbps' } },
          tooltip: { valueSuffix: ' Mbps' },
          credits: { enabled: false },
          accessibility: { enabled: false },
          series: [
            {
              name: 'Outgoing',
              data: [],
              color: 'var(--color-warning)',
            },
            {
              name: 'Incoming',
              data: [],
              color: 'var(--color-primary)',
            },
          ],
        });

        this.load();
        // ۶۰ ثانیه، نه ۱۰: هر نمودار یک درخواست به `/api/zabbix/traffic`
        // می‌زند و این مسیر زیر `throttle:api-user` است (۶۰ درخواست در
        // دقیقه برای کل کاربر). با ۲۵ شبکه و چرخهٔ ۱۰ ثانیه‌ای یعنی
        // ۱۵۰ درخواست در دقیقه و ۴۲۹ قطعی. بازهٔ زمانی نمودار از ۳۰
        // دقیقه شروع می‌شود، پس تازه‌سازی دقیقه‌ای کافی است.
        this.timer = setInterval(() => this.load(), 60000);
      });
    },

    reload() {
      this.load(true);
    },

    /** @param {boolean} [show] */
    async load(show = false) {
      if (!this.chart) {
        return;
      }
      if (show) {
        this.loading = true;
      }
      this.error = null;

      try {
        const url = `/api/zabbix/traffic?out_item_id=${this.outItemId}&in_item_id=${this.inItemId}&duration=${this.duration}`;
        const res = await fetch(url);

        // #703: 503 means Zabbix is unreachable — show the requested
        // friendly message, not a silent empty chart. Other statuses
        // keep their existing behaviour so real bugs stay visible.
        if (res.status === 503) {
          this.error = 'دسترسی به سرور مقدور نمی باشد';
          return;
        }

        const data = await res.json();

        if (this.chart && this.chart.series) {
          this.chart.series[0].setData(data.out || [], false);
          this.chart.series[1].setData(data.in || [], false);
          this.chart.redraw(false);
        }
      } catch (e) {
        console.error(e);

        // #703: a TypeError means the request never reached the
        // server (offline / network down) — the same condition.
        if (e instanceof TypeError) {
          this.error = 'دسترسی به سرور مقدور نمی باشد';
          return;
        }

        this.error = 'خطا در دریافت داده‌های ترافیک';
      } finally {
        if (show) {
          this.loading = false;
        }
      }
    },

    destroy() {
      if (this.timer) {
        clearInterval(this.timer);
      }
      if (this.chart) {
        this.chart.destroy();
        this.chart = null;
      }
    },
  }));
}

export function setupZabbixPoller() {
  // One request for all the page's gauges, instead of one per gauge.
  //
  // Why: `/api/zabbix/multi-latest` is under `throttle:api-user`
  // (60 requests per minute for the whole user, not per page). With
  // 14 devices, each gauge's 30-second loop made 28 requests per
  // minute and the page fell over with 429 "Too Many Attempts"
  // after a few seconds. Now each cycle = one request.
  const block = document.getElementById('wireless-item-ids');
  if (!block) {
    return;
  }
  /** @type {string[]} */
  let itemIds = [];
  try {
    itemIds = JSON.parse(block.textContent || '[]');
  } catch {
    itemIds = [];
  }

  const state = ensureGaugeState();
  const REFRESH_MS = 30000;
  // The endpoint's validation cap = 100 items per request.
  const CHUNK = 90;

  if (state.timer) {
    clearInterval(state.timer);
    state.timer = null;
  }
  state.values = {};
  state.error = null;
  state.loaded = false;

  const publish = () => {
    state.subscribers.slice().forEach((/** @type {() => void} */ subscriber) => subscriber());
  };

  const load = async () => {
    if (!itemIds.length || state.loading) {
      return;
    }

    state.loading = true;
    publish();

    try {
      const token = localStorage.getItem('token');
      /** @type {Record<string, string>} */
      const headers = token ? { Authorization: `Bearer ${token}` } : {};

      const values = {};

      for (let offset = 0; offset < itemIds.length; offset += CHUNK) {
        const params = new URLSearchParams();
        itemIds.slice(offset, offset + CHUNK).forEach((id, index) => params.append(`item_ids[${index}]`, id));

        const response = await fetch(`/api/zabbix/multi-latest?${params.toString()}`, { headers });

        if (!response.ok) {
          // #703: a 503 from our API means Zabbix itself is
          // unreachable. That is NOT an internal error — it is a
          // server we cannot talk to, so show the requested
          // friendly message instead of "خطای HTTP 503".
          if (response.status === 503) {
            state.error = 'دسترسی به سرور مقدور نمی باشد';
            return;
          }

          let errorMsg = `خطای HTTP ${response.status}`;
          try {
            const text = await response.text();
            try {
              const errorData = JSON.parse(text);
              if (errorData.message) {
                errorMsg = errorData.message;
              }
            } catch {
              errorMsg = text.substring(0, 100);
            }
          } catch {
            /* unreadable body — keep the HTTP status message */
          }
          state.error = errorMsg;
          return;
        }

        Object.assign(values, await response.json());
      }

      state.values = values;
      state.error = null;
    } catch (e) {
      console.error('Error fetching values:', e);

      // #703: a TypeError from fetch() means the request never
      // reached the server (network down / server offline), which
      // is the same "cannot reach the server" condition as a 503.
      state.error =
        e instanceof TypeError ? 'دسترسی به سرور مقدور نمی باشد' : /** @type {Error} */ (e).message || 'خطا در دریافت';
    } finally {
      state.loading = false;
      state.loaded = true;
      publish();
    }
  };

  load();
  state.timer = setInterval(load, REFRESH_MS);
}

document.addEventListener('livewire:init', setupZabbixPoller);
document.addEventListener('livewire:navigated', setupZabbixPoller);
