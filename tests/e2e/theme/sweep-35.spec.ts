import { test, expect, login } from '../shared/fixtures';
import * as fs from 'fs';
import * as path from 'path';
import { installThemeHelpers, THEMES, VIEWPORT } from './theme-helpers';
import { SURFACES, SweepRecorder, sweepTheme, toMarkdown, toSummary } from './sweep-runner';

/**
 * #1227 — the full matrix: 35 enabled daisyUI themes × 25 measured surfaces.
 *
 * Why this is a separate, non-blocking spec: the blocking sweep already spends
 * ~26.6 s per theme (measured on run 39977606322); 35 themes straight into that
 * job is ~15.5 min of sweep on top of a ~23 min job and breaks both the 30-min
 * job cap and the 900 s test timeout. This spec runs in its own workflow
 * (`theme-sweep-35.yml`, dispatch + weekly + PR label) with a 45-min cap and
 * produces the published table.
 *
 * It is report-only by default (`SWEEP_REPORT_ONLY` defaults to `1` here): the
 * matrix's job is to publish evidence, and the blocking sweep is what gates a
 * PR. `SWEEP_REPORT_ONLY=0` makes it fail on any ❌ when a follow-up wants a
 * hard lock on a theme.
 *
 * Badge rows: #1226 owns the per-theme badge pairs and is not merged, so solid
 * badges stay 🧱 via the shared STRUCTURAL map — no pair is invented here.
 */

const REPORT_ONLY = process.env.SWEEP_REPORT_ONLY !== '0';
const REPORT_DIR = process.env.SWEEP_REPORT_DIR ?? path.join('test-results', 'theme-sweep-35');
const CAPTURE_DIR = process.env.E2E_CAPTURE_DIR;

test.use({ viewport: VIEWPORT });

test.describe('35-theme sweep', () => {
  test(`all ${THEMES.length} themes: ${SURFACES.length} surfaces (report)`, async ({ page }, testInfo) => {
    // 35 × ~26.6 s ≈ 15.5 min of sampling + navigation; the workflow cap is 45.
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
    // theme keeps its 25 cells as n/a instead of shrinking the matrix.
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
