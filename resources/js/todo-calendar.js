/**
 * Todo calendar moved out of its `@script` block (#1352, Step 3 / R3).
 *
 * WHY THIS EXISTS
 * ---------------
 * No `@script` body can execute under the CSP-safe Alpine build (the
 * CSP expression parser cannot parse Livewire's IIFE wrapper). The
 * calendar body is plain JS that only ever ran because `@script`
 * evaluated it outside the parser; it moves here verbatim, with
 * mechanical translations:
 *
 * - `@js($calendarWindow)` / `@json($events)` become one inert JSON
 *   block (`#todo-calendar-data`) rendered inside the component
 *   root, next to `#calendar`. (A block after the root close tag is
 *   dropped by Livewire — chunk-5 lesson.)
 * - `@this.method(...)` becomes `component.call('method', ...)` on
 *   the component resolved from `#calendar`'s enclosing `[wire:id]`
 *   root; `Livewire.find(@js($this->getId()))` resolves the same way.
 * - The `Livewire.on('calendar-updated')` and
 *   `Livewire.hook('element.initialized')` registrations are
 *   once-guarded: the module re-runs setup on every navigation,
 *   while the original registered them once per `@script`
 *   evaluation.
 *
 * The `#891` `domNodes` event-content construction is kept exactly:
 * user-controlled values are assigned with `.textContent`, never
 * concatenated into an HTML string. The `#1169` seeded-window
 * skip-first-`datesSet` logic is kept exactly.
 *
 * Entry points mirror map-pages.js: bootstrap calls
 * `setupTodoCalendar()` from its `alpine:init` handler, and this
 * module re-runs setup on `livewire:init` / `livewire:navigated`.
 * Setup destroys any previous calendar instance first, matching
 * the `@script` body's opening destroy.
 */

let globalListenersRegistered = false;

function componentFor(element) {
  const root = element.closest('[wire\\:id]');
  if (!root || typeof window.Livewire === 'undefined') {
    return null;
  }
  return window.Livewire.find(root.getAttribute('wire:id'));
}

function readCalendarData() {
  const block = document.getElementById('todo-calendar-data');
  if (!block) {
    return { events: [], window: null };
  }
  try {
    const parsed = JSON.parse(block.textContent || '{}');
    return { events: parsed.events || [], window: parsed.window || null };
  } catch (e) {
    return { events: [], window: null };
  }
}

function initTodoJdp() {
  if (typeof jalaliDatepicker !== 'undefined') {
    jalaliDatepicker.startWatch({
      time: false,
      hasSecond: false,
      format: 'YYYY/MM/DD',
      separatorChars: {
        date: '/',
        between: ' ',
        time: ':',
      },
    });
  }
}

function initTodoCalendar() {
  const calendarEl = document.getElementById('calendar');
  if (!calendarEl) {
    return;
  }
  if (typeof FullCalendar === 'undefined') {
    return;
  }

  if (window.calendarInstance) {
    window.calendarInstance.destroy();
    window.calendarInstance = null;
  }

  // Issue #1169: the server seeded the same window the month grid shows,
  // so the `datesSet` callback that fires on load must not ask for it
  // again. Timestamps are compared — the server sends ISO 8601 with a UTC
  // offset — and only the first callback is skipped, so navigating back
  // to the seeded month still refetches.
  const { events, window: seededWindow } = readCalendarData();
  const seededStart = seededWindow?.start ? Date.parse(seededWindow.start) : null;
  const seededEnd = seededWindow?.end ? Date.parse(seededWindow.end) : null;
  let firstPaint = true;

  const component = componentFor(calendarEl);

  window.calendarInstance = new FullCalendar.Calendar(calendarEl, {
    initialView: 'dayGridMonth',
    locale: 'fa',
    direction: 'rtl',
    firstDay: 6,
    headerToolbar: {
      left: 'prev,next today',
      center: 'title',
      right: 'dayGridMonth,timeGridWeek,timeGridDay',
    },
    buttonText: {
      today: 'امروز',
      month: 'ماهانه',
      week: 'هفتگی',
      day: 'روزانه',
      list: 'لیست',
    },
    allDayText: 'تمام روز',
    moreLinkText: 'بیشتر',
    noEventsText: 'رویدادی برای نمایش وجود ندارد',
    views: {
      dayGridMonth: {
        titleFormat: { year: 'numeric', month: 'long' },
      },
      timeGridWeek: {
        titleFormat: { year: 'numeric', month: 'long', day: 'numeric' },
      },
      timeGridDay: {
        titleFormat: { year: 'numeric', month: 'long', day: 'numeric' },
      },
    },
    selectable: true,
    editable: true,
    eventContent: function (arg) {
      const type = arg.event.extendedProps.type || 'todo';

      // Issue #891: every user-controlled value below (event title,
      // time text, status) is assigned with `.textContent`, never
      // concatenated into an HTML string. The previous return built
      // that string and handed it to FullCalendar as raw markup,
      // which the library assigns through `dangerouslySetInnerHTML`
      // — so a `create_ticket` holder could plant a `Todo::title`
      // (or a `Ticket::subject` copied into one by the ticket-create
      // page) and have it execute in every calendar viewer's browser.
      // `domNodes` removes the sink rather than escaping its
      // contents: there is no string left to escape incorrectly, so
      // the bug cannot recur.
      if (type === 'ticket') {
        const status = arg.event.extendedProps.status || '';

        const row = document.createElement('div');
        row.className = 'flex items-center gap-1';

        const icon = document.createElement('span');
        icon.className = 'text-sm';
        icon.textContent = '🎫';
        row.appendChild(icon);

        const title = document.createElement('span');
        title.className = 'fc-event-title text-xs';
        // Strip only the marker this component prepends server-side.
        title.textContent = arg.event.title.replace('🎫 ', '');

        const badge = document.createElement('span');
        badge.className = 'badge badge-xs badge-ghost';
        badge.textContent = status;

        title.appendChild(document.createTextNode(' '));
        title.appendChild(badge);
        row.appendChild(title);

        return { domNodes: [row] };
      }

      const todoId = arg.event.id.replace('todo-', '');
      const row = document.createElement('div');
      row.className = 'flex items-center gap-1';

      const isCompleted = !!arg.event.extendedProps.is_completed;
      const NS = 'http://www.w3.org/2000/svg';

      const checkIcon = document.createElementNS(NS, 'svg');
      checkIcon.setAttribute('fill', 'none');
      checkIcon.setAttribute('viewBox', '0 0 24 24');
      checkIcon.setAttribute('stroke-width', '2');
      checkIcon.setAttribute('stroke', 'currentColor');
      checkIcon.setAttribute('aria-hidden', 'true');
      checkIcon.style.width = '1rem';
      checkIcon.style.height = '1rem';
      checkIcon.style.cursor = 'pointer';
      checkIcon.classList.add(isCompleted ? 'text-success' : 'text-base-content/40');
      if (!isCompleted) {
        checkIcon.classList.add('hover:text-success');
      }

      const circle = document.createElementNS(NS, 'path');
      circle.setAttribute('stroke-linecap', 'round');
      circle.setAttribute('stroke-linejoin', 'round');
      circle.setAttribute('d', 'M21 12a9 9 0 11-18 0 9 9 0 0118 0z');

      const tick = document.createElementNS(NS, 'path');
      tick.setAttribute('stroke-linecap', 'round');
      tick.setAttribute('stroke-linejoin', 'round');
      tick.setAttribute('d', 'M9 12.75L11.25 15 15 9.75');

      // The completed and open states draw the same circle + tick and
      // differ only in colour, which the class list above carries.
      checkIcon.appendChild(circle);
      checkIcon.appendChild(tick);

      // Issue #891: the toggle used to be an inline `onclick`
      // attribute. That pattern is what the report-only CSP's
      // 'unsafe-inline' permits, so it is removed in the same change.
      checkIcon.addEventListener('click', (ev) => {
        ev.stopPropagation();
        component?.call('toggleComplete', parseInt(todoId, 10));
      });

      row.appendChild(checkIcon);

      const label = document.createElement('span');
      label.className = 'fc-event-title';
      label.textContent = arg.timeText + ' ' + arg.event.title;
      row.appendChild(label);

      return { domNodes: [row] };
    },
    events,
    datesSet: function (info) {
      // Issue #1169: the initial callback repeats the window the
      // server just rendered. Only that first callback is a no-op;
      // month/week/day navigation always refetches.
      const initial = firstPaint;
      firstPaint = false;

      if (initial
        && seededStart !== null
        && seededEnd !== null
        && Date.parse(info.startStr) === seededStart
        && Date.parse(info.endStr) === seededEnd) {
        return;
      }

      component?.call('fetchEvents', info.startStr, info.endStr);
    },
    select: function (info) {
      component?.call('openCreateModal', info.startStr, info.endStr);
    },
    eventClick: function (info) {
      const type = info.event.extendedProps.type;
      if (type === 'ticket') {
        window.location.href = '/tickets/inbox';
      } else {
        component?.call('editEvent', info.event.id.replace('todo-', ''));
      }
    },
    eventDrop: function (info) {
      const type = info.event.extendedProps.type;
      if (type === 'todo') {
        const todoId = info.event.id.replace('todo-', '');
        component?.call('updateTodoDates', todoId, info.event.startStr, info.event.endStr);
      }
    },
  });

  window.calendarInstance.render();
}

function registerGlobalListeners() {
  if (globalListenersRegistered || typeof window.Livewire === 'undefined') {
    return;
  }
  globalListenersRegistered = true;

  Livewire.on('calendar-updated', (...args) => {
    const events = Array.isArray(args[0]) ? args[0] : (args[0]?.events || []);
    if (window.calendarInstance && events.length) {
      window.calendarInstance.removeAllEvents();
      events.forEach((event) => window.calendarInstance.addEvent(event));
    }
  });

  Livewire.hook('element.initialized', (el, component) => {
    if (el.id === 'start_date_picker' || el.id === 'end_date_picker') {
      setTimeout(() => {
        if (typeof jalaliDatepicker !== 'undefined') {
          const inputs = document.querySelectorAll('[data-jdp]');
          inputs.forEach((input) => {
            input.removeAttribute('data-jdp-initialized');
          });
          jalaliDatepicker.startWatch();
        }
      }, 200);
    }
  });
}

export function setupTodoCalendar() {
  if (window.calendarInstance) {
    window.calendarInstance.destroy();
    window.calendarInstance = null;
  }
  registerGlobalListeners();
  initTodoCalendar();
  initTodoJdp();
}

document.addEventListener('livewire:init', setupTodoCalendar);
document.addEventListener('livewire:navigated', setupTodoCalendar);
