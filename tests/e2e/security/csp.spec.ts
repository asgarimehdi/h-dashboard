import { test, expect, login } from '../shared/fixtures';

/**
 * CSP audit — issue #1281.
 *
 * Collects real browser `securitypolicyviolation` events via an init script,
 * then walks the key pages and asserts that no violation was *enforced*
 * (blocked). In report-only mode violations with disposition "report" are
 * printed so a baseline is recorded in the run log before phase-1 enforcing.
 */

interface CspViolation {
  directive: string;
  blocked: string;
  disposition: string;
  sourceFile: string;
  page: string;
}

const PAGES = [
  '/dashboard',
  '/map',
  '/tickets/inbox',
  '/todo',
  '/reports/tickets',
  '/maps/route2',
  '/units',
  '/settings',
  // #1536: four Highcharts + Alpine on one page — the highest-risk CSP surface
  // in the app, and it must stay violation-free while it sets chart heights.
  '/hr-dashboard',
];

async function collectCspViolations(page: import('@playwright/test').Page) {
  await page.addInitScript(() => {
    const w = window as any;
    if (w.__csp) return;
    w.__csp = [];
    document.addEventListener('securitypolicyviolation', (e: SecurityPolicyViolationEvent) => {
      w.__csp.push({
        directive: e.effectiveDirective,
        blocked: e.blockedURI,
        disposition: e.disposition,
        sourceFile: e.sourceFile,
      });
    });
  });
}

test.describe('CSP audit (#1281)', () => {
  let enforcing: boolean;

  test.beforeEach(async ({ page }) => {
    // Collect from the very first document: the post-login redirect is a
    // Livewire SPA navigation, and its head-merge is where a nonce'd `<style>`
    // block loses the nonce (#1532 — DOMParser strips it, so the block trips
    // `style-src-elem` with a per-run hash). Listening only inside the test
    // body misses that document entirely.
    await collectCspViolations(page);
    await login(page);
    const media = await page.request.get('/login');
    const csp = media.headers();
    enforcing = csp['content-security-policy'] !== undefined;
  });

  // Nine pages × (networkidle + 1.5s) leaves almost no headroom under
  // Playwright's 30s default — adding /hr-dashboard (#1536) pushed the walk
  // over it, and the run failed on budget rather than on a violation. Raise the
  // budget; the assertion itself is unchanged.
  test.describe.configure({ timeout: 120_000 });

  test('no violations on key pages', async ({ page }) => {
    const violations: CspViolation[] = [];

    // Whatever the login flow itself produced, before the first `goto` resets
    // the per-document collector.
    const duringLogin: Omit<CspViolation, 'page'>[] = await page.evaluate(() => (window as any).__csp ?? []);
    for (const v of duringLogin) {
      violations.push({ ...v, page: 'login' });
    }

    for (const path of PAGES) {
      await page.goto(path, { waitUntil: 'networkidle' });
      await page.waitForTimeout(1500); // Highcharts / Alpine async init
      const collected = await page.evaluate(() => (window as any).__csp ?? []);
      for (const v of collected as Omit<CspViolation, 'page'>[]) {
        if (!violations.some((x) => x.page === path && x.directive === v.directive && x.blocked === v.blocked)) {
          violations.push({ ...v, page: path });
        }
      }
    }

    if (enforcing) {
      expect(violations, 'enforced violations on key pages').toEqual([]);
    } else {
      // Report-only mode: the violating set is the pre-enforcement baseline.
      // Print it so the run log records it; do not fail the build yet.
      test.info().annotations.push(
        violations.length > 0
          ? { type: 'csp-baseline', description: JSON.stringify(violations, null, 2) }
          : { type: 'csp-baseline', description: 'no violations reported — clean baseline' },
      );
      console.log('[csp-baseline] violations:', JSON.stringify(violations, null, 2));
      expect(violations.every((v) => v.disposition === 'report')).toBe(true);
    }
  });
});
