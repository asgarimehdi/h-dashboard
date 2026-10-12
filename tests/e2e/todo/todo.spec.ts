import { test, expect, login, waitForLivewire, waitForToast } from '../shared/fixtures';

/**
 * E2E tests for the Todo (تسک) calendar page.
 * Covers: page load, create, toggle, delete via Livewire modal.
 */

test.describe('todo calendar', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await page.goto('/todo');
    await page.waitForLoadState('networkidle');
  });

  test('todo page loads with calendar', async ({ page }) => {
    // Page header should show the title
    await expect(page.locator('text=تقویم سازمانی')).toBeVisible();
    // Calendar container should be present
    await expect(page.locator('#calendar')).toBeVisible();
    // "تسک جدید" button should be visible
    await expect(page.getByRole('button', { name: 'تسک جدید' })).toBeVisible();
  });

  test('can open create modal', async ({ page }) => {
    await page.getByRole('button', { name: 'تسک جدید' }).click();
    // Modal should appear with title input
    await expect(page.locator('input[wire\\:model="title"]')).toBeVisible();
    // Save button should be visible
    await expect(page.getByRole('button', { name: 'ذخیره' })).toBeVisible();
    // Cancel button should be visible
    await expect(page.getByRole('button', { name: 'لغو' })).toBeVisible();
  });

  test('can create a todo via modal', async ({ page }) => {
    await page.getByRole('button', { name: 'تسک جدید' }).click();
    await page.waitForTimeout(500);

    // Fill title
    await page.locator('input[wire\\:model="title"]').fill('تست E2E تسک');

    // Fill start date — input is readonly (Jalali date picker), so set value via JS
    // and trigger Livewire's wire:model.live binding
    const startDateInput = page.locator('input[data-jdp]').first();
    await startDateInput.evaluate((el) => {
      const input = el as HTMLInputElement;
      // Set the native value
      const nativeInputValueSetter = Object.getOwnPropertyDescriptor(
        window.HTMLInputElement.prototype, 'value'
      )!.set!;
      nativeInputValueSetter.call(input, '1405/07/01');
      // Dispatch events that wire:model.live / Alpine.js listens to
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(new Event('change', { bubbles: true }));
    });
    await page.waitForTimeout(300);

    // Fill start time
    const startTimeInput = page.locator('input[type="time"]').first();
    await startTimeInput.fill('09:00');

    // Submit
    await page.getByRole('button', { name: 'ذخیره' }).click();

    // Wait for Livewire to complete and toast to appear
    await waitForLivewire(page);
    await waitForToast(page, 'با موفقیت ذخیره شد');
  });

  test('can open and close modal', async ({ page }) => {
    await page.getByRole('button', { name: 'تسک جدید' }).click();
    await expect(page.locator('input[wire\\:model="title"]')).toBeVisible();

    // Close via cancel button
    await page.getByRole('button', { name: 'لغو' }).click();
    await page.waitForTimeout(500);

    // Modal should be hidden
    await expect(page.locator('input[wire\\:model="title"]')).not.toBeVisible();
  });

  /**
   * Issue #891 (behavioural half): the calendar used to return
   * `{ html: ... }` from `eventContent`, which FullCalendar assigns through
   * `dangerouslySetInnerHTML`, so a user-controlled title was executed as
   * markup in every viewer's browser.
   *
   * The Feature test can only assert the raw-HTML path is absent from the
   * source; this runs the real `eventContent` in Chromium and checks what the
   * browser actually did with a hostile title — it must become text, never a
   * node. `window.calendarInstance` is exposed by the component, so the hook
   * is invoked directly with a synthetic event.
   */
  test('a hostile todo title renders as text, never as markup', async ({ page }) => {
    await page.waitForFunction(() => !!(window as unknown as Record<string, unknown>).calendarInstance);

    const result = await page.evaluate(() => {
      const payload = '<img src=x onerror="window.__xssFired=1">';
      const calendar = (window as unknown as Record<string, unknown>).calendarInstance as {
        getOption: (name: string) => (arg: unknown) => { domNodes: HTMLElement[] };
      };
      const hook = calendar.getOption('eventContent');

      type Probe = {
        injectedElements: number;
        rawTagsInDom: boolean;
        textPreserved: boolean;
      };
      const out: Record<string, Probe> = {};

      for (const type of ['todo', 'ticket']) {
        const nodes = hook({
          event: {
            id: type === 'todo' ? 'todo-42' : 'ticket-42',
            title: type === 'todo' ? payload : '🎫 ' + payload,
            extendedProps: { type, is_completed: false, status: payload },
          },
          timeText: payload,
        }).domNodes;

        const wrapper = document.createElement('div');
        nodes.forEach((n: HTMLElement) => wrapper.appendChild(n));
        document.body.appendChild(wrapper);

        out[type] = {
          // Anything that became an ELEMENT is the bug; text is the goal.
          injectedElements: wrapper.querySelectorAll('img, script, iframe, svg image').length,
          rawTagsInDom: wrapper.innerHTML.includes('<img') || wrapper.innerHTML.includes('<script'),
          textPreserved: wrapper.textContent.includes(payload),
        };
        wrapper.remove();
      }
      return out;
    });

    expect(result.todo.injectedElements).toBe(0);
    expect(result.todo.rawTagsInDom).toBe(false);
    expect(result.todo.textPreserved).toBe(true);

    expect(result.ticket.injectedElements).toBe(0);
    expect(result.ticket.rawTagsInDom).toBe(false);
    expect(result.ticket.textPreserved).toBe(true);

    // And nothing executed while we were building it.
    const fired = await page.evaluate(
      () => (window as unknown as Record<string, unknown>).__xssFired ?? 0
    );
    expect(fired).toBe(0);
  });
});
