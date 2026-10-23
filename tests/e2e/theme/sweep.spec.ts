import { test, expect, login } from '../shared/fixtures';
import { installThemeHelpers, BLOCKING_THEMES, VIEWPORT } from './theme-helpers';
import { SURFACES, SweepRecorder, sweepTheme } from './sweep-runner';

/**
 * #1224 / #1227 — measured health sweep: 25 surfaces × the blocking theme set.
 *
 * The blocking set is the pre-#1227 seven (regression lock) plus `WATCH_THEMES`
 * (themes the full 35-theme matrix flagged ❌/🧱). The full 35 × 25 matrix lives
 * in `sweep-35.spec.ts`, which is report-only and runs from its own workflow.
 *
 * Method (locked by the issue's verification comment 6364662792):
 *  - real Chromium + real login, viewport 1440×960;
 *  - theme applied through the app's own storage keys (data-theme + JSON
 *    `mary-theme`/`mary-class`), transitions disabled, settle loop before
 *    every sample;
 *  - only the effective computed style / rendered pixels (pixel sampling is
 *    the primary method for glass + stitch-gradient backdrops);
 *  - WCAG 2.1 relative luminance, alpha composited over the real backdrop.
 *
 * Set SWEEP_REPORT_ONLY=1 to print the table without failing (baseline runs);
 * CI runs it strict. Screenshots are written when E2E_CAPTURE_DIR is set.
 */

const REPORT_ONLY = process.env.SWEEP_REPORT_ONLY === '1';
const CAPTURE_DIR = process.env.E2E_CAPTURE_DIR;

test.use({ viewport: VIEWPORT });

test.describe('theme health sweep', () => {
  test(`all ${BLOCKING_THEMES.length} blocking themes: ${SURFACES.length} surfaces clear WCAG AA`, async ({ page }) => {
    test.setTimeout(900_000);
    await installThemeHelpers(page);
    await login(page);

    const recorder = new SweepRecorder();

    for (const theme of BLOCKING_THEMES) {
      try {
        await sweepTheme(page, theme, recorder, { captureDir: CAPTURE_DIR, capturePrefix: '1224' });
      } catch (error) {
        recorder.missing(theme, 'sweep aborted', String(error));
      }
    }

    // eslint-disable-next-line no-console
    console.log(`[#1224-sweep] ${JSON.stringify(recorder.rows)}`);

    if (REPORT_ONLY) return;

    expect(recorder.failures(), JSON.stringify(recorder.failures(), null, 2)).toEqual([]);
  });
});
