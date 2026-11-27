import { describe, expect, it } from 'vitest';

import { registerMaryComponents } from '../../resources/js/mary-components.js';

// The component factory is registered through `Alpine.data()`; a minimal stub
// captures it without mounting Alpine. `optionValueKey()` reads `$el.dataset`,
// so every instance gets a bare element stand-in.
function makeChoices({ single = false, options = [], selection = null } = {}) {
  let factory;
  registerMaryComponents({
    data: (name, cb) => {
      if (name === 'maryChoices') factory = cb;
    },
  });

  const choices = factory();
  choices.$el = { dataset: {} };
  choices.isSingle = single;
  choices.options = options;
  choices.selection = selection;

  return choices;
}

describe('maryChoices single selection', () => {
  it('selectedOptions matches the option loosely (string selection vs numeric id)', () => {
    const options = [
      { id: 1, label: 'A' },
      { id: 2, label: 'B' },
    ];
    const choices = makeChoices({ single: true, options, selection: '1' });

    expect(choices.selectedOptions).toEqual([{ id: 1, label: 'A' }]);
  });

  it('isSelectionEmpty is true for null and empty string', () => {
    expect(makeChoices({ single: true, selection: null }).isSelectionEmpty).toBe(true);
    expect(makeChoices({ single: true, selection: '' }).isSelectionEmpty).toBe(true);
    expect(makeChoices({ single: true, selection: 5 }).isSelectionEmpty).toBe(false);
  });

  it('honours a custom option value key', () => {
    const choices = makeChoices({ single: true, options: [{ value: 'a', label: 'A' }], selection: 'a' });
    choices.$el.dataset.optionValue = 'value';

    expect(choices.selectedOptions).toEqual([{ value: 'a', label: 'A' }]);
  });
});

describe('maryChoices multi selection', () => {
  it('maps each selected value to its option, keeping order', () => {
    const options = [
      { id: 1, label: 'A' },
      { id: 2, label: 'B' },
    ];
    const choices = makeChoices({ single: false, options, selection: [2, 1] });

    expect(choices.selectedOptions).toEqual([
      { id: 2, label: 'B' },
      { id: 1, label: 'A' },
    ]);
  });

  it('pins the current behaviour: a value absent from options yields undefined', () => {
    // Known rough edge, pinned deliberately (issue #1944 finding) — do NOT fix here.
    const choices = makeChoices({ single: false, options: [{ id: 1, label: 'A' }], selection: [1, 3] });

    expect(choices.selectedOptions).toEqual([{ id: 1, label: 'A' }, undefined]);
  });

  it('isAllSelected is true when both lists are empty', () => {
    // Known rough edge, pinned deliberately (issue #1944 finding) — do NOT fix here.
    const choices = makeChoices({ single: false, options: [], selection: [] });

    expect(choices.isAllSelected).toBe(true);
    expect(choices.isSelectionEmpty).toBe(true);
  });

  it('isAllSelected follows the option count', () => {
    const options = [
      { id: 1, label: 'A' },
      { id: 2, label: 'B' },
    ];

    expect(makeChoices({ options, selection: [1, 2] }).isAllSelected).toBe(true);
    expect(makeChoices({ options, selection: [1] }).isAllSelected).toBe(false);
  });
});
