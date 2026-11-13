/**
 * Ambient type surface for the `resources/js` bundle (issue #1438).
 *
 * Type-only: nothing here is imported at runtime, nothing is emitted
 * (`noEmit`), and Vite never resolves this file — it is erased before the
 * build. It exists so `tsc` can reason about identifiers the bundle obtains
 * from outside its own module graph (vendor `<script>` tags in Blade) and
 * about the properties Alpine injects into a component instance.
 *
 * Keep this a global script: no top-level import/export. One `export {}` turns
 * the whole file into a module and every declaration below stops being global.
 */

interface AlpineMagicProperties {
    /**
     * The element that carries the directive being evaluated — on a handler
     * bound to a child that child is `$el`, NOT the `x-data` root (measured in
     * #1469: a click handler on the toggle's <input> saw the input's dataset).
     */
    $el: HTMLElement;
    /** The component root element (`x-data`). Read root-level `data-*` here. */
    $root: HTMLElement;
    /**
     * `x-ref` lookups. Deliberately `any`: a ref's concrete type is decided by
     * the Blade template that carries `x-ref`, which tsc cannot see, and the
     * call sites span input value/checked, style, focus(), children and
     * querySelectorAll. Typing it `HTMLElement` would reject correct code.
     */
    $refs: Record<string, any>;
    /** The owning Livewire component (see LivewireComponent). */
    $wire: LivewireComponent;
    /** Alpine stores; `map` is the one owned by map-store.js. */
    $store: { map: MapStore } & Record<string, any>;
    $dispatch(event: string, detail?: unknown): void;
    $watch(property: string, callback: (value: any, oldValue: any) => void): void;
    $nextTick(callback?: () => void): Promise<void>;
}

/**
 * `Alpine.data(name, factory)` is the only place tsc can learn that a
 * component object receives the magics: the object literal's `this` is the
 * literal's own type, so a standalone `interface` can never add `$el` to it.
 * `ThisType` is what injects them, which is why the `Alpine` parameter of each
 * `register*` function has to carry `@param {AlpineStatic}`.
 */
interface AlpineStatic {
    data<T extends object>(name: string, factory: () => T & ThisType<T & AlpineMagicProperties>): void;
    store(name: 'map'): MapStore;
    store<T extends object>(name: string, value: T & ThisType<T>): void;
    magic(name: string): void;
    plugin(plugin: unknown): void;
    initTree(root?: HTMLElement): void;
}

interface MapStore {
    config: Record<string, any>;
    configure(config?: Record<string, any>): void;
    get(): any;
    use(el: HTMLElement): any;
    onReady(callback: (map: any) => void): void;
    flush(): void;
    release(el?: HTMLElement | null, options?: { force?: boolean }): void;
}

/**
 * The server component's public properties and methods are not discoverable
 * from the JS side, so the index signature is load-bearing: `$wire.set`,
 * `$wire.refreshStats`, `$wire.saveBoundary` and friends all live here.
 */
interface LivewireComponent {
    $id: string;
    $refresh(): void;
    $set(path: string, value: unknown): void;
    $get(path: string): any;
    $watch(path: string, callback: (value: any, oldValue: any) => void): void;
    $on(event: string, callback: (...args: any[]) => void): void;
    $removeUpload(name: string, tmpFilename: string, callback?: () => void): void;
    upload(name: string, file: File): Promise<any>;
    call(method: string, ...params: any[]): Promise<any>;
    dispatch(event: string, params?: Record<string, unknown>): void;
    [key: string]: any;
}

interface LivewireGlobal {
    find(id: string): LivewireComponent | undefined;
    on(event: string, callback: (...args: any[]) => void): () => void;
    hook(name: string, callback: (payload: any) => void): void;
    dispatch(event: string, params?: Record<string, unknown>): void;
    component(name: string): LivewireComponent | undefined;
}

// Vendored globals — loaded by Blade `<script src="{{ asset('js/…') }}">`

/** Leaflet + leaflet-draw + leaflet-routing-machine (public/js/leaflet/). */
declare const L: any;

/** Highcharts (public/js/chart/highcharts.js). */
declare const Highcharts: {
    chart(target: string | HTMLElement, options: Record<string, any>): any;
    charts?: Array<{ renderTo: HTMLElement } | undefined>;
    color(value: string): { setOpacity(opacity: number): { get(format: string): string } };
};

/** jalaliDatepicker (public/js/other/jalalidatepicker.min.js). */
declare const jalaliDatepicker: {
    startWatch(options?: Record<string, unknown>): void;
    stopWatch(): void;
};

/** full-calendar (public/js/other/full-calendar.min.js). */
declare const FullCalendar: {
    Calendar: new (el: HTMLElement | string, options: Record<string, unknown>) => any;
};

/** Cropper (cropper.js, loaded on the upload partials). */
declare const Cropper: new (el: HTMLImageElement, options: Record<string, unknown>) => any;

declare const Livewire: LivewireGlobal;

// `window.*` extensions: 74 of the 745 baseline errors. Not in the issue's
// 11-item list, but nothing goes green without them — the bundle parks
// cross-component state on `window`.

interface Window {
    Alpine: AlpineStatic;
    Livewire?: LivewireGlobal;
    Highcharts?: typeof Highcharts;
    /** todo-calendar.js: one Calendar instance per page. */
    calendarInstance?: any;
    /** zabbix-gauges.js: gauge registry. */
    zabbixGauges?: Record<string, any>;
    /** map-layers.js: boundary-editor bridge called from Blade `onclick`. */
    _mapGeojson?: string | null;
    _drawnItems?: any;
    toggleGeoJson?: (regionId: number | string) => void;
    toggleGeoJsonOn?: (unitId: number | string) => void;
    toggleGeoJsonOff?: (unitId: number | string) => void;
    saveMapBoundary?: () => void;
}
