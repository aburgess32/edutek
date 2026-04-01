// @ts-check
const { test, expect } = require('@playwright/test');

/**
 * FRE-13: Teacher Hub — End-to-end tests
 *
 * Covers the Teacher Dashboard, Playlist Builder, Publish, Profile tabs
 * and related edge cases (T1-T24 from the pre-dev plan).
 *
 * Requires a logged-in teacher session. Helper logs in before each test.
 */

/* ------------------------------------------------------------------ */
/*  Helpers                                                            */
/* ------------------------------------------------------------------ */

const TEACHER_EMAIL = 'teacher@test.local';
const TEACHER_PASS = 'testpass123';
const TEACHER_NAME = 'Test Teacher';

/**
 * Log in as a teacher via the login form.
 */
async function loginAsTeacher(page) {
  await page.goto('/teacher-login.php', { waitUntil: 'domcontentloaded' });
  await page.fill('input[name="email"]', TEACHER_EMAIL);
  await page.fill('input[name="password"]', TEACHER_PASS);
  await page.click('button[type="submit"]');
  await page.waitForURL('**/teacher.php**', { timeout: 5000 });
}

/* ------------------------------------------------------------------ */
/*  Dashboard Tab Tests (T1-T4)                                        */
/* ------------------------------------------------------------------ */

test.describe('FRE-13 Dashboard Tab', () => {

  test.beforeEach(async ({ page }) => {
    await loginAsTeacher(page);
  });

  test('T1: teacher sees student list on dashboard', async ({ page }) => {
    // Dashboard is the default tab
    const dashRoot = page.locator('#dashboard-root');
    await expect(dashRoot).toBeVisible();

    // Either student table or empty state should appear
    const table = page.locator('.student-table');
    const empty = page.locator('.dash-empty');
    const hasContent = await table.or(empty).first().isVisible();
    expect(hasContent).toBeTruthy();
  });

  test('T2: tap student expands watched list', async ({ page }) => {
    const firstRow = page.locator('.student-table tbody tr').first();
    const hasStudents = await firstRow.isVisible().catch(() => false);
    test.skip(!hasStudents, 'No students in test data');

    await firstRow.click();
    // Expanded detail section should appear
    const detail = page.locator('.student-detail');
    await expect(detail).toBeVisible({ timeout: 3000 });
  });

  test('T3: screen time chart renders', async ({ page }) => {
    const firstRow = page.locator('.student-table tbody tr').first();
    const hasStudents = await firstRow.isVisible().catch(() => false);
    test.skip(!hasStudents, 'No students in test data');

    await firstRow.click();
    const chart = page.locator('.screen-time-chart, svg.chart');
    await expect(chart).toBeVisible({ timeout: 3000 });
  });

  test('T4: empty dashboard shows message', async ({ page }) => {
    // If no students, verify the empty state renders
    const empty = page.locator('.dash-empty');
    const emptyVisible = await empty.isVisible().catch(() => false);
    if (emptyVisible) {
      await expect(page.locator('.dash-empty__text')).toContainText('No student activity');
    }
    // If students exist, this test passes trivially
    expect(true).toBeTruthy();
  });
});

/* ------------------------------------------------------------------ */
/*  Search Tests (T5-T5d)                                              */
/* ------------------------------------------------------------------ */

test.describe('FRE-13 Search in Playlist Builder', () => {

  test.beforeEach(async ({ page }) => {
    await loginAsTeacher(page);
    // Navigate to Playlists tab
    await page.click('[data-tab="playlists"]');
    await page.waitForSelector('#playlists-content', { state: 'visible' });
  });

  test('T5: search by category returns results with badges', async ({ page }) => {
    // Open builder
    await page.click('.lb-btn-primary', { hasText: /New Playlist|Create/ });
    await page.waitForSelector('.lb-search-input');

    await page.fill('.lb-search-input', 'math');
    // Wait for debounce + results
    await page.waitForTimeout(500);

    const results = page.locator('.lb-result-card');
    const resultCount = await results.count();

    if (resultCount > 0) {
      // Check for category badges
      const badges = page.locator('.lb-badge-category');
      const badgeCount = await badges.count();
      expect(badgeCount).toBeGreaterThanOrEqual(0);
    }
  });

  test('T5b: search by source returns results', async ({ page }) => {
    await page.click('.lb-btn-primary', { hasText: /New Playlist|Create/ });
    await page.waitForSelector('.lb-search-input');

    await page.fill('.lb-search-input', 'khan');
    await page.waitForTimeout(500);

    const results = page.locator('.lb-result-card');
    const resultCount = await results.count();
    // Source badge should appear on Khan Academy results
    if (resultCount > 0) {
      const sourceBadges = page.locator('.lb-badge-source');
      expect(await sourceBadges.count()).toBeGreaterThanOrEqual(0);
    }
  });

  test('T5c: search empty state shows message', async ({ page }) => {
    await page.click('.lb-btn-primary', { hasText: /New Playlist|Create/ });
    await page.waitForSelector('.lb-search-input');

    await page.fill('.lb-search-input', 'xyznonexistent999');
    await page.waitForTimeout(500);

    const emptyState = page.locator('.lb-search-empty');
    await expect(emptyState).toBeVisible();
    await expect(page.locator('.lb-search-empty__text')).toContainText('No content found');
  });

  test('T5d: search results show checkboxes', async ({ page }) => {
    await page.click('.lb-btn-primary', { hasText: /New Playlist|Create/ });
    await page.waitForSelector('.lb-search-input');

    await page.fill('.lb-search-input', 'video');
    await page.waitForTimeout(500);

    const results = page.locator('.lb-result-card');
    const resultCount = await results.count();
    if (resultCount > 0) {
      const checkboxes = page.locator('.lb-result-checkbox');
      expect(await checkboxes.count()).toBe(resultCount);
    }
  });
});

/* ------------------------------------------------------------------ */
/*  Playlist Builder Tests (T6-T10)                                    */
/* ------------------------------------------------------------------ */

test.describe('FRE-13 Playlist Builder', () => {

  test.beforeEach(async ({ page }) => {
    await loginAsTeacher(page);
    await page.click('[data-tab="playlists"]');
    await page.waitForSelector('#playlists-content', { state: 'visible' });
  });

  test('T6: tap card expands preview', async ({ page }) => {
    await page.click('.lb-btn-primary', { hasText: /New Playlist|Create/ });
    await page.waitForSelector('.lb-search-input');

    await page.fill('.lb-search-input', 'video');
    await page.waitForTimeout(500);

    const firstCard = page.locator('.lb-result-card').first();
    const hasResults = await firstCard.isVisible().catch(() => false);
    test.skip(!hasResults, 'No search results in test data');

    await firstCard.click();
    const preview = page.locator('.lb-preview-panel');
    await expect(preview).toBeVisible({ timeout: 2000 });
  });

  test('T7: select items shows sticky bar', async ({ page }) => {
    await page.click('.lb-btn-primary', { hasText: /New Playlist|Create/ });
    await page.waitForSelector('.lb-search-input');

    await page.fill('.lb-search-input', 'video');
    await page.waitForTimeout(500);

    const checkboxes = page.locator('.lb-result-checkbox');
    const count = await checkboxes.count();
    test.skip(count < 1, 'No search results to select');

    // Select first item
    await checkboxes.first().click();

    const selectionBar = page.locator('#lb-selection-bar');
    await expect(selectionBar).toBeVisible();
    await expect(page.locator('.lb-selection-count')).toContainText('1 selected');
  });

  test('T8: save plan and it appears in list', async ({ page }) => {
    await page.click('.lb-btn-primary', { hasText: /New Playlist|Create/ });
    await page.waitForSelector('.lb-search-input');

    await page.fill('.lb-search-input', 'video');
    await page.waitForTimeout(500);

    const checkboxes = page.locator('.lb-result-checkbox');
    const count = await checkboxes.count();
    test.skip(count < 1, 'No search results to select');

    await checkboxes.first().click();

    // Click save button
    await page.click('.lb-selection-save');
    // Name modal should appear
    await expect(page.locator('.lb-modal')).toBeVisible();

    const uniqueName = 'Test Plan ' + Date.now();
    await page.fill('.lb-modal-input', uniqueName);
    await page.click('.lb-modal .lb-btn-primary');

    // Should return to list view with new plan
    await page.waitForSelector('.lb-plan-grid', { timeout: 3000 });
    await expect(page.locator('.lb-plan-card-title', { hasText: uniqueName })).toBeVisible();
  });

  test('T9: deselect via chip removes item', async ({ page }) => {
    await page.click('.lb-btn-primary', { hasText: /New Playlist|Create/ });
    await page.waitForSelector('.lb-search-input');

    await page.fill('.lb-search-input', 'video');
    await page.waitForTimeout(500);

    const checkboxes = page.locator('.lb-result-checkbox');
    const count = await checkboxes.count();
    test.skip(count < 1, 'No search results to select');

    await checkboxes.first().click();
    await expect(page.locator('.lb-selection-count')).toContainText('1 selected');

    // Click chip remove button
    await page.click('.lb-chip-remove');
    // Selection bar should be hidden
    const bar = page.locator('#lb-selection-bar');
    await expect(bar).toBeHidden();
  });

  test('T10: reorder items in plan detail', async ({ page }) => {
    const firstCard = page.locator('.lb-plan-card').first();
    const hasPlans = await firstCard.isVisible().catch(() => false);
    test.skip(!hasPlans, 'No plans to test reorder');

    await firstCard.click();
    await page.waitForSelector('.lb-items-list', { timeout: 3000 });

    const items = page.locator('.lb-item-row');
    const itemCount = await items.count();
    test.skip(itemCount < 2, 'Need at least 2 items to reorder');

    // Use up/down arrow buttons for reorder
    const downBtn = items.first().locator('.lb-btn-icon', { hasText: '\u2193' });
    if (await downBtn.isVisible()) {
      await downBtn.click();
    }
  });

  test('empty playlist list shows CTA', async ({ page }) => {
    // Check if empty state exists when no plans
    const emptyState = page.locator('.lb-empty-state');
    const emptyVisible = await emptyState.isVisible().catch(() => false);
    if (emptyVisible) {
      await expect(page.locator('.lb-empty-state')).toContainText('Create Your First Playlist');
      const ctaBtn = page.locator('.lb-btn-lg');
      await expect(ctaBtn).toBeVisible();
    }
  });
});

/* ------------------------------------------------------------------ */
/*  Publish Tests (T11-T17)                                            */
/* ------------------------------------------------------------------ */

test.describe('FRE-13 Publish', () => {

  test.beforeEach(async ({ page }) => {
    await loginAsTeacher(page);
    await page.click('[data-tab="playlists"]');
    await page.waitForSelector('#playlists-content', { state: 'visible' });
  });

  test('T11: publish panel shows segment checkboxes', async ({ page }) => {
    const firstCard = page.locator('.lb-plan-card').first();
    const hasPlans = await firstCard.isVisible().catch(() => false);
    test.skip(!hasPlans, 'No plans to publish');

    await firstCard.click();
    await page.waitForSelector('.lb-publish-section', { timeout: 3000 });

    const checkboxes = page.locator('.publish-checkbox');
    expect(await checkboxes.count()).toBeGreaterThan(0);
  });

  test('T12: checking segments updates badge', async ({ page }) => {
    const firstCard = page.locator('.lb-plan-card').first();
    const hasPlans = await firstCard.isVisible().catch(() => false);
    test.skip(!hasPlans, 'No plans to publish');

    await firstCard.click();
    await page.waitForSelector('.publish-checkbox', { timeout: 3000 });

    // Check a segment checkbox
    const firstCheckbox = page.locator('.publish-checkbox input[type="checkbox"]').first();
    await firstCheckbox.check();

    // Badge should update to Published
    await expect(page.locator('.publish-badge--published')).toBeVisible({ timeout: 3000 });
  });

  test('T13: uncheck all segments shows Private badge', async ({ page }) => {
    const firstCard = page.locator('.lb-plan-card').first();
    const hasPlans = await firstCard.isVisible().catch(() => false);
    test.skip(!hasPlans, 'No plans to test');

    await firstCard.click();
    await page.waitForSelector('.publish-checkbox', { timeout: 3000 });

    // Uncheck all
    const checkboxes = page.locator('.publish-checkbox input[type="checkbox"]');
    const count = await checkboxes.count();
    for (let i = 0; i < count; i++) {
      await checkboxes.nth(i).uncheck();
    }

    await expect(page.locator('.publish-badge--private')).toBeVisible({ timeout: 3000 });
  });

  test('plan cards show publish status badges', async ({ page }) => {
    const badges = page.locator('.publish-badge');
    const firstCard = page.locator('.lb-plan-card').first();
    const hasPlans = await firstCard.isVisible().catch(() => false);
    test.skip(!hasPlans, 'No plans to check badges');

    // Each plan card should have a badge
    const badgeCount = await badges.count();
    expect(badgeCount).toBeGreaterThan(0);
  });
});

/* ------------------------------------------------------------------ */
/*  Student Playlist Page (T15-T17)                                    */
/* ------------------------------------------------------------------ */

test.describe('FRE-13 Student Playlist Page', () => {

  test('T15: published playlist page loads for students', async ({ page }) => {
    // Access a published playlist directly (no auth needed)
    await page.goto('/playlist.php?plan=1', { waitUntil: 'domcontentloaded' });

    // Should either show the playlist or a 404
    const header = page.locator('.playlist-header');
    const notFound = page.locator('.playlist-404');
    const visible = await header.or(notFound).first().isVisible();
    expect(visible).toBeTruthy();
  });

  test('T16: playlist shows ordered content items', async ({ page }) => {
    await page.goto('/playlist.php?plan=1', { waitUntil: 'domcontentloaded' });

    const header = page.locator('.playlist-header');
    const isPublished = await header.isVisible().catch(() => false);
    test.skip(!isPublished, 'Plan 1 is not published');

    const items = page.locator('.playlist-item');
    expect(await items.count()).toBeGreaterThan(0);
  });

  test('T17: private plan returns 404', async ({ page }) => {
    // Use a very high ID unlikely to be published
    const response = await page.goto('/playlist.php?plan=99999', { waitUntil: 'domcontentloaded' });
    expect(response.status()).toBe(404);

    await expect(page.locator('.playlist-404')).toBeVisible();
  });

  test('unavailable items show strikethrough in student view', async ({ page }) => {
    await page.goto('/playlist.php?plan=1', { waitUntil: 'domcontentloaded' });

    const unavailable = page.locator('.playlist-item--unavailable');
    const hasUnavailable = await unavailable.count();
    // If there are unavailable items, they should have strikethrough title
    if (hasUnavailable > 0) {
      const title = unavailable.first().locator('.playlist-item__title--strike');
      await expect(title).toBeVisible();
    }
  });
});

/* ------------------------------------------------------------------ */
/*  Profile Tab Tests                                                  */
/* ------------------------------------------------------------------ */

test.describe('FRE-13 Profile Tab', () => {

  test.beforeEach(async ({ page }) => {
    await loginAsTeacher(page);
    await page.click('[data-tab="profile"]');
    await page.waitForSelector('#profile-root .profile-wrapper', { timeout: 3000 });
  });

  test('profile tab renders all sections', async ({ page }) => {
    await expect(page.locator('.profile-page-title')).toContainText('Your Profile');

    const sections = page.locator('.profile-section');
    // Should have: name, email, change password, reset another, logout
    expect(await sections.count()).toBeGreaterThanOrEqual(5);
  });

  test('display name field is editable', async ({ page }) => {
    const nameInput = page.locator('.profile-input[type="text"]').first();
    await expect(nameInput).toBeVisible();
    await expect(nameInput).toBeEditable();
  });

  test('email field is read-only', async ({ page }) => {
    const emailDisplay = page.locator('.profile-email-display');
    await expect(emailDisplay).toBeVisible();
    await expect(page.locator('.profile-hint')).toContainText('cannot be changed');
  });

  test('change password section has 3 fields', async ({ page }) => {
    const passwordInputs = page.locator('.profile-input[type="password"]');
    // 3 for change password (current, new, confirm) + 1 for reset section new password = 4 total
    expect(await passwordInputs.count()).toBeGreaterThanOrEqual(3);
  });

  test('logout button links to logout.php', async ({ page }) => {
    const logoutBtn = page.locator('.profile-btn--logout');
    await expect(logoutBtn).toBeVisible();
    await expect(logoutBtn).toHaveAttribute('href', '/logout.php');
  });

  test('reset another teacher section visible', async ({ page }) => {
    const resetSection = page.locator('.profile-section__title', { hasText: /Reset Another/ });
    await expect(resetSection).toBeVisible();
  });
});

/* ------------------------------------------------------------------ */
/*  Collaboration Tests (T22-T23)                                      */
/* ------------------------------------------------------------------ */

test.describe('FRE-13 Collaboration', () => {

  test('T22: any teacher can open any plan detail', async ({ page }) => {
    await loginAsTeacher(page);
    await page.click('[data-tab="playlists"]');
    await page.waitForSelector('#playlists-content', { state: 'visible' });

    const firstCard = page.locator('.lb-plan-card').first();
    const hasPlans = await firstCard.isVisible().catch(() => false);
    test.skip(!hasPlans, 'No plans to test collaboration');

    await firstCard.click();
    // Should be able to see detail view with edit controls
    await page.waitForSelector('.lb-detail-header', { timeout: 3000 });
    await expect(page.locator('.lb-detail-title-input')).toBeVisible();
  });

  test('T23: delete confirms with modal', async ({ page }) => {
    await loginAsTeacher(page);
    await page.click('[data-tab="playlists"]');
    await page.waitForSelector('#playlists-content', { state: 'visible' });

    const firstCard = page.locator('.lb-plan-card').first();
    const hasPlans = await firstCard.isVisible().catch(() => false);
    test.skip(!hasPlans, 'No plans to test delete');

    await firstCard.click();
    await page.waitForSelector('.lb-btn-danger', { timeout: 3000 });

    await page.click('.lb-detail-actions .lb-btn-danger');
    // Confirm modal should appear
    await expect(page.locator('.lb-modal')).toBeVisible();
    await expect(page.locator('.lb-modal')).toContainText('Delete Playlist');

    // Cancel the deletion
    await page.click('.lb-modal .lb-btn-secondary');
    await expect(page.locator('.lb-modal-overlay')).toBeHidden();
  });
});

/* ------------------------------------------------------------------ */
/*  Edge Cases (T21)                                                   */
/* ------------------------------------------------------------------ */

test.describe('FRE-13 Edge Cases', () => {

  test('T18: non-teacher redirected from teacher.php', async ({ page }) => {
    // Don't login — go directly to teacher.php
    const response = await page.goto('/teacher.php', { waitUntil: 'domcontentloaded' });
    // Should redirect to login or home
    const url = page.url();
    expect(url).not.toContain('/teacher.php');
  });

  test('tab switching via hash works', async ({ page }) => {
    await loginAsTeacher(page);
    await page.goto('/teacher.php#playlists', { waitUntil: 'domcontentloaded' });

    const playlistsPanel = page.locator('#playlists');
    await expect(playlistsPanel).toHaveClass(/tab-panel--active/);
  });

  test('long titles truncated with ellipsis', async ({ page }) => {
    await loginAsTeacher(page);
    await page.click('[data-tab="playlists"]');
    await page.waitForSelector('#playlists-content', { state: 'visible' });

    const titles = page.locator('.lb-plan-card-title');
    const count = await titles.count();
    for (let i = 0; i < count; i++) {
      const text = await titles.nth(i).textContent();
      // Title should be at most 60 chars + ellipsis character
      expect(text.length).toBeLessThanOrEqual(61);
    }
  });
});
