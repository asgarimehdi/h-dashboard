import { test, expect, login } from '../shared/fixtures';
import * as fs from 'fs';
import * as path from 'path';
import { installThemeHelpers, THEMES, VIEWPORT } from './theme-helpers';
import { SURFACES, SweepRecorder, sweepTheme, toMarkdown, toSummary } from './sweep-runner';

/**
 * #1227 — the full matrix: 35 enabled daisyUI themes × every SURFACES cell
 * (33 after the #1226 per-variant badge probes joined the shared runner).
 *
 * Why this is a separate, non-blocking spec: on CI the blocking sweep costs
 * ≈39 s per theme (9-theme run measured 5.8 min, 2026-10-24), so 35 themes
 * straight into that job is ~22.7 min of sweep on top of the suite and breaks
 * the 30-min E2E job cap. This spec runs in its own workflow
 * (`theme-sweep-35.yml`, dispatch + weekly + PR label) with a 45-min cap and
 * produces the published table.
 *
 * It is report-only by default (`SWEEP_REPORT_ONLY` defaults to `1` here) so
 * ad-hoc/local runs still produce the table; `SWEEP_REPORT_ONLY=0` turns the
 * run into a hard gate on any ❌. The 35-theme workflow passes '0' since
 * #1231, whose acceptance is an empty `failures()` on the branch.
 *
 * Badge rows: #1226 (merged in a4276fc8) owns the per-theme opaque/glass
 * matrix for its five themes; #1231 extends the same opaque-token restore to
 * every other failing badge cell (the winter/abyss cells included), so the
 * STRUCTURAL map is now empty and nothing is exempt from AA here.
 */

const REPORT_ONLY = process.env.SWEEP_REPORT_ONLY !== '0';
const REPORT_DIR = process.env.SWEEP_REPORT_DIR ?? path.join('test-results', 'theme-sweep-35');
const CAPTURE_DIR = process.env.E2E_CAPTURE_DIR;

test.use({ viewport: VIEWPORT });

test.describe('35-theme sweep', () => {
  test(`all ${THEMES.length} themes: ${SURFACES.length} surfaces (report)`, async ({ page }, testInfo) => {
    // 35 × ~39 s (CI-measured) ≈ 22.7 min of sampling + navigation; cap is 45.
    test.setTimeout(1_800_000);
    await installThemeHelpers(page);
    await login(page);

    const recorder = new SweepRecorder();

    for (const theme of THEMES) {
      try {
        await sweepTheme(page, theme, recorder, { captureDir: CAPTURE_DIR, capturePrefix: '1227' });
      } catch (error) {
        recorder.missing(theme, 'sweep aborted', String(error));
      }
    }

    // Guarantee the published table is the full cross-product: an aborted
    // theme keeps its full cell set as n/a instead of shrinking the matrix.
    recorder.fillMatrix(THEMES);

    const summary = toSummary(recorder.rows, THEMES);
    const table = toMarkdown(recorder.rows);
    const report = [
      `# #1227 — ${THEMES.length} × ${SURFACES.length} theme sweep`,
      '',
      `Report-only: \`${REPORT_ONLY ? 'yes' : 'no'}\``,
      '',
      '## Per-theme roll-up',
      '',
      summary,
      '',
      '## Every cell',
      '',
      table,
      '',
    ].join('\n');

    fs.mkdirSync(REPORT_DIR, { recursive: true });
    fs.writeFileSync(path.join(REPORT_DIR, 'sweep-35.json'), JSON.stringify(recorder.rows, null, 2));
    fs.writeFileSync(path.join(REPORT_DIR, 'sweep-35.md'), report);

    await testInfo.attach('sweep-35.md', { body: report, contentType: 'text/markdown' });
    await testInfo.attach('sweep-35.json', {
      body: JSON.stringify(recorder.rows, null, 2),
      contentType: 'application/json',
    });

    // eslint-disable-next-line no-console
    console.log(`[#1227-sweep] rows=${recorder.rows.length} flagged=${recorder.flaggedThemes().join(',') || '-'}`);
    // eslint-disable-next-line no-console
    console.log(summary);
    // eslint-disable-next-line no-console
    console.log(`[#1227-sweep-rows] ${JSON.stringify(recorder.rows)}`);

    if (REPORT_ONLY) return;

    expect(recorder.failures(), JSON.stringify(recorder.failures(), null, 2)).toEqual([]);
  });
});
