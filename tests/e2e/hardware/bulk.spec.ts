import { test, expect, login, waitForLivewire, TEST_USER } from '../shared/fixtures';
/**
 * Plan 010 / #1175 — Hardware bulk actions
 *
 * The row checkbox is bound DEFERRED (`wire:model`, not `wire:model.live`):
 * the whole selection travels with the NEXT request instead of one Livewire
 * round trip per tick. The bulk toolbar therefore counts the selection on the
 * client — the count and the disabled state are rendered by Alpine, not by the
 * server, because the server does not know about the ticked rows yet.
 *
 * This spec pins the BEHAVIOUR, never the binding attribute:
 *   1. nothing ticked → bulk buttons disabled, no count chip
 *   2. a row ticked  → bulk buttons enabled + the chip «1 انتخاب» appears,
 *                      and the tick itself costs no Livewire round trip
 *   3. bulk delete   → the row really leaves the database (it shows up in the
 *                      trash list, which is built from the audit trail and the
 *                      live table)
 */

const BULK_DELETE = { name: 'حذف', exact: true };
const BULK_MARK = { name: 'علامت', exact: true };
const BULK_UNMARK = { name: 'برداشتن', exact: true };

/** The n_code of the seeded admin, whose person row is in the seeded unit. */
const SEEDED_N_CODE = TEST_USER.nCode;

test.describe('hardware bulk actions', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await page.goto('/hardware');
    await page.waitForLoadState('networkidle');
  });

  test('bulk buttons are disabled until a row is selected', async ({ page }) => {
    await expect(page.getByRole('button', BULK_DELETE)).toBeDisabled();
    await expect(page.getByRole('button', BULK_MARK)).toBeDisabled();
    await expect(page.getByRole('button', BULK_UNMARK)).toBeDisabled();
  });

  test('ticking a row enables the bulk actions and shows the count', async ({ page }) => {
    // A live binding would spend one Livewire round trip per tick and ship
    // the selection to the server right away. The deferred binding keeps the
    // selection on the client until the next request — so the tick must not
    // put `selected` into any Livewire payload.
    //
    // The check is on the PAYLOAD, not on the request count: other components
    // on the page (the notification bell's click-away handler) talk to the
    // server on their own.
    const shippedSelections: string[] = [];
    page.on('request', (request) => {
      if (request.method() !== 'POST') return;
      // Livewire v4 serves its update endpoint from /livewire-<hash>/update.
      if (!/\/livewire[^/]*\/update(\?|$)/.test(request.url())) return;
      const body = request.postData();
      if (!body) return;
      try {
        const payload = JSON.parse(body);
        for (const component of payload.components ?? []) {
          if (component?.updates && 'selected' in component.updates) {
            shippedSelections.push(request.url());
          }
        }
      } catch {
        // Not a Livewire payload — nothing to inspect.
      }
    });

    await page.locator('table input[type="checkbox"]').first().check();

    await expect(page.getByRole('button', BULK_DELETE)).toBeEnabled();
    await expect(page.getByRole('button', BULK_MARK)).toBeEnabled();
    await expect(page.getByRole('button', BULK_UNMARK)).toBeEnabled();
    // The count chip names the size of the selection.
    await expect(page.getByRole('button', { name: '1 انتخاب' })).toBeVisible();

    await page.waitForTimeout(1200);
    expect(shippedSelections).toHaveLength(0);
  });

  test('bulk mark persists and unmark reverts', async ({ page }) => {
    // Isolate one stable row so bulk actions touch exactly one record.
    const search = waitForLivewire(page);
    await page.locator('input[placeholder*="جستجو در تمام"]').first().fill('AB-17SH-EZDEVAJ');
    // Wait for Livewire search to complete
    await search;
    await expect(page.locator('table tbody tr')).toHaveCount(1);

    await page.locator('table input[type="checkbox"]').first().check();
    // The deferred selection reaches the action with the click itself — no
    // round trip was needed to light the toolbar up.
    await expect(page.getByRole('button', BULK_MARK)).toBeEnabled();

    const markBtn = page.getByRole('button', BULK_MARK);
    await markBtn.click();
    await expect(page.locator('.toast').first()).toContainText('علامت‌گذاری', { timeout: 10000 });
    await expect(page.locator('table tbody')).toContainText('علامت');

    // Revert in the same test (shared seeded data must not stay mutated).
    const unmarkBtn = page.getByRole('button', BULK_UNMARK);
    await expect(unmarkBtn).toBeEnabled();
    await unmarkBtn.click();
    await expect(page.locator('.toast').first()).toContainText('علامت‌گذاری', { timeout: 10000 });
    await expect(page.locator('table tbody')).not.toContainText('علامت');
  });

  test('bulk delete removes the selected row from the database', async ({ page }) => {
    // A dedicated row, so the shared seeded data other specs rely on stays
    // untouched (the suite runs fully parallel against one database).
    const pcName = `E2E-BULK-DEL-${Date.now()}`;

    await page.getByRole('button', { name: 'افزودن سخت‌افزار' }).click();
    // MaryUI pads the placeholder with a trailing space, so anchor on the
    // prefix — "جستجو..." must not catch the toolbar's "جستجو در تمام فیلدها...".
    const nCodeInput = page.locator('input[placeholder^="جستجو..."]');
    await expect(nCodeInput).toBeVisible();
    await nCodeInput.fill(SEEDED_N_CODE);
    // The lookup is debounced (500 ms) — the person resolves once the field
    // goes quiet.
    await expect(page.locator('div.text-xs.text-success')).toBeVisible({ timeout: 10000 });

    await page.locator('input[placeholder^="PC-NAME"]').fill(pcName);
    await page.getByRole('button', { name: 'ذخیره', exact: true }).click();
    await expect(page.locator('.toast', { hasText: 'ایجاد شد' }).first()).toBeVisible({ timeout: 10000 });

    const search = waitForLivewire(page);
    await page.locator('input[placeholder*="جستجو در تمام"]').first().fill(pcName);
    await search;
    await expect(page.locator('table tbody tr')).toHaveCount(1);

    await page.locator('table input[type="checkbox"]').first().check();
    await expect(page.getByRole('button', BULK_DELETE)).toBeEnabled();
    await expect(page.getByRole('button', { name: '1 انتخاب' })).toBeVisible();

    // Livewire's wire:confirm uses the native confirm() dialog.
    page.on('dialog', (dialog) => dialog.accept());
    const bulkDelete = waitForLivewire(page);
    await page.getByRole('button', BULK_DELETE).click();
    await bulkDelete;
    await expect(page.locator('.toast', { hasText: 'حذف شدند' }).first()).toBeVisible({ timeout: 10000 });

    // The row is gone from the list …
    await expect(page.locator('table tbody tr')).toHaveCount(0);

    // … and it is in the trash, which is built from the audit trail and the
    // live table — i.e. the delete really reached the database.
    await page.getByRole('button', { name: 'حذف شده‌ها' }).click();
    await expect(page.locator('body')).toContainText(pcName, { timeout: 10000 });
  });
});
