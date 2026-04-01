// @ts-check
const { test, expect } = require('@playwright/test');

/**
 * FRE-14: Layout Mode Toggle E2E Tests
 *
 * Tests the mode cycle button (phone → tablet → screen):
 *   - Default mode is phone
 *   - Clicking cycles through modes
 *   - Mode persists via cookie across reload
 *   - Tablet mode shows 3-col grid
 *   - Screen mode shows dark background
 *   - Collapsed breadcrumb hides mode icon
 *   - Invalid cookie falls back to phone
 */

test.describe('FRE-14: Layout Mode Toggle', () => {

  // ─── T1: Fresh load defaults to phone mode ─────────────────────
  test('T1: fresh load defaults to phone mode', async ({ page, context }) => {
    // Clear any existing mode cookie
    await context.clearCookies();

    await page.goto('/browse.php?seg=early_learners');
    const body = page.locator('body');
    await expect(body).toHaveAttribute('data-mode', 'phone');

    // Mode icon should show phone emoji
    const modeIcon = page.locator('.bc__mode-icon');
    const text = await modeIcon.textContent();
    expect(text.trim()).toBe('\u{1F4F1}');
  });

  // ─── T2: Click mode icon cycles phone → tablet → screen → phone
  test('T2: click mode icon cycles through all modes', async ({ page, context }) => {
    await context.clearCookies();
    await page.goto('/browse.php?seg=early_learners');

    const modeBtn = page.locator('.bc__mode');
    const modeIcon = page.locator('.bc__mode-icon');
    const body = page.locator('body');

    // Initial: phone
    await expect(body).toHaveAttribute('data-mode', 'phone');

    // Click 1: phone → tablet
    await modeBtn.click();
    await expect(body).toHaveAttribute('data-mode', 'tablet');
    await expect(modeIcon).toHaveText('\u{1F4CB}');

    // Click 2: tablet → screen
    await modeBtn.click();
    await expect(body).toHaveAttribute('data-mode', 'screen');
    await expect(modeIcon).toHaveText('\u{1F4FA}');

    // Click 3: screen → phone
    await modeBtn.click();
    await expect(body).toHaveAttribute('data-mode', 'phone');
    await expect(modeIcon).toHaveText('\u{1F4F1}');
  });

  // ─── T3: Mode persists after page reload (cookie) ─────────────
  test('T3: mode persists after page reload via cookie', async ({ page, context }) => {
    await context.clearCookies();
    await page.goto('/browse.php?seg=early_learners');

    const modeBtn = page.locator('.bc__mode');

    // Switch to tablet
    await modeBtn.click();
    await expect(page.locator('body')).toHaveAttribute('data-mode', 'tablet');

    // Reload the page
    await page.reload();

    // Should still be tablet
    await expect(page.locator('body')).toHaveAttribute('data-mode', 'tablet');

    // Icon should show tablet emoji
    const modeIcon = page.locator('.bc__mode-icon');
    await expect(modeIcon).toHaveText('\u{1F4CB}');
  });

  // ─── T4: Tablet mode shows 3-col grid ─────────────────────────
  test('T4: tablet mode applies 3-col grid to segments', async ({ page, context }) => {
    await context.clearCookies();
    await page.goto('/browse.php?seg=early_learners');

    // Switch to tablet
    await page.locator('.bc__mode').click();
    await expect(page.locator('body')).toHaveAttribute('data-mode', 'tablet');

    // Check seg-tile flex-basis includes 33.333%
    const segTile = page.locator('.seg-tile').first();
    const count = await segTile.count();
    if (count > 0) {
      const flex = await segTile.evaluate(el => getComputedStyle(el).flexBasis);
      expect(flex).toContain('33');
    }
  });

  // ─── T5: Screen mode shows dark background ────────────────────
  test('T5: screen mode applies dark background', async ({ page, context }) => {
    await context.clearCookies();
    await page.goto('/browse.php?seg=early_learners');

    const modeBtn = page.locator('.bc__mode');

    // Click twice to get to screen mode (phone → tablet → screen)
    await modeBtn.click();
    await modeBtn.click();
    await expect(page.locator('body')).toHaveAttribute('data-mode', 'screen');

    // Body background should be black
    const bg = await page.locator('body').evaluate(el =>
      getComputedStyle(el).backgroundColor
    );
    expect(bg).toBe('rgb(0, 0, 0)');

    // Body text color should be white
    const color = await page.locator('body').evaluate(el =>
      getComputedStyle(el).color
    );
    expect(color).toBe('rgb(255, 255, 255)');
  });

  // ─── T6: Collapsed breadcrumb hides mode icon ─────────────────
  test('T6: collapsed breadcrumb hides mode icon', async ({ page }) => {
    await page.goto('/browse.php?seg=early_learners');

    const modeBtn = page.locator('.bc__mode');
    const toggle = page.locator('.bc__toggle');

    // Mode button should initially be visible
    await expect(modeBtn).toBeVisible();

    // Collapse breadcrumb
    await toggle.click();
    await expect(page.locator('nav.bc')).toHaveClass(/bc--collapsed/);

    // Mode button should be hidden
    await expect(modeBtn).not.toBeVisible();

    // Expand breadcrumb
    await toggle.click();
    await expect(page.locator('nav.bc')).not.toHaveClass(/bc--collapsed/);

    // Mode button should be visible again
    await expect(modeBtn).toBeVisible();
  });

  // ─── T7: Invalid cookie falls back to phone ───────────────────
  test('T7: invalid cookie value falls back to phone', async ({ page, context }) => {
    // Set an invalid mode cookie
    await context.addCookies([{
      name: 'edupak_mode',
      value: 'bogus',
      domain: 'localhost',
      path: '/'
    }]);

    await page.goto('/browse.php?seg=early_learners');

    // Should fall back to phone
    await expect(page.locator('body')).toHaveAttribute('data-mode', 'phone');
  });
});
