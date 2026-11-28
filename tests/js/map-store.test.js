import { afterEach, describe, expect, it, vi } from 'vitest';

// `registerMapStore` only needs an Alpine stub that captures the store object;
// the Leaflet instance itself is module scope and stays null without `use()`.
async function makeStore() {
  vi.resetModules();
  const { registerMapStore } = await import('../../resources/js/map-store.js');
  let store;
  registerMapStore({
    store: (name, value) => {
      if (name === 'map') store = value;
    },
  });

  return store;
}

afterEach(() => {
  vi.restoreAllMocks();
  vi.resetModules();
});

describe('map store config', () => {
  it('configure merges shallowly, keeping earlier keys', async () => {
    const store = await makeStore();

    store.configure({ zoom: 5 });
    store.configure({ view: [1, 2] });

    expect(store.config).toEqual({ zoom: 5, view: [1, 2] });
  });

  it('configure replaces a repeated key', async () => {
    const store = await makeStore();

    store.configure({ zoom: 5 });
    store.configure({ zoom: 9 });

    expect(store.config).toEqual({ zoom: 9 });
  });
});

describe('map store lifecycle', () => {
  it('get() is null without an instance', async () => {
    expect((await makeStore()).get()).toBeNull();
  });

  it('onReady queues callbacks and flush drains them with the instance', async () => {
    const store = await makeStore();
    const first = vi.fn();
    const second = vi.fn();

    store.onReady(first);
    store.onReady(second);
    expect(store.pending).toHaveLength(2);

    store.flush();

    expect(first).toHaveBeenCalledWith(null);
    expect(second).toHaveBeenCalledWith(null);
    expect(store.pending).toHaveLength(0);
  });

  it('flush keeps going after a consumer throws', async () => {
    const store = await makeStore();
    const boom = vi.fn(() => {
      throw new Error('boom');
    });
    const after = vi.fn();
    const error = vi.spyOn(console, 'error').mockImplementation(() => {});

    store.onReady(boom);
    store.onReady(after);
    store.flush();

    expect(after).toHaveBeenCalledWith(null);
    expect(error).toHaveBeenCalled();
  });

  it('use(null) warns and returns null without touching the document', async () => {
    const store = await makeStore();
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

    expect(store.use(null)).toBeNull();
    expect(warn).toHaveBeenCalled();
  });

  it('release is a no-op without an instance', async () => {
    const store = await makeStore();

    expect(() => store.release(null)).not.toThrow();
  });
});
