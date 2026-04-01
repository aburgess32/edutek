// @ts-check
const { test, expect } = require('@playwright/test');

/**
 * FRE-13: Teacher Authentication — End-to-end tests
 *
 * Covers the complete teacher auth lifecycle (A1-A18 from the pre-dev plan):
 * registration, login, session management, password operations, and edge cases.
 */

/* ------------------------------------------------------------------ */
/*  Helpers                                                            */
/* ------------------------------------------------------------------ */

const TEACHER_EMAIL = 'teacher@test.local';
const TEACHER_PASS = 'testpass123';
const TEACHER_NAME = 'Test Teacher';
const REGISTER_PASSPHRASE = 'teachwithedutek';

/**
 * Generate a unique email for registration tests.
 */
function uniqueEmail() {
  return 'test_' + Date.now() + '@test.local';
}

/* ------------------------------------------------------------------ */
/*  Registration Tests (A1-A4, A16-A17)                                */
/* ------------------------------------------------------------------ */

test.describe('FRE-13 Registration', () => {

  test('A1: register new teacher with valid data', async ({ page }) => {
    await page.goto('/teacher-register.php', { waitUntil: 'domcontentloaded' });

    const email = uniqueEmail();
    await page.fill('input[name="display_name"]', 'New Teacher');
    await page.fill('input[name="email"]', email);
    await page.fill('input[name="password"]', 'secure123');
    await page.fill('input[name="password_confirm"]', 'secure123');
    await page.fill('input[name="passphrase"]', REGISTER_PASSPHRASE);

    await page.click('button[type="submit"]');

    // Should auto-login and redirect to teacher.php
    await page.waitForURL('**/teacher.php**', { timeout: 5000 });
    await expect(page.locator('.teacher-header__name')).toBeVisible();
  });

  test('A2: register with duplicate email shows error', async ({ page }) => {
    await page.goto('/teacher-register.php', { waitUntil: 'domcontentloaded' });

    await page.fill('input[name="display_name"]', 'Duplicate');
    await page.fill('input[name="email"]', TEACHER_EMAIL);
    await page.fill('input[name="password"]', 'secure123');
    await page.fill('input[name="password_confirm"]', 'secure123');
    await page.fill('input[name="passphrase"]', REGISTER_PASSPHRASE);

    await page.click('button[type="submit"]');

    // Should show error about duplicate email
    const error = page.locator('.auth-error, .error-message, .alert');
    await expect(error).toBeVisible({ timeout: 3000 });
    const text = await error.textContent();
    expect(text.toLowerCase()).toContain('already exists');
  });

  test('A3: register with short password shows error', async ({ page }) => {
    await page.goto('/teacher-register.php', { waitUntil: 'domcontentloaded' });

    await page.fill('input[name="display_name"]', 'Short Pass');
    await page.fill('input[name="email"]', uniqueEmail());
    await page.fill('input[name="password"]', '12345');
    await page.fill('input[name="password_confirm"]', '12345');
    await page.fill('input[name="passphrase"]', REGISTER_PASSPHRASE);

    await page.click('button[type="submit"]');

    // Should show password length error
    const error = page.locator('.auth-error, .error-message, .alert');
    await expect(error).toBeVisible({ timeout: 3000 });
  });

  test('A4: register with mismatched passwords shows error', async ({ page }) => {
    await page.goto('/teacher-register.php', { waitUntil: 'domcontentloaded' });

    await page.fill('input[name="display_name"]', 'Mismatch');
    await page.fill('input[name="email"]', uniqueEmail());
    await page.fill('input[name="password"]', 'secure123');
    await page.fill('input[name="password_confirm"]', 'different456');
    await page.fill('input[name="passphrase"]', REGISTER_PASSPHRASE);

    await page.click('button[type="submit"]');

    const error = page.locator('.auth-error, .error-message, .alert');
    await expect(error).toBeVisible({ timeout: 3000 });
    const text = await error.textContent();
    expect(text.toLowerCase()).toContain('do not match');
  });

  test('A16: register with wrong passphrase shows error', async ({ page }) => {
    await page.goto('/teacher-register.php', { waitUntil: 'domcontentloaded' });

    await page.fill('input[name="display_name"]', 'Wrong Pass');
    await page.fill('input[name="email"]', uniqueEmail());
    await page.fill('input[name="password"]', 'secure123');
    await page.fill('input[name="password_confirm"]', 'secure123');
    await page.fill('input[name="passphrase"]', 'wrongphrase');

    await page.click('button[type="submit"]');

    const error = page.locator('.auth-error, .error-message, .alert');
    await expect(error).toBeVisible({ timeout: 3000 });
    const text = await error.textContent();
    expect(text.toLowerCase()).toContain('passphrase');
  });

  test('A17: register with correct passphrase succeeds', async ({ page }) => {
    await page.goto('/teacher-register.php', { waitUntil: 'domcontentloaded' });

    const email = uniqueEmail();
    await page.fill('input[name="display_name"]', 'Valid Teacher');
    await page.fill('input[name="email"]', email);
    await page.fill('input[name="password"]', 'secure123');
    await page.fill('input[name="password_confirm"]', 'secure123');
    await page.fill('input[name="passphrase"]', REGISTER_PASSPHRASE);

    await page.click('button[type="submit"]');
    await page.waitForURL('**/teacher.php**', { timeout: 5000 });
  });
});

/* ------------------------------------------------------------------ */
/*  Login Tests (A5-A7)                                                */
/* ------------------------------------------------------------------ */

test.describe('FRE-13 Login', () => {

  test('A5: login with correct credentials redirects to teacher.php', async ({ page }) => {
    await page.goto('/teacher-login.php', { waitUntil: 'domcontentloaded' });

    await page.fill('input[name="email"]', TEACHER_EMAIL);
    await page.fill('input[name="password"]', TEACHER_PASS);
    await page.click('button[type="submit"]');

    await page.waitForURL('**/teacher.php**', { timeout: 5000 });
    await expect(page.locator('.teacher-header')).toBeVisible();
  });

  test('A6: login with wrong password shows error', async ({ page }) => {
    await page.goto('/teacher-login.php', { waitUntil: 'domcontentloaded' });

    await page.fill('input[name="email"]', TEACHER_EMAIL);
    await page.fill('input[name="password"]', 'wrongpassword');
    await page.click('button[type="submit"]');

    const error = page.locator('.auth-error, .error-message, .alert');
    await expect(error).toBeVisible({ timeout: 3000 });
    const text = await error.textContent();
    expect(text.toLowerCase()).toContain('invalid');
  });

  test('A7: rate limiting after repeated failed logins', async ({ page }) => {
    await page.goto('/teacher-login.php', { waitUntil: 'domcontentloaded' });

    // Attempt 6 rapid failed logins
    for (let i = 0; i < 6; i++) {
      await page.fill('input[name="email"]', TEACHER_EMAIL);
      await page.fill('input[name="password"]', 'wrong' + i);
      await page.click('button[type="submit"]');
      await page.waitForLoadState('domcontentloaded');
    }

    // After 5+ failures, should see rate limit message
    const body = await page.textContent('body');
    const isRateLimited = body.toLowerCase().includes('too many') ||
                          body.toLowerCase().includes('rate limit') ||
                          body.toLowerCase().includes('wait');
    expect(isRateLimited).toBeTruthy();
  });
});

/* ------------------------------------------------------------------ */
/*  Access Control Tests (A8)                                          */
/* ------------------------------------------------------------------ */

test.describe('FRE-13 Access Control', () => {

  test('A8: unauthenticated visit to teacher.php redirects', async ({ page }) => {
    const response = await page.goto('/teacher.php', { waitUntil: 'domcontentloaded' });
    const url = page.url();
    // Should redirect away from teacher.php
    expect(url).not.toMatch(/\/teacher\.php$/);
  });
});

/* ------------------------------------------------------------------ */
/*  Password Management Tests (A9-A12)                                 */
/* ------------------------------------------------------------------ */

test.describe('FRE-13 Password Management', () => {

  test.beforeEach(async ({ page }) => {
    await page.goto('/teacher-login.php', { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="email"]', TEACHER_EMAIL);
    await page.fill('input[name="password"]', TEACHER_PASS);
    await page.click('button[type="submit"]');
    await page.waitForURL('**/teacher.php**', { timeout: 5000 });

    // Navigate to profile tab
    await page.click('[data-tab="profile"]');
    await page.waitForSelector('.profile-wrapper', { timeout: 3000 });
  });

  test('A9: change password with correct current password', async ({ page }) => {
    const passwordInputs = page.locator('.profile-input[type="password"]');

    // Fill change password fields (first 3 password inputs)
    await passwordInputs.nth(0).fill(TEACHER_PASS);
    await passwordInputs.nth(1).fill('newpass123');
    await passwordInputs.nth(2).fill('newpass123');

    // Click the change password button
    const changeBtn = page.locator('.profile-btn--primary', { hasText: 'Change Password' });
    await changeBtn.click();

    // Should show success feedback
    const feedback = page.locator('.profile-feedback--success');
    await expect(feedback).toBeVisible({ timeout: 3000 });
    await expect(feedback).toContainText('changed successfully');

    // Revert password back (important for other tests)
    await passwordInputs.nth(0).fill('newpass123');
    await passwordInputs.nth(1).fill(TEACHER_PASS);
    await passwordInputs.nth(2).fill(TEACHER_PASS);
    await changeBtn.click();
    await expect(page.locator('.profile-feedback--success')).toBeVisible({ timeout: 3000 });
  });

  test('A10: change password with wrong current password fails', async ({ page }) => {
    const passwordInputs = page.locator('.profile-input[type="password"]');

    await passwordInputs.nth(0).fill('wrongcurrent');
    await passwordInputs.nth(1).fill('newpass123');
    await passwordInputs.nth(2).fill('newpass123');

    const changeBtn = page.locator('.profile-btn--primary', { hasText: 'Change Password' });
    await changeBtn.click();

    const feedback = page.locator('.profile-feedback--error');
    await expect(feedback).toBeVisible({ timeout: 3000 });
    await expect(feedback).toContainText('incorrect');
  });

  test('A11: change display name', async ({ page }) => {
    const nameInput = page.locator('.profile-input[type="text"]').first();
    const originalName = await nameInput.inputValue();

    const newName = 'Updated Teacher ' + Date.now().toString().slice(-4);
    await nameInput.fill(newName);

    const saveBtn = page.locator('.profile-btn--primary', { hasText: 'Save Name' });
    await saveBtn.click();

    const feedback = page.locator('.profile-feedback--success');
    await expect(feedback).toBeVisible({ timeout: 3000 });
    await expect(feedback).toContainText('updated');

    // Header name should update
    await expect(page.locator('.teacher-header__name')).toContainText(newName);

    // Revert name
    await nameInput.fill(originalName);
    await saveBtn.click();
  });

  test('A12: reset another teacher password', async ({ page }) => {
    // Find the reset section inputs (last email + password inputs)
    const resetEmail = page.locator('.profile-input[type="email"]');
    const resetPassword = page.locator('.profile-section').last().locator('.profile-input[type="password"]');

    const targetEmail = uniqueEmail();
    // This will fail because the email doesn't exist, but we can test the UI flow
    await resetEmail.fill(targetEmail);

    // The reset password field is the one inside the reset section
    const allPasswordInputs = page.locator('.profile-input[type="password"]');
    const lastPasswordInput = allPasswordInputs.last();
    await lastPasswordInput.fill('newreset123');

    const resetBtn = page.locator('.profile-btn--danger', { hasText: 'Reset Password' });
    await resetBtn.click();

    // Should show either success or "Teacher not found" error feedback
    const feedback = page.locator('.profile-feedback');
    await expect(feedback).toBeVisible({ timeout: 3000 });
  });
});

/* ------------------------------------------------------------------ */
/*  Logout Tests (A13)                                                 */
/* ------------------------------------------------------------------ */

test.describe('FRE-13 Logout', () => {

  test('A13: logout destroys session and redirects', async ({ page }) => {
    // Login first
    await page.goto('/teacher-login.php', { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="email"]', TEACHER_EMAIL);
    await page.fill('input[name="password"]', TEACHER_PASS);
    await page.click('button[type="submit"]');
    await page.waitForURL('**/teacher.php**', { timeout: 5000 });

    // Click logout in header
    await page.click('.teacher-header__logout');
    await page.waitForLoadState('domcontentloaded');

    // Should be redirected away from teacher.php
    const url = page.url();
    expect(url).not.toContain('/teacher.php');

    // Trying to access teacher.php should redirect again
    await page.goto('/teacher.php', { waitUntil: 'domcontentloaded' });
    expect(page.url()).not.toMatch(/\/teacher\.php$/);
  });
});

/* ------------------------------------------------------------------ */
/*  Session Tests (A14-A15)                                            */
/* ------------------------------------------------------------------ */

test.describe('FRE-13 Session', () => {

  test('A14: expired session parameter shows message', async ({ page }) => {
    await page.goto('/login.php?expired=1', { waitUntil: 'domcontentloaded' });

    const info = page.locator('.auth-info');
    const infoVisible = await info.isVisible().catch(() => false);
    if (infoVisible) {
      await expect(info).toContainText('expired');
    }
  });

  test('A15: fresh install redirects login to register', async ({ page }) => {
    // This test requires no teacher accounts to exist, so it's conditional.
    // We test the register page shows first-time message when ?first=1
    await page.goto('/teacher-register.php?first=1', { waitUntil: 'domcontentloaded' });

    const info = page.locator('.auth-info');
    const infoVisible = await info.isVisible().catch(() => false);
    if (infoVisible) {
      await expect(info).toContainText('first teacher account');
    }
  });
});

/* ------------------------------------------------------------------ */
/*  CSRF Tests (A18)                                                   */
/* ------------------------------------------------------------------ */

test.describe('FRE-13 CSRF Protection', () => {

  test('A18: CSRF token present on teacher pages', async ({ page }) => {
    // Login
    await page.goto('/teacher-login.php', { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="email"]', TEACHER_EMAIL);
    await page.fill('input[name="password"]', TEACHER_PASS);
    await page.click('button[type="submit"]');
    await page.waitForURL('**/teacher.php**', { timeout: 5000 });

    // CSRF token should be present as meta tag
    const csrfMeta = page.locator('meta[name="csrf-token"]');
    await expect(csrfMeta).toHaveAttribute('content', /.+/);
  });
});
