import { describe, expect, it } from 'vitest';

import { registerMaryComponents } from '../../resources/js/mary-components.js';

// The component factory is registered through `Alpine.data()`; a minimal stub
// captures it without mounting Alpine or touching the DOM.
function makeTable() {
  let factory;
  registerMaryComponents({
    data: (name, cb) => {
      if (name === 'maryTable') factory = cb;
    },
  });

  return factory();
}

describe('maryTable selection', () => {
  it('pushIds adds only the page ids and keeps selections from other pages', () => {
    const table = makeTable();
    table.pageIds = [1, 2];
    table.selection = [9, 1];

    table.pushIds();

    expect(table.selection).toEqual([9, 1, 2]);
  });

  it('removeIds removes only the page ids and keeps selections from other pages', () => {
    const table = makeTable();
    table.pageIds = [1, 2];
    table.selection = [9, 1, 2];

    table.removeIds();

    expect(table.selection).toEqual([9]);
  });

  it('toggleExpand adds a missing key and removes an existing one', () => {
    const table = makeTable();
    table.selection = [7];

    table.toggleExpand(7);
    expect(table.selection).toEqual([]);

    table.toggleExpand(8);
    expect(table.selection).toEqual([8]);
  });

  it('isPageFullSelected is true when every page id is selected, ignoring extra pages', () => {
    const table = makeTable();
    table.pageIds = [1, 2];
    table.selection = [2, 1, 9];

    expect(table.isPageFullSelected()).toBe(true);
  });

  it('isPageFullSelected is false for an empty page', () => {
    const table = makeTable();
    table.pageIds = [];
    table.selection = [];

    expect(table.isPageFullSelected()).toBe(false);
  });

  it('isPageFullSelected is false when a page id is missing', () => {
    const table = makeTable();
    table.pageIds = [1, 2];
    table.selection = [1];

    expect(table.isPageFullSelected()).toBe(false);
  });

  it('isPageFullSelected does not treat a page id as a substring of a larger selection id', () => {
    const table = makeTable();
    table.pageIds = [23];
    table.selection = [123];

    expect(table.isPageFullSelected()).toBe(false);
  });

  it('isPageFullSelected does not match across id boundaries', () => {
    const table = makeTable();
    table.pageIds = [1, 2];
    table.selection = [1, 12];

    expect(table.isPageFullSelected()).toBe(false);
  });

  it('isPageFullSelected is true when every page id is present alongside other selections', () => {
    const table = makeTable();
    table.pageIds = [23];
    table.selection = [123, 23];

    expect(table.isPageFullSelected()).toBe(true);
  });
});
