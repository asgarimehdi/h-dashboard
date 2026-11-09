/**
 * App-owned Alpine components (#1352 CSP-compat).
 *
 * WHY THIS EXISTS
 * ---------------
 * Five app templates carried component logic in `x-data` object literals
 * and `x-init` attributes. The CSP-safe Alpine build evaluates directive
 * expressions with a small interpreter that rejects method shorthand,
 * `function` expressions, and arrow functions in those literals, so the
 * data objects evaluated EMPTY and the components never initialized
 * (theme toggle, toast, help modal, dashboard refresh, unit tree picker).
 *
 * The logic therefore lives here, in plain JS, registered as
 * `Alpine.data()` components on `alpine:init` (same static-import
 * discipline as the map store in bootstrap.js). Templates carry only
 * `x-data="<name>"` plus data attributes for server config. Directive
 * expressions in the templates use ONLY the Step-1 proven subset:
 * property access, assignments, method calls, $store/$dispatch/$refs,
 * ternaries, and :class/:style literal bindings.
 */

function readStoredTheme(key) {
  const raw = localStorage.getItem(key);
  if (raw == null) return null;
  try {
    return JSON.parse(raw);
  } catch (e) {
    // Legacy plain values: the pre-CSP inline script stripped quotes.
    return raw.replaceAll('"', '');
  }
}

export function registerAppComponents(Alpine) {
  // Theme toggle (components/csp-theme-toggle.blade.php). Persistence
  // matches the $persist format it replaces: JSON-stringified values
  // under the same keys, so the nonce'd no-flash script in the template
  // and this component read/write the same stored values.
  Alpine.data('themeToggle', () => ({
    theme: 'light',
    class: 'light',
    lightTheme: 'light',
    darkTheme: 'dark',
    lightClass: 'light',
    darkClass: 'dark',

    init() {
      const ds = this.$el.dataset;
      this.lightTheme = ds.lightTheme || 'light';
      this.darkTheme = ds.darkTheme || 'dark';
      this.lightClass = ds.lightClass || 'light';
      this.darkClass = ds.darkClass || 'dark';

      const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
      this.theme = readStoredTheme('mary-theme') ?? (prefersDark ? this.darkTheme : this.lightTheme);
      this.class = readStoredTheme('mary-class') ?? (prefersDark ? this.darkClass : this.lightClass);

      if (this.theme === this.darkTheme) {
        this.$refs.sun.classList.add('swap-off');
        this.$refs.sun.classList.remove('swap-on');
        this.$refs.moon.classList.add('swap-on');
        this.$refs.moon.classList.remove('swap-off');
      }
      this.setToggle();
    },

    setToggle() {
      document.documentElement.setAttribute('data-theme', this.theme);
      document.documentElement.setAttribute('class', this.class);
      try {
        localStorage.setItem('mary-theme', JSON.stringify(this.theme));
        localStorage.setItem('mary-class', JSON.stringify(this.class));
      } catch (e) {
        // Private mode / blocked storage must not break the toggle.
      }
      this.$dispatch('theme-changed', this.theme);
      this.$dispatch('theme-changed-class', this.class);
    },

    toggle() {
      this.theme = this.theme === this.lightTheme ? this.darkTheme : this.lightTheme;
      this.class = this.theme === this.lightTheme ? this.lightClass : this.darkClass;
      this.setToggle();
    },
  }));

  // Toast (components/csp-toast.blade.php, decision D3). The mary-toast
  // listener is attached in plain JS: `@mary-toast.window="start(...)"`
  // would pass the payload through the parser. Title/description render
  // with x-text (plain text); the icon is static markup.
  //
  // #1377 — the `toast` function itself. MaryUI's Toast trait emits a BARE
  // `toast({...})` JS expression from `$this->js(...)` (vendor/.../Toast.php:33),
  // and the CSP-safe Livewire build resolves bare identifiers against the
  // evaluator scope ONLY — never window (livewire.csp.js:5311-5322, closestDataStack
  // at 2965). The old `window.toast` in the inline <script nonce> of
  // csp-toast.blade.php was therefore invisible to Livewire's evaluator and
  // every toast was silently dropped. This method lives on the layout-level
  // Alpine data (`<body x-data="...">`), whose scope walks up to the nearest
  // `_x_dataStack` ancestor — so it is reachable from ANY descendant Livewire
  // component's `$this->js('toast({...})')`. The inline script is deleted;
  // `window.toast` is undefined BY DESIGN (do not assert it is a function).
  Alpine.data('globalToast', () => ({
    toast(payload) {
      window.dispatchEvent(new CustomEvent('mary-toast', { detail: payload }));
    },

    // #1379 — «اعلان مرورگر» toggle (livewire/settings/index.blade.php).
    // The old logic lived in the `x-on:change` expression, where Livewire's
    // CSP evaluator threw on `'Notification' in window` (tokenises `in`,
    // never evaluates it) and on the arrow callbacks. Plain JS here is never
    // CSP-parsed; the directive is a bare call.
    //
    // The notify helpers are Livewire component methods that only toast, so
    // every failure path must also reset the bound property or the toggle
    // stays ON after a denial / an unsupported browser.
    toggleBrowserNotifications(checked) {
      if (!checked) return; // user un-checked: plain uncheck, no toast
      if (typeof Notification === 'undefined') {
        this.$wire.set('browserNotifications', false);
        this.$wire.notifyUnsupported();
        return;
      }
      Notification.requestPermission().then((p) => {
        if (p !== 'granted') {
          this.$wire.set('browserNotifications', false);
          this.$wire.notifyPermissionDenied();
        }
      });
    },
  }));

  Alpine.data('cspToast', () => ({
    show: false,
    toast: {},
    timer: null,
    interval: null,

    maxProgress: 100,
    progress: 100,
    initialProgress: 0,

    startTime: 0,
    remaining: 0,

    _toastListener: null,

    init() {
      this._toastListener = (event) => this.start(event.detail.toast);
      window.addEventListener('mary-toast', this._toastListener);
    },

    destroy() {
      if (this._toastListener) {
        window.removeEventListener('mary-toast', this._toastListener);
        this._toastListener = null;
      }
      this.clearTimers();
    },

    start(toast) {
      this.clearTimers();

      this.toast = toast;
      this.progress = this.maxProgress;

      this.remaining = toast.timeout;

      this.initialProgress = this.progress;
      this.startTime = Date.now();

      // delay for DOM initiation
      setTimeout(() => { this.show = true; }, 50);

      this.startProgress();
      this.startCloseTimer();
    },

    startProgress() {
      if (this.toast.noProgress) return;

      const intervalRefreshRate = 8;
      this.startTime = Date.now();

      const startProgressValue = this.initialProgress;

      this.interval = setInterval(() => {
        const elapsed = Date.now() - this.startTime;
        const ratio = elapsed / this.remaining;

        this.progress = startProgressValue * (1 - ratio);

        if (this.progress <= 0) {
          this.progress = 0;
          clearInterval(this.interval);
          this.interval = null;
        }
      }, intervalRefreshRate);
    },

    startCloseTimer() {
      this.startTime = Date.now();

      this.timer = setTimeout(() => {
        this.close();
      }, this.remaining);
    },

    pause() {
      if (!this.show) return;

      const elapsed = Date.now() - this.startTime;
      this.remaining -= elapsed;

      this.initialProgress = this.progress;

      this.clearTimers();
    },

    resume() {
      if (!this.show || this.remaining <= 0) return;

      this.startProgress();
      this.startCloseTimer();
    },

    close() {
      this.show = false;
      this.clearTimers();
    },

    dismiss() {
      this.show = false;
      clearInterval(this.interval);
    },

    clearTimers() {
      clearTimeout(this.timer);
      clearInterval(this.interval);
      this.timer = null;
      this.interval = null;
    },
  }));

  // Help modal (components/help/modal.blade.php). The x-init listener
  // (arrow function + optional chaining) cannot run under the CSP
  // build, so it lives here. noHelpContent() keeps the fallback
  // paragraph's array check out of the template expression.
  Alpine.data('helpModal', () => ({
    helpSection: 'dashboard',

    _helpListener: null,

    init() {
      this.helpSection = this.$el.dataset.section || 'dashboard';
      this._helpListener = (event) => {
        if (event.detail && event.detail.section) {
          this.helpSection = event.detail.section;
        }
      };
      document.addEventListener('help-open', this._helpListener);
    },

    destroy() {
      if (this._helpListener) {
        document.removeEventListener('help-open', this._helpListener);
        this._helpListener = null;
      }
    },

    noHelpContent() {
      return !['dashboard', 'hardware', 'hardware-import', 'persons-import', 'personnel', 'units', 'tickets', 'todos', 'reports', 'maps', 'settings', 'roles', 'permissions', 'users', 'activity-log', 'networks', 'wireless', 'tools', 'search', 'profile', 'hr-dashboard'].includes(this.helpSection);
    },
  }));

  // Dashboard stats refresh (livewire/dashboard.blade.php).
  Alpine.data('dashboardRefresh', () => ({
    interval: 0,
    timer: null,

    init() {
      this.interval = parseInt(this.$el.dataset.interval || '0', 10) || 0;
      if (this.interval > 0) {
        this.timer = setInterval(() => this.$wire.refreshStats(), this.interval);
      }
    },

    destroy() {
      if (this.timer) {
        clearInterval(this.timer);
        this.timer = null;
      }
    },
  }));

  // Unit tree picker (livewire/partials/unit-tree-picker.blade.php).
  // The $watch calls from the old x-init (arrows) live in init() as
  // plain-JS getter functions, so no parser is involved. `selected`
  // is seeded from $wire in init() and kept in sync by the $watch —
  // the same contract the x-init watches provided.
  Alpine.data('unitTreePicker', () => ({
    search: '',
    open: false,
    expanded: {},
    matchedIds: [],
    autoExpanded: [],
    flat: [],
    multiple: false,
    alwaysOpen: false,
    selected: null,
    modelName: '',

    init() {
      const ds = this.$el.dataset;
      this.modelName = ds.model || '';
      this.multiple = ds.multiple === 'true';
      this.alwaysOpen = ds.alwaysOpen === 'true';
      this.open = ds.open === 'true';
      const flatBlock = this.$el.querySelector('script.picker-flat-data');
      try {
        this.flat = JSON.parse((flatBlock ? flatBlock.textContent : '') || '[]');
      } catch (e) {
        console.warn('[picker] invalid flat JSON block, starting empty', e);
        this.flat = [];
      }
      if (this.modelName) {
        this.selected = this.$wire[this.modelName];
        this.$watch(
          () => this.$wire[this.modelName],
          (value) => { this.selected = value; },
        );
      }
      this.$watch(
        () => this.search,
        () => this.expandForSearch(),
      );
    },

    isSelected(id) {
      if (this.multiple) return Array.isArray(this.selected) && this.selected.map(Number).includes(Number(id));
      return String(this.selected) === String(id);
    },

    toggle(id) {
      if (this.multiple) {
        const arr = Array.isArray(this.selected) ? this.selected : [];
        const idx = arr.findIndex((x) => String(x) === String(id));
        if (idx === -1) this.$wire[this.modelName] = [...arr, Number(id)];
        else this.$wire[this.modelName] = arr.filter((x) => String(x) !== String(id));
      } else {
        this.$wire[this.modelName] = Number(id);
        if (!this.alwaysOpen) this.open = false;
      }
    },

    toggleExpanded(id) {
      this.expanded[id] = !this.expanded[id];
    },

    remove(id) {
      if (this.multiple) {
        this.$wire[this.modelName] = (this.selected ?? []).filter((x) => String(x) !== String(id));
      } else {
        this.$wire[this.modelName] = null;
        if (!this.alwaysOpen) this.open = false;
      }
    },

    nameOf(id) { return this.flat.find((u) => String(u.id) === String(id))?.name ?? '-'; },

    isMatched(id) {
      return this.matchedIds.some((m) => String(m) === String(id));
    },

    parentOf(id) {
      const u = this.flat.find((x) => String(x.id) === String(id));
      return u && u.parent_id !== null && u.parent_id !== undefined ? u.parent_id : null;
    },

    rowClass(id) {
      return (this.isSelected(id) ? 'font-bold text-primary' : '') + (this.isMatched(id) ? 'bg-warning/25' : '');
    },

    expandForSearch() {
      // Undo auto-expansions from the previous query so manual state is preserved
      this.autoExpanded.forEach((id) => { delete this.expanded[id]; });
      this.autoExpanded = [];
      this.matchedIds = [];
      const s = this.search.trim().toLowerCase();
      if (!s) return;
      const matches = this.flat.filter((u) => u.name.toLowerCase().includes(s)).map((u) => u.id);
      this.matchedIds = matches;
      // Expand every ancestor chain of each match so results are actually visible
      const ancestors = new Set();
      matches.forEach((id) => {
        const guard = new Set([Number(id)]);
        let cur = id;
        while (true) {
          const p = this.parentOf(cur);
          if (p === null || guard.has(Number(p))) break;
          guard.add(Number(p));
          ancestors.add(p);
          cur = p;
        }
      });
      ancestors.forEach((id) => {
        this.expanded[id] = true;
        this.autoExpanded.push(id);
      });
    },
  }));
}
