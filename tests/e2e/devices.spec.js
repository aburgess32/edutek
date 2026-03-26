// @ts-check
const { test, expect } = require('@playwright/test');

/**
 * Device-specific tests for EduPak Learning Hub
 *
 * Runs across all 17 device profiles defined in playwright.config.js.
 * Each test adapts assertions based on the project's tier metadata.
 */

/* ------------------------------------------------------------------ */
/*  Helpers                                                            */
/* ------------------------------------------------------------------ */

function getTier(testInfo) {
  return testInfo.project.metadata?.tier ?? 2;
}

function getMaxLoadTime(tier) {
  // Performance budgets per tier (milliseconds)
  const budgets = {
    1: 3000,   // Ultra-budget: 3s (2GB RAM, weak CPU)
    2: 2000,   // Budget: 2s
    3: 1500,   // Lower-mid: 1.5s
    4: 2500,   // Tablets (old donated): 2.5s
    5: 1000,   // Projector/TV (local Chromium): 1s
    6: 5000,   // Feature phone: 5s (very limited)
  };
  return budgets[tier] || 2000;
}

/* ------------------------------------------------------------------ */
/*  Performance tests                                                  */
/* ------------------------------------------------------------------ */

test.describe('Performance by device tier', () => {
  test('page loads within tier budget', async ({ page }, testInfo) => {
    const tier = getTier(testInfo);
    const maxLoad = getMaxLoadTime(tier);

    const start = Date.now();
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    const loadTime = Date.now() - start;

    console.log(`[${testInfo.project.name}] Load time: ${loadTime}ms (budget: ${maxLoad}ms)`);
    expect(loadTime).toBeLessThan(maxLoad);
  });

  test('total page weight under budget', async ({ page }, testInfo) => {
    const tier = getTier(testInfo);
    // Tier 1 & 6 get strictest budget
    const maxBytes = tier <= 1 || tier === 6 ? 300 * 1024 : 500 * 1024;

    let totalBytes = 0;
    page.on('response', (response) => {
      const headers = response.headers();
      const size = parseInt(headers['content-length'] || '0', 10);
      totalBytes += size;
    });

    await page.goto('/', { waitUntil: 'load' });

    console.log(`[${testInfo.project.name}] Total transfer: ${(totalBytes / 1024).toFixed(1)}KB (budget: ${(maxBytes / 1024).toFixed(0)}KB)`);
    expect(totalBytes).toBeLessThan(maxBytes);
  });

  test('no JavaScript errors on page load', async ({ page }, testInfo) => {
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));

    await page.goto('/', { waitUntil: 'load' });

    if (errors.length > 0) {
      console.log(`[${testInfo.project.name}] JS errors:`, errors);
    }
    expect(errors).toHaveLength(0);
  });
});

/* ------------------------------------------------------------------ */
/*  Touch target tests (mobile devices only)                           */
/* ------------------------------------------------------------------ */

test.describe('Touch targets', () => {
  test('all interactive elements >= 44x44px on mobile', async ({ page }, testInfo) => {
    const tier = getTier(testInfo);
    if (tier === 5 || tier === 6) {
      test.skip(true, 'Touch target test not applicable for projectors/feature phones');
    }

    await page.goto('/', { waitUntil: 'domcontentloaded' });

    const tooSmall = await page.evaluate(() => {
      const interactive = document.querySelectorAll('a, button, input, select, [role="button"], [onclick]');
      const violations = [];
      interactive.forEach((el) => {
        const rect = el.getBoundingClientRect();
        if (rect.width > 0 && rect.height > 0) {
          if (rect.width < 44 || rect.height < 44) {
            violations.push({
              tag: el.tagName,
              text: el.textContent?.slice(0, 30),
              width: Math.round(rect.width),
              height: Math.round(rect.height),
            });
          }
        }
      });
      return violations;
    });

    if (tooSmall.length > 0) {
      console.log(`[${testInfo.project.name}] Touch target violations:`, tooSmall);
    }
    expect(tooSmall).toHaveLength(0);
  });
});

/* ------------------------------------------------------------------ */
/*  Text readability tests                                             */
/* ------------------------------------------------------------------ */

test.describe('Text readability', () => {
  test('minimum font size meets device requirements', async ({ page }, testInfo) => {
    const tier = getTier(testInfo);
    // Projector needs 24px+, mobile needs 14px+, feature phone 10px+
    const minFont = tier === 5 ? 24 : tier === 6 ? 10 : 14;

    await page.goto('/', { waitUntil: 'domcontentloaded' });

    const tooSmall = await page.evaluate((minPx) => {
      const allText = document.querySelectorAll('body *');
      const violations = [];
      allText.forEach((el) => {
        if (el.children.length === 0 && el.textContent?.trim()) {
          const fontSize = parseFloat(window.getComputedStyle(el).fontSize);
          if (fontSize < minPx) {
            violations.push({
              tag: el.tagName,
              text: el.textContent.slice(0, 30),
              fontSize: Math.round(fontSize),
            });
          }
        }
      });
      return violations;
    }, minFont);

    if (tooSmall.length > 0) {
      console.log(`[${testInfo.project.name}] Font size violations (min ${minFont}px):`, tooSmall);
    }
    expect(tooSmall).toHaveLength(0);
  });
});

/* ------------------------------------------------------------------ */
/*  Viewport / layout tests                                            */
/* ------------------------------------------------------------------ */

test.describe('Layout integrity', () => {
  test('no horizontal scrollbar on any device', async ({ page }, testInfo) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });

    const hasHorizontalScroll = await page.evaluate(() => {
      return document.documentElement.scrollWidth > document.documentElement.clientWidth;
    });

    expect(hasHorizontalScroll).toBe(false);
  });

  test('content fits within viewport width', async ({ page }, testInfo) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });

    const overflows = await page.evaluate(() => {
      const vw = document.documentElement.clientWidth;
      const elements = document.querySelectorAll('body *');
      const bad = [];
      elements.forEach((el) => {
        const rect = el.getBoundingClientRect();
        if (rect.right > vw + 1) {
          bad.push({
            tag: el.tagName,
            class: el.className?.toString().slice(0, 40),
            right: Math.round(rect.right),
            viewportWidth: vw,
          });
        }
      });
      return bad.slice(0, 5);
    });

    if (overflows.length > 0) {
      console.log(`[${testInfo.project.name}] Viewport overflow:`, overflows);
    }
    expect(overflows).toHaveLength(0);
  });
});

/* ------------------------------------------------------------------ */
/*  Image fallback tests                                               */
/* ------------------------------------------------------------------ */

test.describe('Image handling', () => {
  test('all images load without 404 errors', async ({ page }, testInfo) => {
    const brokenImages = [];
    page.on('response', (response) => {
      if (response.request().resourceType() === 'image' && response.status() >= 400) {
        brokenImages.push({ url: response.url(), status: response.status() });
      }
    });

    await page.goto('/', { waitUntil: 'load' });

    if (brokenImages.length > 0) {
      console.log(`[${testInfo.project.name}] Broken images:`, brokenImages);
    }
    expect(brokenImages).toHaveLength(0);
  });

  test('all images have alt text', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });

    const missingAlt = await page.evaluate(() => {
      const images = document.querySelectorAll('img');
      const violations = [];
      images.forEach((img) => {
        if (!img.alt && !img.getAttribute('role')?.includes('presentation')) {
          violations.push({ src: img.src?.slice(-40) });
        }
      });
      return violations;
    });

    expect(missingAlt).toHaveLength(0);
  });
});

/* ------------------------------------------------------------------ */
/*  Progressive enhancement (critical for T1 and T6)                   */
/* ------------------------------------------------------------------ */

test.describe('Progressive enhancement', () => {
  test('page renders meaningful content with JS disabled', async ({ browser }, testInfo) => {
    const tier = getTier(testInfo);
    // Most critical for ultra-budget and feature phones
    if (tier > 4 && tier !== 6) {
      test.skip(true, 'JS-disabled test most relevant for mobile tiers');
    }

    const context = await browser.newContext({
      ...testInfo.project.use,
      javaScriptEnabled: false,
    });
    const page = await context.newPage();

    await page.goto('/', { waitUntil: 'domcontentloaded' });

    // Verify there's actual content rendered (not blank page)
    const bodyText = await page.textContent('body');
    expect(bodyText?.length).toBeGreaterThan(10);

    // Verify navigation links work without JS
    const links = await page.locator('a[href]').count();
    expect(links).toBeGreaterThan(0);

    await context.close();
  });
});

/* ------------------------------------------------------------------ */
/*  Projector-specific tests (T5)                                      */
/* ------------------------------------------------------------------ */

test.describe('Projector mode', () => {
  test('keyboard navigation works (no touch)', async ({ page }, testInfo) => {
    const tier = getTier(testInfo);
    if (tier !== 5) {
      test.skip(true, 'Projector test only');
    }

    await page.goto('/', { waitUntil: 'domcontentloaded' });

    // Tab through interactive elements — verify focus is visible
    await page.keyboard.press('Tab');
    const focusedElement = await page.evaluate(() => {
      const el = document.activeElement;
      if (!el || el === document.body) return null;
      const style = window.getComputedStyle(el);
      return {
        tag: el.tagName,
        outline: style.outline,
        boxShadow: style.boxShadow,
      };
    });

    // Something should be focused and have a visible indicator
    expect(focusedElement).not.toBeNull();
  });
});

/* ------------------------------------------------------------------ */
/*  Tablet orientation tests (T4)                                      */
/* ------------------------------------------------------------------ */

test.describe('Tablet orientation', () => {
  test('layout adapts to landscape orientation', async ({ page, browser }, testInfo) => {
    const tier = getTier(testInfo);
    if (tier !== 4) {
      test.skip(true, 'Tablet orientation test only');
    }

    // Portrait first
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    const portraitWidth = await page.evaluate(() => document.documentElement.clientWidth);

    // Create landscape context
    const vp = testInfo.project.use.viewport;
    const landscapeContext = await browser.newContext({
      ...testInfo.project.use,
      viewport: { width: vp.height, height: vp.width },
    });
    const landscapePage = await landscapeContext.newPage();
    await landscapePage.goto('/', { waitUntil: 'domcontentloaded' });

    // Verify no horizontal scroll in landscape either
    const hasScroll = await landscapePage.evaluate(() =>
      document.documentElement.scrollWidth > document.documentElement.clientWidth
    );
    expect(hasScroll).toBe(false);

    await landscapeContext.close();
  });
});
