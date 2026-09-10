import { test, expect, login } from '../shared/fixtures';

/**
 * Plan 002 — Smoke link check
 *
 * Collects every internal link from the rendered sidebar, issues a GET for each
 * with the authenticated session, and asserts none return HTTP 500 (a broken
 * Livewire page). 302 → /login and 404 are tolerated: they mean "route exists
 * but redirects/missing", not a server crash — only 5xx is a real break.
 *
 * Known-broken routes are marked `test.fixme()` with the bug ID comment until
 * the upstream issue is resolved (per plan 002 note).
 */

// Routes that currently 500 in the SPA and are tracked as known bugs.
// key = href, value = bug reference.
const KNOWN_BROKEN: Record<string, string> = {
  // '/docs': 'BUG-003 — docs route 500s',          // add when confirmed
  // '/users/create': 'BUG-001 — create page 500s', // add when confirmed
};

test.describe('no broken links (smoke loop)', () => {
  test('every sidebar link returns non-500', async ({ page }) => {
    await login(page);
    await page.goto('/dashboard');
    await page.waitForLoadState('networkidle');

    // Collect unique internal hrefs from the sidebar drawer (skip query-string dupes)
    const hrefs = await page.locator('.drawer-side a[href^="/"]').evaluateAll((anchors) => {
      const seen = new Set<string>();
      for (const a of anchors) {
        const href = (a.getAttribute('href') || '').split('#')[0];
        if (href && href.startsWith('/') && !href.startsWith('//')) {
          seen.add(href);
        }
      }
      return [...seen];
    });

    expect(hrefs.length).toBeGreaterThan(20);

    // Use the page's API request context (shares the auth session/cookies).
    const results: { href: string; status: number }[] = [];
    for (const href of hrefs) {
      const res = await page.request.get(href);
      results.push({ href, status: res.status() });
    }

    const broken = results.filter((r) => r.status >= 500);
    expect(
      broken,
      `Broken links (5xx):\n${broken.map((b) => `  ${b.status} ${b.href}`).join('\n')}`,
    ).toEqual([]);
  });
});