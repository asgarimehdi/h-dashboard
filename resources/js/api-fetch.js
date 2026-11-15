// @ts-check
/**
 * Cookie-authenticated `/api/*` fetch and the shared 401/403 latch (#1532).
 *
 * WHY THIS EXISTS
 * ---------------
 * The web UI calls `/api/*` with the session cookie and no bearer token
 * (#1399). Sanctum only treats such a request as stateful when its
 * Referer/Origin host is in `config('sanctum.stateful')`; a deployment
 * served from a host/port missing from that list answers `401
 * {"message":"Unauthenticated."}` to every call.
 *
 * On the map that used to be silent: `fetchAndRender()` parsed the JSON
 * envelope, found no `features`, cleared the layer and moved on — so panning
 * kept firing six doomed requests per move and the map just looked empty.
 * The Zabbix gauges had the mirrored bug (cookie request, 401, empty chart).
 *
 * The 401 is not transient: retrying a cookie-authenticated call cannot fix
 * a host that Sanctum does not trust, and a 403 cannot be fixed by repeating
 * either. So the latch is terminal for the page: every later `apiFetch()`
 * call short-circuits, the caller skips its per-layer/per-chart message, and
 * the ONE `x-api-auth-banner` tells the user to sign in again (or the operator
 * to fix the config).
 */

/** Module-scoped, deliberately: one latch per page, shared by every module. @type {boolean} */
let authFailed = false;

/** @type {Set<(status: number) => void>} */
const listeners = new Set();

/**
 * The error `apiFetch()` throws for a latched status. Callers rethrow nothing
 * user-facing: the banner owns the message.
 */
export class ApiAuthError extends Error {
  /** @param {number} status */
  constructor(status) {
    super(`API authentication failed (${status})`);
    this.name = 'ApiAuthError';
    this.status = status;
  }
}

/** Whether the page has already seen a 401/403 from a cookie-authenticated call. */
export function isAuthFailed() {
  return authFailed;
}

/**
 * Register a listener for the latch. Returns an unsubscribe function, so an
 * Alpine component can clean up in `destroy()`.
 *
 * @param {(status: number) => void} listener
 * @returns {() => void}
 */
export function onAuthFailed(listener) {
  listeners.add(listener);

  return () => listeners.delete(listener);
}

/** @param {number} status */
function latchAuthFailure(status) {
  authFailed = true;
  listeners.forEach((listener) => listener(status));
}

/**
 * `fetch()` for a cookie-authenticated reachable-by-session endpoint.
 *
 * Adds `Accept: application/json` (so a guest response is the JSON 401
 * envelope, never an HTML login redirect) and `credentials: 'include'`, then
 * latches and throws on 401/403. Every other status is returned untouched:
 * callers keep their own 503/error mapping.
 *
 * @param {string} url
 * @param {RequestInit} [options]
 * @returns {Promise<Response>}
 */
export async function apiFetch(url, options = {}) {
  /** @type {RequestInit} */
  const init = {
    ...options,
    credentials: 'include',
    headers: { Accept: 'application/json', ...(options.headers || {}) },
  };

  const response = await fetch(url, init);

  if (response.status === 401 || response.status === 403) {
    latchAuthFailure(response.status);

    throw new ApiAuthError(response.status);
  }

  return response;
}

/**
 * The single banner every page shows once the latch trips. The markup lives in
 * `resources/views/components/api-auth-banner.blade.php`; this registration
 * only carries the state.
 *
 * @param {AlpineStatic} Alpine
 */
export function registerAuthBanner(Alpine) {
  Alpine.data('authBanner', () => ({
    authFailed: isAuthFailed(),
    /** @type {(() => void) | null} */
    off: null,

    init() {
      this.off = onAuthFailed(() => {
        this.authFailed = true;
      });
    },

    destroy() {
      if (this.off) this.off();
    },
  }));
}
