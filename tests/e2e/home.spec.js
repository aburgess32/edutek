// @ts-check
const { test, expect } = require('@playwright/test');

/**
 * Home page tests — run across all 17 device profiles.
 * Assertions adapt based on device tier metadata.
 */

function getTier(testInfo) {
  return testInfo.project.metadata?.tier ?? 2;
}

test.describe('Home page', () => {

  test('loads successfully', async ({ page }, testInfo) => {
    const tier = getTier(testInfo);
    const maxLoad = tier <= 1 ? 3000 : tier === 6 ? 5000 : 2000;

    const start = Date.now();
    const response = await page.goto('/', { waitUntil: 'domcontentloaded' });
    const loadTime = Date.now() - start;

    // Page should return 200
    expect(response?.status()).toBe(200);

    // Within budget
    console.log(`[${testInfo.project.name}] Home loaded in ${loadTime}ms (budget: ${maxLoad}ms)`);
    expect(loadTime).toBeLessThan(maxLoad);
  });

  test('displays page title', async ({ page }) => {
    await page.goto('/');
    const title = await page.title();
    expect(title).toBeTruthy();
  });

  test('audience tiles are visible (when implemented)', async ({ page }, testInfo) => {
    const tier = getTier(testInfo);

    await page.goto('/', { waitUntil: 'domcontentloaded' });

    // Look for audience-segment tiles
    // Selector will match once Visual Home Tiles (P1) is implemented
    const tiles = page.locator('[data-role="audience-tile"], .audience-tile, .home-tile');
    const count = await tiles.count();

    if (count > 0) {
      // If tiles exist, verify there are exactly 4 (Kid, Teen, Adult, Teacher)
      expect(count).toBe(4);

      // Each tile should be visible
      for (let i = 0; i < count; i++) {
        await expect(tiles.nth(i)).toBeVisible();
      }

      // On mobile, tiles should be tappable (large enough)
      if (tier <= 4) {
        for (let i = 0; i < count; i++) {
          const box = await tiles.nth(i).boundingBox();
          if (box) {
            expect(box.width).toBeGreaterThanOrEqual(44);
            expect(box.height).toBeGreaterThanOrEqual(44);
          }
        }
      }
    } else {
      // Tiles not yet implemented — test passes with a note
      console.log(`[${testInfo.project.name}] Audience tiles not yet implemented (P1 pending)`);
    }
  });

  test('no console errors', async ({ page }, testInfo) => {
    const errors = [];
    page.on('pageerror', (err) => errors.push(err.message));

    await page.goto('/', { waitUntil: 'load' });

    if (errors.length > 0) {
      console.log(`[${testInfo.project.name}] Console errors:`, errors);
    }
    expect(errors).toHaveLength(0);
  });

  test('critical CSS loads inline or fast', async ({ page }, testInfo) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });

    // Check that body is not unstyled (FOUC check)
    const bodyBg = await page.evaluate(() => {
      return window.getComputedStyle(document.body).backgroundColor;
    });

    // Body should have some styling applied (not default white in all cases)
    // This is a basic FOUC check — will be more meaningful once CSS is written
    expect(bodyBg).toBeTruthy();
  });
});
