// @ts-check
const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');

/**
 * FRE-9: Visual Home Tiles — End-to-end tests
 *
 * Tests the segment tiles on the homepage, browse page, and directory page.
 * Runs against a single default project to keep execution fast;
 * responsive tests override the viewport explicitly.
 */

/* ------------------------------------------------------------------ */
/*  Helpers                                                            */
/* ------------------------------------------------------------------ */

const SEGMENT_KEYS = [
  'early_learners',
  'explorers',
  'advanced',
  'educators',
  'knowledge_power',
];

const SEGMENT_LABELS = [
  'Early Learners',
  'Explorers',
  'Advanced',
  'Educators',
  'Knowledge is Power',
];

const SCREENSHOT_DIR = path.join(__dirname, 'screenshots');

/* ------------------------------------------------------------------ */
/*  Homepage Tests                                                     */
/* ------------------------------------------------------------------ */

test.describe('FRE-9 Homepage', () => {

  test('T1: page loads with 5 segment tiles visible', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });

    const tiles = page.locator('.seg-tile');
    await expect(tiles).toHaveCount(5);

    for (let i = 0; i < 5; i++) {
      await expect(tiles.nth(i)).toBeVisible();
    }
  });

  test('T2: each segment tile has correct label text', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });

    for (const label of SEGMENT_LABELS) {
      const tile = page.locator('.seg-tile-label', { hasText: label });
      await expect(tile).toBeVisible();
    }
  });

  test('T3: "All Content" section displays content cards', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });

    const sectionHeader = page.locator('.tiles-section-title', { hasText: 'All Content' });
    await expect(sectionHeader).toBeVisible();

    const cards = page.locator('.all-content-card');
    const count = await cards.count();
    expect(count).toBeGreaterThan(0);
  });

  test('T4: "View Directory" link exists and navigates to directory.php', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });

    const link = page.locator('a.tiles-section-link', { hasText: 'View Directory' });
    await expect(link).toBeVisible();
    await expect(link).toHaveAttribute('href', 'directory.php');

    await link.click();
    await page.waitForURL('**/directory.php');
    await expect(page).toHaveURL(/directory\.php/);
  });

  test('T5: segment tile click navigates to browse.php?seg={key}', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });

    // Test first segment tile (early_learners)
    const firstTile = page.locator('.seg-tile').first();
    const href = await firstTile.getAttribute('href');
    expect(href).toContain('browse.php?seg=');

    // Click and verify navigation
    await firstTile.click();
    await page.waitForURL('**/browse.php?seg=*');
    await expect(page).toHaveURL(/browse\.php\?seg=/);
  });

});

/* ------------------------------------------------------------------ */
/*  Browse Page Tests                                                  */
/* ------------------------------------------------------------------ */

test.describe('FRE-9 Browse Page', () => {

  test('T6: browse.php?seg=early_learners shows topic tiles', async ({ page }) => {
    await page.goto('/browse.php?seg=early_learners', { waitUntil: 'domcontentloaded' });

    const header = page.locator('.browse-seg-label');
    await expect(header).toHaveText('Early Learners');

    const topics = page.locator('.topic-tile');
    const count = await topics.count();
    expect(count).toBeGreaterThan(0);
  });

  test('T7: browse.php?seg=knowledge_power shows empty state message', async ({ page }) => {
    await page.goto('/browse.php?seg=knowledge_power', { waitUntil: 'domcontentloaded' });

    const header = page.locator('.browse-seg-label');
    await expect(header).toHaveText('Knowledge is Power');

    const emptyState = page.locator('.tiles-empty');
    await expect(emptyState).toBeVisible();

    const emptyText = page.locator('.tiles-empty-text');
    await expect(emptyText).toHaveText('Your teacher will add priority content here.');
  });

  test('T8: invalid seg parameter redirects to homepage', async ({ page }) => {
    const response = await page.goto('/browse.php?seg=nonexistent_segment', {
      waitUntil: 'domcontentloaded',
    });

    // Should redirect to homepage
    await expect(page).toHaveURL(/\/($|index\.php)/);
  });

  test('T9: back button/link returns to homepage', async ({ page }) => {
    await page.goto('/browse.php?seg=early_learners', { waitUntil: 'domcontentloaded' });

    const backLink = page.locator('.browse-back');
    await expect(backLink).toBeVisible();
    await expect(backLink).toHaveAttribute('href', 'index.php');

    await backLink.click();
    await page.waitForURL('**/index.php');
  });

  test('T10: topic tiles have correct type badges', async ({ page }) => {
    await page.goto('/browse.php?seg=early_learners', { waitUntil: 'domcontentloaded' });

    const badges = page.locator('.topic-tile .type-badge');
    const count = await badges.count();
    expect(count).toBeGreaterThan(0);

    // Every badge should contain a valid type label
    const validTypes = ['Video', 'Audio', 'Book', 'Tool', 'Resource', 'Mixed'];
    for (let i = 0; i < count; i++) {
      const text = await badges.nth(i).textContent();
      const trimmed = text?.trim();
      expect(validTypes).toContain(trimmed);
    }
  });

});

/* ------------------------------------------------------------------ */
/*  Directory Page Tests                                               */
/* ------------------------------------------------------------------ */

test.describe('FRE-9 Directory Page', () => {

  test('T11: directory page loads with anchor nav', async ({ page }) => {
    await page.goto('/directory.php', { waitUntil: 'domcontentloaded' });

    const anchorNav = page.locator('.dir-anchor-nav');
    await expect(anchorNav).toBeVisible();

    const links = page.locator('.dir-anchor-link');
    const count = await links.count();
    // 5 segments + "All" link = at least 6
    expect(count).toBeGreaterThanOrEqual(6);
  });

  test('T12: search input filters items', async ({ page }) => {
    await page.goto('/directory.php', { waitUntil: 'domcontentloaded' });

    const searchInput = page.locator('#dir-search');
    await expect(searchInput).toBeVisible();

    // Count all items before search
    const allItems = page.locator('.dir-item');
    const totalBefore = await allItems.count();
    expect(totalBefore).toBeGreaterThan(0);

    // Type a search query that matches a known item
    await searchInput.fill('Khan');

    // Wait for debounce (300ms in tiles.js)
    await page.waitForTimeout(400);

    // Some items should be hidden
    const hiddenItems = page.locator('.dir-item.dir-item--hidden');
    const hiddenCount = await hiddenItems.count();
    expect(hiddenCount).toBeGreaterThan(0);

    // At least one item should still be visible (Khan Academy exists)
    const visibleItems = page.locator('.dir-item:not(.dir-item--hidden)');
    const visibleCount = await visibleItems.count();
    expect(visibleCount).toBeGreaterThan(0);
  });

  test('T13: anchor nav contains all segment names', async ({ page }) => {
    await page.goto('/directory.php', { waitUntil: 'domcontentloaded' });

    const anchors = page.locator('.dir-anchor-link');

    for (const label of SEGMENT_LABELS) {
      const link = anchors.filter({ hasText: label });
      // Segments with topics should appear; knowledge_power has no topics so
      // it won't have a dir-section, but the anchor nav renders all segments
      // from the PHP foreach. Verify the nav link exists.
      await expect(link).toHaveCount(1);
    }

    // Also verify "All" link
    const allLink = anchors.filter({ hasText: 'All' });
    await expect(allLink).toHaveCount(1);
  });

  test('T14: "All" section lists all content alphabetically', async ({ page }) => {
    await page.goto('/directory.php', { waitUntil: 'domcontentloaded' });

    const allSection = page.locator('#dir-all');
    await expect(allSection).toBeVisible();

    const items = allSection.locator('.dir-item');
    const count = await items.count();
    expect(count).toBeGreaterThan(0);

    // Verify alphabetical order
    const labels = [];
    for (let i = 0; i < count; i++) {
      const label = await items.nth(i).getAttribute('data-label');
      if (label) labels.push(label);
    }
    const sorted = [...labels].sort((a, b) => a.localeCompare(b, 'en', { sensitivity: 'base' }));
    expect(labels).toEqual(sorted);
  });

  test('T15: search with no results shows "no matching content" message', async ({ page }) => {
    await page.goto('/directory.php', { waitUntil: 'domcontentloaded' });

    const searchInput = page.locator('#dir-search');
    await searchInput.fill('zzzznonexistent12345');

    // Wait for debounce
    await page.waitForTimeout(400);

    const noResults = page.locator('#dir-no-results');
    await expect(noResults).toBeVisible();
    await expect(noResults).toHaveText('No matching content found.');
  });

});

/* ------------------------------------------------------------------ */
/*  Responsive Tests                                                   */
/* ------------------------------------------------------------------ */

test.describe('FRE-9 Responsive', () => {

  test('T16: homepage on mobile (375px) shows segment tiles in 2 columns max', async ({ browser }) => {
    const context = await browser.newContext({
      viewport: { width: 375, height: 667 },
      isMobile: true,
    });
    const page = await context.newPage();
    await page.goto('/', { waitUntil: 'domcontentloaded' });

    // At 375px (< 400px breakpoint), CSS sets seg-tile to 100% width = 1 column
    // At 400-599px, default is 2 columns (50% - gap)
    // 375px falls into the <400px range => 1 column
    const tiles = page.locator('.seg-tile');
    const count = await tiles.count();
    expect(count).toBe(5);

    // Verify tiles don't exceed 2 per row by checking bounding boxes
    const boxes = [];
    for (let i = 0; i < count; i++) {
      const box = await tiles.nth(i).boundingBox();
      if (box) boxes.push(box);
    }

    // Group by approximate Y position (same row = same top)
    const rows = new Map();
    for (const box of boxes) {
      const rowKey = Math.round(box.y / 10) * 10;
      rows.set(rowKey, (rows.get(rowKey) || 0) + 1);
    }

    for (const [, tilesInRow] of rows) {
      expect(tilesInRow).toBeLessThanOrEqual(2);
    }

    await context.close();
  });

  test('T17: homepage on tablet (768px) shows expanded grid', async ({ browser }) => {
    const context = await browser.newContext({
      viewport: { width: 768, height: 1024 },
    });
    const page = await context.newPage();
    await page.goto('/', { waitUntil: 'domcontentloaded' });

    const tiles = page.locator('.seg-tile');
    const count = await tiles.count();
    expect(count).toBe(5);

    // At 768px (>600px), the grid should allow more columns
    // Verify at least one row has more than 1 tile
    const boxes = [];
    for (let i = 0; i < count; i++) {
      const box = await tiles.nth(i).boundingBox();
      if (box) boxes.push(box);
    }

    const rows = new Map();
    for (const box of boxes) {
      const rowKey = Math.round(box.y / 10) * 10;
      rows.set(rowKey, (rows.get(rowKey) || 0) + 1);
    }

    // Should have at least one row with 2+ tiles
    const maxPerRow = Math.max(...rows.values());
    expect(maxPerRow).toBeGreaterThanOrEqual(2);

    await context.close();
  });

  test('T18: directory on mobile is single column', async ({ browser }) => {
    const context = await browser.newContext({
      viewport: { width: 375, height: 667 },
      isMobile: true,
    });
    const page = await context.newPage();
    await page.goto('/directory.php', { waitUntil: 'domcontentloaded' });

    // Below 600px, dir-list has no column-count set = single column
    const columnCount = await page.locator('.dir-list').first().evaluate((el) => {
      return window.getComputedStyle(el).columnCount;
    });
    expect(columnCount).toBe('auto');

    await context.close();
  });

});

/* ------------------------------------------------------------------ */
/*  Edge Case Tests                                                    */
/* ------------------------------------------------------------------ */

test.describe('FRE-9 Edge Cases', () => {

  test('T19: browse.php with no seg param redirects to /', async ({ page }) => {
    await page.goto('/browse.php', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/($|index\.php)/);
  });

  test('T20: browse.php with XSS seg param redirects to / (XSS prevention)', async ({ page }) => {
    await page.goto('/browse.php?seg=%3Cscript%3Ealert(1)%3C%2Fscript%3E', {
      waitUntil: 'domcontentloaded',
    });
    await expect(page).toHaveURL(/\/($|index\.php)/);
  });

});

/* ------------------------------------------------------------------ */
/*  Visual Regression Screenshots                                      */
/* ------------------------------------------------------------------ */

test.describe('FRE-9 Screenshots', () => {

  const widths = [
    { name: '375px', width: 375, height: 667, isMobile: true },
    { name: '1280px', width: 1280, height: 720, isMobile: false },
  ];

  for (const vp of widths) {

    test(`screenshot: homepage at ${vp.name}`, async ({ browser }) => {
      const context = await browser.newContext({
        viewport: { width: vp.width, height: vp.height },
        isMobile: vp.isMobile,
      });
      const page = await context.newPage();
      await page.goto('/', { waitUntil: 'load' });

      // Ensure screenshots dir exists
      if (!fs.existsSync(SCREENSHOT_DIR)) {
        fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
      }

      await page.screenshot({
        path: path.join(SCREENSHOT_DIR, `homepage-${vp.name}.png`),
        fullPage: true,
      });
      await context.close();
    });

    test(`screenshot: browse at ${vp.name}`, async ({ browser }) => {
      const context = await browser.newContext({
        viewport: { width: vp.width, height: vp.height },
        isMobile: vp.isMobile,
      });
      const page = await context.newPage();
      await page.goto('/browse.php?seg=early_learners', { waitUntil: 'load' });

      if (!fs.existsSync(SCREENSHOT_DIR)) {
        fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
      }

      await page.screenshot({
        path: path.join(SCREENSHOT_DIR, `browse-${vp.name}.png`),
        fullPage: true,
      });
      await context.close();
    });

    test(`screenshot: directory at ${vp.name}`, async ({ browser }) => {
      const context = await browser.newContext({
        viewport: { width: vp.width, height: vp.height },
        isMobile: vp.isMobile,
      });
      const page = await context.newPage();
      await page.goto('/directory.php', { waitUntil: 'load' });

      if (!fs.existsSync(SCREENSHOT_DIR)) {
        fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
      }

      await page.screenshot({
        path: path.join(SCREENSHOT_DIR, `directory-${vp.name}.png`),
        fullPage: true,
      });
      await context.close();
    });

  }

});
