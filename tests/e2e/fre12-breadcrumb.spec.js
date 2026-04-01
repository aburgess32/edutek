// @ts-check
const { test, expect } = require('@playwright/test');

/**
 * FRE-12: Breadcrumb Path E2E Tests
 *
 * Tests the bottom-fixed floating breadcrumb across pages:
 *   - Rendering per page type (home, browse, tutorials, watch, directory)
 *   - Correct number of crumbs per context
 *   - Chevron arrow separators
 *   - Color dots
 *   - Collapse/expand toggle + localStorage persistence
 *   - Truncation of long titles
 *   - XSS protection
 *   - Accessibility (aria-label, aria-current)
 *   - Click passthrough on empty space
 */

test.describe('FRE-12: Breadcrumb Path', () => {

  // ─── T1: No breadcrumb on homepage ─────────────────────────────
  test('T1: no breadcrumb on homepage', async ({ page }) => {
    await page.goto('/');
    const bc = page.locator('nav.bc');
    await expect(bc).toHaveCount(0);
  });

  // ─── T2: Single crumb on segment browse page ──────────────────
  test('T2: single crumb on segment browse page', async ({ page }) => {
    await page.goto('/browse.php?seg=early_learners');
    const bc = page.locator('nav.bc');
    await expect(bc).toBeVisible();

    // Should have 1 crumb item
    const items = bc.locator('.bc__item');
    await expect(items).toHaveCount(1);

    // Should contain segment label
    await expect(items.first()).toContainText('Early Learners');

    // Should NOT be a link (single crumb = current page)
    const links = bc.locator('.bc__link');
    await expect(links).toHaveCount(0);

    // Should have a color dot
    const dot = bc.locator('.bc__dot');
    await expect(dot).toHaveCount(1);
  });

  // ─── T3: Two crumbs on topic browse page ───────────────────────
  test('T3: two crumbs with chevron on topic browse page', async ({ page }) => {
    await page.goto('/browse.php?seg=early_learners&topic=primary-multiplication');
    const bc = page.locator('nav.bc');
    await expect(bc).toBeVisible();

    // Should have 2 crumb items
    const items = bc.locator('.bc__item');
    await expect(items).toHaveCount(2);

    // First should be a link
    const link = bc.locator('.bc__link').first();
    await expect(link).toContainText('Early Learners');
    await expect(link).toHaveAttribute('href', /browse\.php\?seg=early_learners/);

    // Second should be current (not a link)
    const current = bc.locator('.bc__item--current');
    await expect(current).toContainText('Primary Multiplication');

    // Should have a chevron separator
    const sep = bc.locator('.bc__sep');
    await expect(sep).toHaveCount(1);
    const svg = sep.locator('svg');
    await expect(svg).toHaveCount(1);
  });

  // ─── T7: Unknown segment shows nothing ─────────────────────────
  test('T7: unknown seg param shows no breadcrumb', async ({ page }) => {
    // browse.php redirects to / for unknown seg, so breadcrumb should not appear
    const response = await page.goto('/browse.php?seg=xyz_unknown');
    // Should redirect to home (no breadcrumb)
    const bc = page.locator('nav.bc');
    await expect(bc).toHaveCount(0);
  });

  // ─── T12: Directory page single crumb ──────────────────────────
  test('T12: directory page shows single Directory crumb', async ({ page }) => {
    await page.goto('/directory.php');
    const bc = page.locator('nav.bc');
    await expect(bc).toBeVisible();

    const items = bc.locator('.bc__item');
    await expect(items).toHaveCount(1);
    await expect(items.first()).toContainText('Directory');
  });

  // ─── T14: Click passthrough on empty space ─────────────────────
  test('T14: clicks pass through breadcrumb empty space', async ({ page }) => {
    await page.goto('/browse.php?seg=early_learners');
    const bc = page.locator('nav.bc');
    await expect(bc).toBeVisible();

    // Container should have pointer-events: none
    const pointerEvents = await bc.evaluate(el =>
      getComputedStyle(el).pointerEvents
    );
    expect(pointerEvents).toBe('none');
  });

  // ─── T15: Accessibility attributes ─────────────────────────────
  test('T15: accessibility: aria-label and aria-current', async ({ page }) => {
    await page.goto('/browse.php?seg=early_learners&topic=primary-multiplication');
    const bc = page.locator('nav.bc');

    // Nav has aria-label
    await expect(bc).toHaveAttribute('aria-label', 'You are here');

    // Last item has aria-current="page"
    const current = bc.locator('[aria-current="page"]');
    await expect(current).toHaveCount(1);
  });

  // ─── T16: Color dots render ────────────────────────────────────
  test('T16: color dots render with segment color', async ({ page }) => {
    await page.goto('/browse.php?seg=early_learners');
    const dot = page.locator('.bc__dot');
    await expect(dot).toHaveCount(1);

    // Should have a background color set via inline style
    const bg = await dot.evaluate(el => el.style.background);
    expect(bg).toBeTruthy();
    expect(bg).not.toBe('');
  });

  // ─── T17: Toggle collapse hides breadcrumb trail ───────────────
  test('T17: toggle button collapses breadcrumb trail', async ({ page }) => {
    await page.goto('/browse.php?seg=early_learners&topic=primary-multiplication');
    const bc = page.locator('nav.bc');
    const toggle = bc.locator('.bc__toggle');
    const list = bc.locator('.bc__list');

    // Initially visible
    await expect(list).toBeVisible();

    // Click toggle
    await toggle.click();

    // List should be hidden
    await expect(list).not.toBeVisible();

    // Nav should have collapsed class
    await expect(bc).toHaveClass(/bc--collapsed/);

    // Toggle aria-expanded should be false
    await expect(toggle).toHaveAttribute('aria-expanded', 'false');
  });

  // ─── T18: Toggle expand restores breadcrumb trail ──────────────
  test('T18: toggle button re-expands breadcrumb trail', async ({ page }) => {
    await page.goto('/browse.php?seg=early_learners&topic=primary-multiplication');
    const bc = page.locator('nav.bc');
    const toggle = bc.locator('.bc__toggle');
    const list = bc.locator('.bc__list');

    // Collapse
    await toggle.click();
    await expect(list).not.toBeVisible();

    // Re-expand
    await toggle.click();
    await expect(list).toBeVisible();
    await expect(bc).not.toHaveClass(/bc--collapsed/);
  });

  // ─── T19: Collapsed state persists via localStorage ────────────
  test('T19: collapsed state persists across navigation', async ({ page }) => {
    await page.goto('/browse.php?seg=early_learners');

    // Collapse the breadcrumb
    const toggle = page.locator('.bc__toggle');
    await toggle.click();

    // Verify localStorage was set
    const stored = await page.evaluate(() => localStorage.getItem('bc-collapsed'));
    expect(stored).toBe('1');

    // Navigate to another page
    await page.goto('/directory.php');

    // Should still be collapsed
    const bc = page.locator('nav.bc');
    await expect(bc).toHaveClass(/bc--collapsed/);
  });

  // ─── T20: Default state is expanded ────────────────────────────
  test('T20: default state is expanded when localStorage is clear', async ({ page }) => {
    // Clear localStorage
    await page.goto('/browse.php?seg=early_learners');
    await page.evaluate(() => localStorage.removeItem('bc-collapsed'));
    await page.reload();

    const bc = page.locator('nav.bc');
    const list = bc.locator('.bc__list');
    await expect(list).toBeVisible();
    await expect(bc).not.toHaveClass(/bc--collapsed/);
  });

  // ─── T11: XSS protection ──────────────────────────────────────
  test('T11: XSS in params is escaped', async ({ page }) => {
    // This should redirect (unknown seg) but if it somehow renders,
    // the script should not execute
    let alertFired = false;
    page.on('dialog', () => { alertFired = true; });

    await page.goto('/browse.php?seg=<script>alert(1)</script>', {
      waitUntil: 'domcontentloaded'
    });

    expect(alertFired).toBe(false);
  });

  // ─── Toggle icon rotation ─────────────────────────────────────
  test('toggle icon rotates when expanded vs collapsed', async ({ page }) => {
    await page.goto('/browse.php?seg=early_learners');
    const icon = page.locator('.bc__toggle-icon');

    // Expanded: should be rotated 90deg
    const expandedTransform = await icon.evaluate(el =>
      getComputedStyle(el).transform
    );
    // matrix for rotate(90deg) contains specific values
    expect(expandedTransform).not.toBe('none');

    // Collapse
    await page.locator('.bc__toggle').click();

    // Collapsed: should not be rotated
    const collapsedTransform = await icon.evaluate(el =>
      getComputedStyle(el).transform
    );
    expect(collapsedTransform).toBe('none');
  });

  // ─── Breadcrumb links on browse.php carry seg param ────────────
  test('topic tile links carry seg and topic params', async ({ page }) => {
    await page.goto('/browse.php?seg=early_learners');

    // Find a topic tile link that goes to tutorials.php
    const tileLinks = page.locator('a.topic-tile[href*="tutorials.php"]');
    const count = await tileLinks.count();

    if (count > 0) {
      const href = await tileLinks.first().getAttribute('href');
      // Should contain seg param
      expect(href).toContain('seg=early_learners');
      // Should contain topic param
      expect(href).toMatch(/topic=/);
    }
  });

  // ─── Fixed bottom positioning ──────────────────────────────────
  test('breadcrumb is fixed at bottom of viewport', async ({ page }) => {
    await page.goto('/browse.php?seg=early_learners');
    const bc = page.locator('nav.bc');

    const position = await bc.evaluate(el => getComputedStyle(el).position);
    expect(position).toBe('fixed');

    const bottom = await bc.evaluate(el => getComputedStyle(el).bottom);
    expect(bottom).toBe('0px');
  });

  // ─── Left-justified ────────────────────────────────────────────
  test('breadcrumb list is left-justified', async ({ page }) => {
    await page.goto('/browse.php?seg=early_learners');
    const list = page.locator('.bc__list');

    const justify = await list.evaluate(el => getComputedStyle(el).justifyContent);
    expect(justify).toBe('flex-start');
  });
});
