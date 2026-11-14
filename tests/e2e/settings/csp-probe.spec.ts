import { test, expect, login } from '../shared/fixtures';

/**
 * TEMPORARY probe for the #1496 false-green — do NOT merge, delete after use.
 *
 * The csp-enforcing job reports success while browser-notifications.spec.ts
 * logs one "Applying inline style violates ..." console error per attempt.
 * Static analysis ruled out every `style=` / `<style>` / `:style` string in
 * the app views and the MaryUI + Livewire vendor trees, so the style is set
 * at runtime. This spec records every style-attribute mutation (via a
 * MutationObserver installed before login) and dumps every `[style]` element
 * and `<style>` tag on /settings at load and after the toggle, so the
 * violating string can be hashed and found.
 */

test('probe: runtime styles on /settings', async ({ page }) => {
  await page.addInitScript(() => {
    const w = window as any;
    w.__probeMutations = [];
    w.__probeCsp = [];
    document.addEventListener('securitypolicyviolation', (e: any) => {
      w.__probeCsp.push({
        directive: e.effectiveDirective,
        blocked: e.blockedURI,
        sample: e.sample,
        sourceFile: e.sourceFile,
      });
    });
    const observer = new MutationObserver((records) => {
      for (const r of records) {
        if (r.type === 'attributes' && r.attributeName === 'style') {
          const el = r.target as HTMLElement;
          w.__probeMutations.push({
            at: 'attr',
            html: el.outerHTML.slice(0, 400),
          });
        }
        if (r.type === 'childList') {
          r.addedNodes.forEach((n: any) => {
            if (n.tagName === 'STYLE') {
              w.__probeMutations.push({ at: 'add', html: n.textContent });
            }
          });
        }
      }
    });
    observer.observe(document.documentElement, {
      attributes: true,
      attributeFilter: ['style'],
      childList: true,
      subtree: true,
    });
  });

  const errors: string[] = [];
  page.on('console', (msg) => {
    if (msg.type() === 'error') errors.push(msg.text());
  });

  await login(page);
  await page.goto('/settings');
  await page.waitForLoadState('networkidle');

  const dump = async (label: string) => {
    const d = await page.evaluate(() => ({
      styled: [...document.querySelectorAll('[style]')].map(
        (e: any) => `${e.tagName} :: ${e.getAttribute('style')}`,
      ),
      styleTags: [...document.querySelectorAll('style')].map((e: any) => e.textContent),
      mutations: (window as any).__probeMutations ?? [],
      cspEvents: (window as any).__probeCsp ?? [],
    }));
    console.log(`[probe:${label}] ${JSON.stringify(d)}`);
  };

  await dump('after-load');

  const toggle = page.locator('label:has-text("اعلان مرورگر") input[type="checkbox"]').first();
  await toggle.click();
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(1000);
  await dump('after-toggle');

  console.log(`[probe:console-errors] ${JSON.stringify(errors)}`);
  expect(true).toBe(true);
});
