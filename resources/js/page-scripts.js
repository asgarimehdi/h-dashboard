/**
 * Page-level scripts moved out of `@script` blocks (#1352, Step 3 / R3).
 *
 * WHY THIS EXISTS
 * ---------------
 * No `@script` body can execute under the CSP-safe Alpine build: the
 * CSP expression parser cannot parse Livewire's IIFE wrapper, so every
 * body fails with `Unexpected token: PUNCTUATION ")"` whatever its
 * content (Step-1 trivial probe). The bodies below are plain JS that
 * only ever ran because `@script` evaluated them outside the parser;
 * they move here verbatim, with two mechanical translations:
 *
 * - `$wire` (the component instance Livewire injects into `@script`
 *   scope) becomes `Livewire.find()` resolved from the input's
 *   enclosing `[wire:id]` root. Plain-JS `$wire` writes land
 *   server-side (proven by the picker probe); only
 *   parser-evaluated `$wire` assignments are dead.
 * - `@js(...)` server data becomes an inert JSON block in the
 *   template (the settings theme families).
 *
 * SCOPE NOTES
 * -----------
 * `Livewire.on('download-export')` was already global in the
 * hardware body, and `theme-poles-saved` is dispatched only by the
 * settings component, so both global registrations are
 * behavior-identical to the component-scoped originals. The Jalali
 * datepicker inits keep their `jdpBound` guards and their
 * `livewire:navigated` / morph re-runs.
 *
 * Entry points mirror map-pages.js: bootstrap calls
 * `setupPageScripts()` from its `alpine:init` handler, and this
 * module re-runs setup on `livewire:init` / `livewire:navigated`.
 * Every setup step is idempotent.
 */

let globalListenersRegistered = false;
let morphHookRegistered = false;
const patientWatchersRegistered = new Set();

function componentFor(element) {
  const root = element.closest('[wire\\:id]');
  if (!root || typeof window.Livewire === 'undefined') {
    return null;
  }
  return window.Livewire.find(root.getAttribute('wire:id'));
}

const JDP_OPTIONS = {
  time: false,
  hasSecond: false,
  format: 'YYYY/MM/DD',
  separatorChars: { date: '/', between: ' ', time: ':' },
};

function startJdpWatch() {
  if (typeof jalaliDatepicker === 'undefined') {
    return false;
  }
  jalaliDatepicker.startWatch(JDP_OPTIONS);
  return true;
}

// Disease index + create (#1182): every date input carries
// `data-jdp-model` naming its Livewire property.
function initDiseaseJdp(jdpReady) {
  if (!jdpReady) {
    return;
  }
  document.querySelectorAll('[data-jdp][data-jdp-model]').forEach((input) => {
    if (!input.dataset.jdpBound) {
      input.dataset.jdpBound = '1';
      input.addEventListener('jdp:change', (event) => {
        componentFor(input)?.set(input.dataset.jdpModel, event.target.value);
      });
    }
    const value = componentFor(input)?.get(input.dataset.jdpModel);
    if (value !== undefined && value !== null) {
      input.value = value;
    }
  });
}

// Activity log: two fixed date inputs.
function initActivityLogJdp(jdpReady) {
  if (!jdpReady) {
    return;
  }
  const bindings = [
    ['activity_log_date_from', 'dateFrom'],
    ['activity_log_date_to', 'dateTo'],
  ];
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

// Disease patients: single birth-date input, mirrored both ways.
function initPatientJdp(jdpReady) {
  if (!jdpReady) {
    return;
  }
  const input = document.getElementById('patient_birth_date');
  if (!input) {
    return;
  }
  if (!input.dataset.jdpBound) {
    input.dataset.jdpBound = '1';
    input.addEventListener('jdp:change', (event) => {
      componentFor(input)?.set('birth_date_jalali', event.target.value);
    });
  }
  const component = componentFor(input);
  input.value = component?.get('birth_date_jalali') ?? '';
  if (component) {
    const id = input.closest('[wire\\:id]')?.getAttribute('wire:id');
    if (id && !patientWatchersRegistered.has(id)) {
      patientWatchersRegistered.add(id);
      component.$watch?.('formOpen', () => initPatientJdp(startJdpWatch()));
      component.$watch?.('birth_date_jalali', (value) => {
        const el = document.getElementById('patient_birth_date');
        if (el) {
          el.value = value ?? '';
        }
      });
    }
  }
}

function registerGlobalListeners() {
  if (globalListenersRegistered || typeof window.Livewire === 'undefined') {
    return;
  }
  globalListenersRegistered = true;

  // Hardware export download.
  window.Livewire.on('download-export', (url) => {
    window.location.href = url;
  });

  // Settings (#1224 scenario 1): after saving new theme poles, an
  // applied theme that is not one of them would make the toggle's
  // icon and first click undefined. Migrate it to the same-family pole.
  window.Livewire.on('theme-poles-saved', ({ lightTheme, darkTheme }) => {
    const block = document.getElementById('theme-families-data');
    if (!block) {
      return;
    }
    let families;
    try {
      families = JSON.parse(block.textContent || '{}');
    } catch (e) {
      return;
    }
    const root = document.documentElement;
    const current = root.getAttribute('data-theme')
      || (localStorage.getItem('mary-theme') || '').replaceAll('"', '');
    const lightThemes = families.light || [];
    const darkThemes = families.dark || [];
    const poles = [lightTheme, darkTheme];
    if (!current || poles.includes(current)) {
      return;
    }
    const target = lightThemes.includes(current)
      ? lightTheme
      : (darkThemes.includes(current) ? darkTheme : lightTheme);
    root.setAttribute('data-theme', target);
    // MaryUI's $persist stores JSON, including the quotes — the head
    // script and the storage listener both strip them with
    // replaceAll('"').
    localStorage.setItem('mary-theme', JSON.stringify(target));
    // Keep the layout blobs and the HR charts in sync without a reload.
    window.dispatchEvent(new CustomEvent('theme-changed', { detail: target, bubbles: true }));
  });
}

export function setupPageScripts() {
  if (typeof window.Livewire === 'undefined') {
    return;
  }
  registerGlobalListeners();
  if (!morphHookRegistered) {
    morphHookRegistered = true;
    // Disease index + create re-init the datepickers after morphs.
    window.Livewire.hook('morphed', () => initDiseaseJdp(startJdpWatch()));
  }
  // One startWatch per run: the library call is global, and the
  // three inits below share it (each is a no-op on pages without
  // its inputs).
  const jdpReady = startJdpWatch();
  initDiseaseJdp(jdpReady);
  initActivityLogJdp(jdpReady);
  initPatientJdp(jdpReady);
}

document.addEventListener('livewire:init', setupPageScripts);
document.addEventListener('livewire:navigated', setupPageScripts);
