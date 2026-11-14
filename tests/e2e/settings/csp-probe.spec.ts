import { test, expect, login } from '../shared/fixtures';

/**
 * TEMPORARY probe v2 for the #1496 false-green — do NOT merge, delete after use.
 *
 * Probe v1 (head 909e077c) found: the blocked hash changes between CI runs
 * (YlgfM6Tz… → lGBgJqS8…) while staying constant within a run, so hash-pinning
 * cannot fix it; the 3 decorative blob DIVs are CSSOM-styled (innocent); no
 * style mutation was observed on /settings and no captured string hashed to
 * the blocked value. v1 had two holes: it only recorded STYLE-tag insertions
 * (not transient DIVs styled while detached) and its records reset on every
 * navigation (a /login violation would be lost). v2 patches setAttribute to
 * capture the EXACT raw style string, records every added node, and persists
 * records in sessionStorage across navigations.
 */

test('probe: runtime styles on /settings', async ({ page }) => {
  await page.addInitScript(() => {
    const w = window as any;
    const store = (rec: any) => {
      try {
        const prev = JSON.parse(sessionStorage.getItem('__probeRecords') ?? '[]');
        prev.push(rec);
        sessionStorage.setItem('__probeRecords', JSON.stringify(prev.slice(-300)));
      } catch {
        // Storage unavailable — the live arrays below still carry the data.
      }
    };
    if (!w.__probeSetAttr) {
      w.__probeSetAttr = [];
      const origSetAttr = Element.prototype.setAttribute;
      Element.prototype.setAttribute = function (name: string, value: string) {
        if (name === 'style') {
          const rec = {
            at: 'setAttribute',
            tag: (this as Element).tagName,
            cls: (this as Element).getAttribute('class'),
            value,
          };
          w.__probeSetAttr.push(rec);
          store(rec);
        }
        return origSetAttr.call(this, name, value);
      };
    }
    if (!w.__probeAdded) {
      w.__probeAdded = [];
      w.__probeCsp = [];
      document.addEventListener('securitypolicyviolation', (e: any) => {
        const rec = {
          directive: e.effectiveDirective,
          blocked: e.blockedURI,
          sample: e.sample,
          sourceFile: e.sourceFile,
        };
        w.__probeCsp.push(rec);
        store({ at: 'csp-event', ...rec });
      });
      const observer = new MutationObserver((records) => {
        for (const r of records) {
          if (r.type === 'attributes' && r.attributeName === 'style') {
            const el = r.target as HTMLElement;
            const rec = { at: 'attr-mutation', tag: el.tagName, value: el.getAttribute('style') };
            w.__probeAdded.push(rec);
            store(rec);
          }
          if (r.type === 'childList') {
            r.addedNodes.forEach((n: any) => {
              if (n.nodeType === 1) {
                const rec = { at: 'added', tag: n.tagName, html: n.outerHTML.slice(0, 300) };
                w.__probeAdded.push(rec);
                store(rec);
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
    }
  });

  const errors: string[] = [];
  page.on('console', (msg) => {
    if (msg.type() === 'error') errors.push(msg.text());
  });

  await login(page);

  const dump = async (label: string) => {
    const d = await page.evaluate(() => ({
      styled: [...document.querySelectorAll('[style]')].map(
        (e: any) => `${e.tagName} :: ${e.getAttribute('style')}`,
      ),
      setAttr: (window as any).__probeSetAttr ?? [],
      added: ((window as any).__probeAdded ?? []).slice(-40),
      stored: JSON.parse(sessionStorage.getItem('__probeRecords') ?? '[]').slice(-60),
      cspEvents: (window as any).__probeCsp ?? [],
    }));
    console.log(`[probe:${label}] ${JSON.stringify(d)}`);
  };

  await dump('after-login');
  await page.goto('/settings');
  await page.waitForLoadState('networkidle');
  await dump('after-load');

  const toggle = page.locator('label:has-text("اعلان مرورگر") input[type="checkbox"]').first();
  await toggle.click();
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(1000);
  await dump('after-toggle');

  console.log(`[probe:console-errors] ${JSON.stringify(errors)}`);
  expect(true).toBe(true);
});
