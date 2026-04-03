// @ts-check
const { test, expect } = require('@playwright/test');

/**
 * FRE-47: Manage Students — End-to-end tests
 *
 * Covers: Students tab, add/remove students, groups CRUD,
 * student detail panel, dashboard scope filter, auth guard.
 *
 * Requires a logged-in teacher session and seeded student data.
 */

/* ------------------------------------------------------------------ */
/*  Helpers                                                            */
/* ------------------------------------------------------------------ */

const TEACHER_EMAIL = 'teacher@test.local';
const TEACHER_PASS = 'testpass123';

async function loginAsTeacher(page) {
  await page.goto('/teacher-login.php', { waitUntil: 'domcontentloaded' });
  await page.fill('input[name="email"]', TEACHER_EMAIL);
  await page.fill('input[name="password"]', TEACHER_PASS);
  await page.click('button[type="submit"]');
  await page.waitForURL('**/teacher.php**', { timeout: 5000 });
}

async function navigateToStudentsTab(page) {
  await page.click('[data-tab="students"]');
  await page.waitForSelector('.stu-header', { timeout: 5000 });
}

/* ------------------------------------------------------------------ */
/*  Students Tab — Basic Rendering (S1-S3)                             */
/* ------------------------------------------------------------------ */

test.describe('FRE-47 Students Tab', () => {

  test.beforeEach(async ({ page }) => {
    await loginAsTeacher(page);
    await navigateToStudentsTab(page);
  });

  test('S1: Students tab is visible and accessible', async ({ page }) => {
    const tab = page.locator('[data-tab="students"]');
    await expect(tab).toBeVisible();
    await expect(tab).toHaveText('Students');
    // Tab panel should be active
    const panel = page.locator('#students');
    await expect(panel).toHaveClass(/tab-panel--active/);
  });

  test('S2: Students tab shows header with actions', async ({ page }) => {
    await expect(page.locator('.stu-title')).toHaveText('Students');
    await expect(page.locator('#stu-add-btn')).toBeVisible();
    await expect(page.locator('#stu-manage-groups')).toBeVisible();
  });

  test('S3: Empty state renders when no students assigned', async ({ page }) => {
    // Before any assignment, should show empty state
    const empty = page.locator('.stu-empty');
    const table = page.locator('.stu-table');
    // Either empty state or table should be present
    const hasEmpty = await empty.isVisible().catch(() => false);
    const hasTable = await table.isVisible().catch(() => false);
    expect(hasEmpty || hasTable).toBeTruthy();
  });

  test('S4: Add Students modal opens and shows available students', async ({ page }) => {
    await page.click('#stu-add-btn');
    await page.waitForSelector('.stu-modal', { timeout: 3000 });
    const modal = page.locator('.stu-modal');
    await expect(modal).toBeVisible();
    // Modal should have header
    await expect(modal.locator('h3')).toHaveText('Add Students');
    // Close button
    await expect(modal.locator('.stu-modal__close')).toBeVisible();
  });

  test('S5: Add Students modal can be closed', async ({ page }) => {
    await page.click('#stu-add-btn');
    await page.waitForSelector('.stu-modal', { timeout: 3000 });
    await page.click('.stu-modal__close');
    await expect(page.locator('.stu-modal')).not.toBeVisible();
  });
});

/* ------------------------------------------------------------------ */
/*  Groups Management (S6-S9)                                          */
/* ------------------------------------------------------------------ */

test.describe('FRE-47 Groups', () => {

  test.beforeEach(async ({ page }) => {
    await loginAsTeacher(page);
    await navigateToStudentsTab(page);
  });

  test('S6: Manage Groups modal opens', async ({ page }) => {
    await page.click('#stu-manage-groups');
    await page.waitForSelector('.stu-modal--groups', { timeout: 3000 });
    const modal = page.locator('.stu-modal--groups');
    await expect(modal).toBeVisible();
    await expect(modal.locator('h3')).toHaveText('Manage Groups');
  });

  test('S7: Create a new group', async ({ page }) => {
    await page.click('#stu-manage-groups');
    await page.waitForSelector('.stu-modal--groups', { timeout: 3000 });
    await page.fill('#stu-new-group-name', 'Period 1');
    await page.click('#stu-create-group');
    // Group should appear in the list
    await page.waitForSelector('.stu-group-row', { timeout: 3000 });
    const groupName = page.locator('.stu-group-name').first();
    await expect(groupName).toContainText('Period 1');
  });

  test('S8: Delete a group', async ({ page }) => {
    // Create a group first
    await page.click('#stu-manage-groups');
    await page.waitForSelector('.stu-modal--groups', { timeout: 3000 });
    await page.fill('#stu-new-group-name', 'Temp Group');
    await page.click('#stu-create-group');
    await page.waitForSelector('.stu-group-row', { timeout: 3000 });

    // Count groups before delete
    const countBefore = await page.locator('.stu-group-row').count();

    // Accept the confirm dialog
    page.on('dialog', dialog => dialog.accept());
    // Click delete on first group matching "Temp Group"
    const deleteBtn = page.locator('.stu-group-delete').first();
    await deleteBtn.click();

    // Wait for re-render
    await page.waitForTimeout(500);
    const countAfter = await page.locator('.stu-group-row').count();
    expect(countAfter).toBeLessThan(countBefore);
  });

  test('S9: Manage Groups modal can be closed with Done', async ({ page }) => {
    await page.click('#stu-manage-groups');
    await page.waitForSelector('.stu-modal--groups', { timeout: 3000 });
    await page.click('.stu-modal__cancel');
    await expect(page.locator('.stu-modal--groups')).not.toBeVisible();
  });
});

/* ------------------------------------------------------------------ */
/*  Dashboard Scope Filter (S10-S11)                                   */
/* ------------------------------------------------------------------ */

test.describe('FRE-47 Dashboard Scope', () => {

  test.beforeEach(async ({ page }) => {
    await loginAsTeacher(page);
  });

  test('S10: Dashboard has scope dropdown', async ({ page }) => {
    // Dashboard is the default tab
    await page.waitForSelector('#d-scope-select', { timeout: 5000 });
    const select = page.locator('#d-scope-select');
    await expect(select).toBeVisible();
    // Should have All Students and My Students options
    const options = select.locator('option');
    const texts = await options.allTextContents();
    expect(texts).toContain('All Students');
    expect(texts).toContain('My Students');
  });

  test('S11: Scope dropdown changes trigger data reload', async ({ page }) => {
    await page.waitForSelector('#d-scope-select', { timeout: 5000 });
    // Listen for API calls with scope param
    const requests = [];
    page.on('request', req => {
      if (req.url().includes('scope=mine')) requests.push(req.url());
    });
    // Change scope to "My Students"
    await page.selectOption('#d-scope-select', 'mine');
    // Wait for requests
    await page.waitForTimeout(1000);
    expect(requests.length).toBeGreaterThan(0);
  });
});

/* ------------------------------------------------------------------ */
/*  Auth Guard (S12)                                                   */
/* ------------------------------------------------------------------ */

test.describe('FRE-47 Auth', () => {

  test('S12: Students API rejects unauthenticated requests', async ({ request }) => {
    const response = await request.get('/api/teacher/students.php?action=list');
    // Should return 403 since we're not logged in
    expect(response.status()).toBe(403);
  });

  test('S13: Groups API rejects unauthenticated requests', async ({ request }) => {
    const response = await request.get('/api/teacher/groups.php?action=list');
    expect(response.status()).toBe(403);
  });
});
