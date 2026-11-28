import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

// The latch and the listener set are module-scoped (one per page), so every
// test re-imports the module after resetting the registry.
let apiFetch;
let isAuthFailed;
let onAuthFailed;
let ApiAuthError;

async function load() {
  vi.resetModules();
  ({ apiFetch, isAuthFailed, onAuthFailed, ApiAuthError } = await import('../../resources/js/api-fetch.js'));
}

function stubFetch(status) {
  const fetch = vi.fn(async () => new Response(null, { status }));
  vi.stubGlobal('fetch', fetch);

  return fetch;
}

beforeEach(async () => {
  await load();
});

afterEach(() => {
  vi.unstubAllGlobals();
  vi.resetModules();
});

describe('apiFetch', () => {
  it('throws ApiAuthError and latches on 401', async () => {
    stubFetch(401);

    await expect(apiFetch('/api/x')).rejects.toBeInstanceOf(ApiAuthError);
    await expect(apiFetch('/api/x')).rejects.toMatchObject({ status: 401 });
    expect(isAuthFailed()).toBe(true);
  });

  it('throws ApiAuthError and latches on 403', async () => {
    stubFetch(403);

    await expect(apiFetch('/api/x')).rejects.toMatchObject({ status: 403 });
    expect(isAuthFailed()).toBe(true);
  });

  it('returns the response untouched on 200 and does not latch', async () => {
    stubFetch(200);

    const response = await apiFetch('/api/x');

    expect(response).toBeInstanceOf(Response);
    expect(response.status).toBe(200);
    expect(isAuthFailed()).toBe(false);
  });

  it('returns the response untouched on 500 and does not latch', async () => {
    stubFetch(500);

    const response = await apiFetch('/api/x');

    expect(response.status).toBe(500);
    expect(isAuthFailed()).toBe(false);
  });

  it('sends the session cookie and JSON Accept, preserving caller headers', async () => {
    const fetch = stubFetch(200);

    await apiFetch('/api/x', { headers: { 'X-Custom': 'yes' } });

    const [, init] = fetch.mock.calls[0];
    expect(init.credentials).toBe('include');
    expect(init.headers).toEqual({ Accept: 'application/json', 'X-Custom': 'yes' });
  });
});

describe('onAuthFailed', () => {
  it('notifies listeners with the latched status', async () => {
    stubFetch(401);
    const listener = vi.fn();
    onAuthFailed(listener);

    await expect(apiFetch('/api/x')).rejects.toBeInstanceOf(ApiAuthError);

    expect(listener).toHaveBeenCalledTimes(1);
    expect(listener).toHaveBeenCalledWith(401);
  });

  it('stops notifying after the returned unsubscribe runs', async () => {
    stubFetch(403);
    const listener = vi.fn();
    const off = onAuthFailed(listener);
    off();

    await expect(apiFetch('/api/x')).rejects.toBeInstanceOf(ApiAuthError);

    expect(listener).not.toHaveBeenCalled();
  });
});
