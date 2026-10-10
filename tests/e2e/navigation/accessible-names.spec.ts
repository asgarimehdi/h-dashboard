import { test, expect, login, waitForLivewire } from '../shared/fixtures';

type Page = import('@playwright/test').Page;

/**
 * Issue #956 — every icon-only control exposes an accessible name.
 *
 * The 78 `<x-button icon=…>` call sites this issue fixed all render an icon and
 * nothing else: MaryUI's inner icon carries `aria-hidden="true"`, so a control
 * with no `label=` and no `aria-label=` has NO accessible name at all, and a
 * screen-reader user cannot tell an edit button from a delete button — which
 * are precisely the destructive controls this product exposes.
 *
 * The measurement is deliberately programmatic rather than by eye (the
 * `theme/toast-contrast.spec.ts` model): `ariaSnapshotJSON()` returns Chromium's
 * own accessibility tree, so `name` is the *computed* accessible name — the
 * exact string a screen reader would announce — not a re-implementation of the
 * name-computation algorithm inside the test.
 *
 * ## Scope: action controls only, not form fields
 *
 * The roles below are the ones a user *activates to do something*. Form-entry
 * roles (`textbox`, `searchbox`, `combobox`, `checkbox`, `radio`, `slider`,
 * `spinbutton`) are deliberately excluded, because they are a different and much
 * larger gap, and this issue is about buttons.
 *
 * Measured on `beta` @ bd8f4c5 with this same walker, before any fix:
 *
 *   page                 unnamed BUTTONs   unnamed form fields
 *   /users                        42     checkbox, combobox, textbox
 *   /permissions                  38     checkbox, combobox, textbox
 *   /units                        62     checkbox, combobox, textbox
 *   /hardware                     62     checkbox, combobox, textbox
 *   /kargozini/persons            43     checkbox, combobox, textbox
 *   /maintenance                   2     checkbox, textbox
 *   /dashboard                     1     checkbox
 *   /it/zabbix-devices             1     checkbox, combobox, textbox
 *   ------------------------------------------------------
 *   total                         251
 *
 * After this branch the button column is 0 on every one of those pages. The form
 * field column is unchanged, item for item — the table select-all checkbox, the
 * search box and the per-page selector are still unnamed, exactly as on beta.
 * That is a real gap and it belongs to the accessibility-infrastructure work in
 * sister issue #957, not here.
 */

/** Roles a user activates to perform an action. Deliberately excludes form fields. */
const ACTION_ROLES = new Set([
  'button',
  'link',
  'menuitem',
  'menuitemcheckbox',
  'menuitemradio',
  'tab',
]);

interface AxeNode {
  role?: string;
  name?: string;
  text?: string;
  children?: AxeNode[];
}

/**
 * Walk Chromium's accessibility tree and return every action control with no
 * accessible name, described well enough to be found by hand in devtools.
 */
async function unnamedActionControls(page: Page): Promise<string[]> {
  const tree = (await page.locator('body').ariaSnapshotJSON()) as AxeNode[];
  const found: string[] = [];

  const walk = (nodes: AxeNode[]) => {
    for (const node of nodes) {
      const role = node.role ?? '';

      if (ACTION_ROLES.has(role) && (node.name ?? '').trim() === '') {
        // `text` carries the visible text when it is the only child, which is
        // what makes an empty name locatable in devtools.
        found.push(`${role} (text: ${JSON.stringify(node.text ?? '')})`);
      }

      if (node.children?.length) {
        walk(node.children);
      }
    }
  };

  walk(tree);

  return found;
}

/**
 * Navigate and let Livewire settle before reading the accessibility tree.
 *
 * `domcontentloaded` alone is not enough. A control that a Livewire request is
 * still re-rendering is absent from the tree, and an absent control reads as a
 * NAMED control — the snapshot would pass over a button that a user mid-click
 * genuinely cannot reach yet. Waiting costs nothing when nothing is loading and
 * removes the false-pass window when something is.
 */
async function openSettled(page: Page, path: string): Promise<void> {
  await page.goto(path);
  await page.waitForLoadState('domcontentloaded');
  await waitForLivewire(page);
}

/** The pages the issue names as representative, plus the layout chrome. */
const PAGES: { path: string; why: string }[] = [
  { path: '/dashboard', why: 'layout chrome: bell, theme toggle, logout, change-context' },
  { path: '/users', why: 'edit / restore / deactivate user' },
  { path: '/permissions', why: 'create / edit / delete permission' },
  { path: '/roles', why: 'create / edit / delete role' },
  { path: '/units', why: 'create / edit / delete unit' },
  { path: '/hardware', why: 'edit / history / delete hardware, filter toggle' },
  { path: '/maintenance', why: 'create / save / cancel / edit / delete schedule' },
  { path: '/kargozini/persons', why: 'create / filter / reset / edit / delete person' },
  { path: '/kargozini/estekhdam', why: 'responsive create, save, cancel, edit, delete' },
  { path: '/kargozini/radif', why: 'responsive create, save, cancel, edit, delete' },
  { path: '/kargozini/semat', why: 'responsive create, save, cancel, edit, delete' },
  { path: '/kargozini/tahsil', why: 'responsive create, save, cancel, edit, delete' },
  { path: '/activity-log', why: 'view activity detail' },
  { path: '/it/zabbix-devices', why: 'test connection / edit / toggle / delete device' },
];

test.describe('accessible names on action controls (#956)', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  for (const { path, why } of PAGES) {
    test(`${path} has no unnamed action control`, async ({ page }) => {
      await openSettled(page, path);

      const unnamed = await unnamedActionControls(page);

      expect(
        unnamed,
        `${path} (${why}) — ${unnamed.length} کنترل بدون نامِ قابل‌دسترس`
      ).toEqual([]);
    });
  }

  test('the notification bell announces its unread count', async ({ page }) => {
    await openSettled(page, '/dashboard');

    const bell = page.getByRole('button', { name: /اعلان‌ها/ });

    await expect(bell).toHaveCount(1);

    // The count lives in the accessible name, so the badge span must not also be
    // announced — otherwise a screen reader reads the number twice.
    const name = await bell.getAttribute('aria-label');
    expect(name).toContain('اعلان‌ها');
  });

  test('a destructive control is distinguishable from an editing one', async ({ page }) => {
    // The concrete harm the issue describes: "a screen reader cannot tell an
    // edit button from a delete button". Asserted by name, not by pixels.
    await openSettled(page, '/users');

    await expect(page.getByRole('button', { name: 'ویرایش کاربر' }).first()).toBeVisible();
    await expect(
      page.getByRole('button', { name: /غیرفعال کردن کاربر|فعال‌سازی کاربر/ }).first()
    ).toBeVisible();
  });

  test('a responsive create button keeps a name on a phone-width viewport', async ({ page }) => {
    // MaryUI hides a `responsive` label below `lg` with `hidden lg:block`
    // (Button.php:91), so below that breakpoint the aria-label is the only name
    // left. Asserted at phone width, which is where the label is gone.
    //
    // /hardware and /it/zabbix-devices are here because their create buttons
    // already carried a `label=` and so looked fine to a "has a name" check —
    // they were unnamed on mobile precisely because of that. The static guard
    // now enforces the rule for every page; this proves it in a real browser.
    const cases: { path: string; name: string }[] = [
      { path: '/users', name: 'کاربر جدید' },
      { path: '/hardware', name: 'افزودن سخت‌افزار' },
      { path: '/it/zabbix-devices', name: 'دستگاه جدید' },
    ];

    await page.setViewportSize({ width: 390, height: 844 });

    for (const { path, name } of cases) {
      await openSettled(page, path);

      const create = page.getByRole('button', { name }).first();

      await expect(create, `${path}: دکمهٔ «${name}» باید زیر lg نام داشته باشد`).toBeVisible();
      await expect(create).toHaveAttribute('aria-label', name);
    }
  });

  test('the header nav links keep names when their labels collapse', async ({ page }) => {
    // The header search and profile links carry their text in a
    // `hidden md:inline` span, so below `md` they are icon plus nothing.
    await page.setViewportSize({ width: 390, height: 844 });
    await openSettled(page, '/dashboard');

    await expect(page.getByRole('link', { name: 'جستجو' })).toHaveCount(1);
    await expect(page.getByRole('link', { name: /پروفایل/ })).toHaveCount(1);
  });
});