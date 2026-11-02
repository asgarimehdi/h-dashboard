import { registerMapStore } from './map-store';
import { registerMapHost } from './map-host';
import { setupMapPages } from './map-pages';
import { registerAppComponents } from './app-components';
import { registerMaryComponents } from './mary-components';
import { setupPageScripts } from './page-scripts';
import { setupReportCharts } from './report-charts';
import { setupTodoCalendar } from './todo-calendar';
import { setupDashboardCharts } from './dashboard-charts';
import { registerSignalGauge, setupZabbixPoller } from './zabbix-gauges';
import { registerMapDashboard } from './map-dashboard';
import { setupMapLayers } from './map-layers';

// The Leaflet lifecycle store (issue #028) is registered on `alpine:init`, not
// at module evaluation time: window.Alpine does not exist yet when this module
// runs, and the event always fires before the first x-data is initialised.
// Listening to the event also keeps this correct if @livewireScripts ever moves
// into <head>.
//
// The import must be STATIC. A dynamic import() here resolves in a microtask,
// which lands AFTER Livewire's start() has already dispatched alpine:init — the
// store would then register too late and every x-data init() would find no
// store, leaving maps silently blank.
document.addEventListener('alpine:init', () => {
  registerMapStore(window.Alpine);
  registerMapHost(window.Alpine);
  registerAppComponents(window.Alpine);
  registerMaryComponents(window.Alpine);
  registerSignalGauge(window.Alpine);
  registerMapDashboard(window.Alpine);
  // The store must exist before host-page setup subscribes to it;
  // this handler runs after registration, unlike a standalone
  // livewire:init listener, whose ordering vs alpine:init is not
  // guaranteed (measured: setup ran before the store existed and
  // silently never defined the county toggle handler).
  setupMapPages();
  setupMapLayers();
  setupPageScripts();
  setupReportCharts();
  setupTodoCalendar();
  setupDashboardCharts();
  setupZabbixPoller();
});
