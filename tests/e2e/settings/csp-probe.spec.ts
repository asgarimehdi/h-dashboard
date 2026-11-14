import { test, expect, login } from '../shared/fixtures';

/**
 * TEMPORARY probe v3 for the #1496 false-green — do NOT merge, delete after use.
 *
 * Probe v2 (head 56688e91) narrowed it down: exactly ONE violation per test
 * attempt, firing during login() (on /login or /dashboard — NOT on /settings),
 * directive style-src-elem, blocked inline, sample empty, sourceFile
 * livewire.min.js. The blocked hash changes every CI run (YlgfM6Tz… →
 * lGBgJqS8… → Cy08wxh4…), so hash-pinning cannot fix it. Ruled out by hashing:
 * nprogress style (wHM+htXdtkideW9K), view-transition styles, auth-layout
 * <style> (WyPFsodU…), Livewire wire:loading style (BBzkWIzm, allowlisted),
 * app-brand (C9H3lAsL, allowlisted). v3 intercepts every <style> element
 * appended to the document (covers Livewire's injectStyles AND its
 * wire:navigate head-merge, which moves server-rendered <style> elements into
 * the live head) and records each one's nonce + full text, persisted in
 * sessionStorage across navigations.
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
    if (!w.__probePatched) {
      w.__probePatched = true;
      w.__probeSetAttr = [];
      w.__probeAppendedStyles = [];
      w.__probeCsp = [];
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
      const recordStyleNode = (n: any, how: string) => {
        if (!n || n.nodeType !== 1) return;
        const styles = n.tagName === 'STYLE' ? [n] : [...(n.querySelectorAll?.('style') ?? [])];
        for (const s of styles) {
          const rec = {
            at: how,
            nonce: s.getAttribute('nonce'),
            text: s.textContent,
          };
          w.__probeAppendedStyles.push(rec);
          store(rec);
        }
      };
      const origAppend = Node.prototype.appendChild;
      Node.prototype.appendChild = function (n: any) {
        recordStyleNode(n, 'appendChild');
        return origAppend.call(this, n);
      };
      const origInsert = Node.prototype.insertBefore;
      Node.prototype.insertBefore = function (n: any, ref: any) {
        recordStyleNode(n, 'insertBefore');
        return origInsert.call(this, n, ref);
      };
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
    }
  });

  const errors: string[] = [];
  page.on('console', (msg) => {
    if (msg.type() === 'error') errors.push(msg.text());
  });

  await login(page);

  const dump = async (label: string) => {
    const d = await page.evaluate(() => ({
      headStyles: [...document.querySelectorAll('style')].map((s: any) => ({
        nonce: s.getAttribute('nonce'),
        text: s.textContent,
      })),
      appendedStyles: (window as any).__probeAppendedStyles ?? [],
      setAttr: (window as any).__probeSetAttr ?? [],
      stored: JSON.parse(sessionStorage.getItem('__probeRecords') ?? '[]'),
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
