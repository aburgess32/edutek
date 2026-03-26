// @ts-check
const { test, expect } = require('@playwright/test');

/**
 * Accessibility tests for EduPak Learning Hub
 *
 * WCAG 2.1 Level A — critical for low-literacy users in rural Africa.
 * These tests run across all 17 device profiles.
 */

/* ------------------------------------------------------------------ */
/*  Color contrast                                                     */
/* ------------------------------------------------------------------ */

test.describe('Color contrast', () => {
  test('text elements meet 4.5:1 contrast ratio', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });

    const lowContrast = await page.evaluate(() => {
      // Relative luminance calculation per WCAG 2.0
      function luminance(r, g, b) {
        const [rs, gs, bs] = [r, g, b].map((c) => {
          c = c / 255;
          return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
        });
        return 0.2126 * rs + 0.7152 * gs + 0.0722 * bs;
      }

      function contrastRatio(l1, l2) {
        const lighter = Math.max(l1, l2);
        const darker = Math.min(l1, l2);
        return (lighter + 0.05) / (darker + 0.05);
      }

      function parseColor(color) {
        const match = color.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/);
        if (!match) return null;
        return { r: parseInt(match[1]), g: parseInt(match[2]), b: parseInt(match[3]) };
      }

      const violations = [];
      const textElements = document.querySelectorAll('body *');

      textElements.forEach((el) => {
        if (el.children.length > 0 || !el.textContent?.trim()) return;

        const style = window.getComputedStyle(el);
        const fg = parseColor(style.color);
        const bg = parseColor(style.backgroundColor);

        if (!fg || !bg) return;

        // Skip transparent backgrounds
        const bgAlpha = style.backgroundColor.match(/rgba\(\d+,\s*\d+,\s*\d+,\s*([\d.]+)/);
        if (bgAlpha && parseFloat(bgAlpha[1]) < 0.1) return;

        const fgLum = luminance(fg.r, fg.g, fg.b);
        const bgLum = luminance(bg.r, bg.g, bg.b);
        const ratio = contrastRatio(fgLum, bgLum);

        const fontSize = parseFloat(style.fontSize);
        const isBold = parseInt(style.fontWeight) >= 700 || style.fontWeight === 'bold';
        // Large text: 18px+ or 14px+ bold — needs 3:1 instead of 4.5:1
        const minRatio = (fontSize >= 18 || (fontSize >= 14 && isBold)) ? 3 : 4.5;

        if (ratio < minRatio) {
          violations.push({
            text: el.textContent.slice(0, 30),
            tag: el.tagName,
            ratio: ratio.toFixed(2),
            required: minRatio,
            fg: style.color,
            bg: style.backgroundColor,
          });
        }
      });

      return violations.slice(0, 10); // Cap to avoid noise
    });

    if (lowContrast.length > 0) {
      console.log('Contrast violations:', lowContrast);
    }
    expect(lowContrast).toHaveLength(0);
  });
});

/* ------------------------------------------------------------------ */
/*  Image accessibility                                                */
/* ------------------------------------------------------------------ */

test.describe('Image accessibility', () => {
  test('all images have alt text or are decorative', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });

    const violations = await page.evaluate(() => {
      const images = document.querySelectorAll('img');
      const bad = [];
      images.forEach((img) => {
        const hasAlt = img.hasAttribute('alt');
        const isDecor = img.getAttribute('role') === 'presentation' ||
                        img.getAttribute('aria-hidden') === 'true';
        if (!hasAlt && !isDecor) {
          bad.push({ src: img.src?.split('/').pop() });
        }
      });
      return bad;
    });

    expect(violations).toHaveLength(0);
  });

  test('icons have aria-labels', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });

    const unlabeled = await page.evaluate(() => {
      // SVG icons, icon fonts, and image icons in interactive contexts
      const icons = document.querySelectorAll(
        'svg:not([aria-hidden="true"]), ' +
        'i[class*="icon"], ' +
        'span[class*="icon"], ' +
        'button > img, ' +
        'a > img'
      );
      const bad = [];
      icons.forEach((el) => {
        const hasLabel = el.getAttribute('aria-label') ||
                         el.getAttribute('aria-labelledby') ||
                         el.closest('[aria-label]') ||
                         el.closest('[aria-labelledby]') ||
                         el.getAttribute('title') ||
                         el.getAttribute('alt');
        if (!hasLabel) {
          bad.push({ tag: el.tagName, class: el.className?.toString?.()?.slice(0, 30) });
        }
      });
      return bad;
    });

    if (unlabeled.length > 0) {
      console.log('Unlabeled icons:', unlabeled);
    }
    expect(unlabeled).toHaveLength(0);
  });
});

/* ------------------------------------------------------------------ */
/*  Focus management                                                   */
/* ------------------------------------------------------------------ */

test.describe('Focus indicators', () => {
  test('interactive elements have visible focus styles', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });

    // Tab to first interactive element
    await page.keyboard.press('Tab');

    const focusVisible = await page.evaluate(() => {
      const el = document.activeElement;
      if (!el || el === document.body) return { hasElement: false };

      const style = window.getComputedStyle(el);
      const hasOutline = style.outlineStyle !== 'none' && style.outlineWidth !== '0px';
      const hasBoxShadow = style.boxShadow !== 'none';
      const hasBorder = style.borderColor !== style.backgroundColor;

      return {
        hasElement: true,
        tag: el.tagName,
        hasVisibleFocus: hasOutline || hasBoxShadow,
        outline: style.outline,
        boxShadow: style.boxShadow?.slice(0, 50),
      };
    });

    if (focusVisible.hasElement) {
      expect(focusVisible.hasVisibleFocus).toBe(true);
    }
  });

  test('tab order is logical', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });

    const tabOrder = [];
    for (let i = 0; i < 10; i++) {
      await page.keyboard.press('Tab');
      const info = await page.evaluate(() => {
        const el = document.activeElement;
        if (!el || el === document.body) return null;
        const rect = el.getBoundingClientRect();
        return { tag: el.tagName, y: Math.round(rect.top), x: Math.round(rect.left) };
      });
      if (info) tabOrder.push(info);
    }

    // Verify tab order generally follows visual top-to-bottom order
    // (allow some flexibility for horizontal nav)
    if (tabOrder.length >= 3) {
      let majorBacktrack = 0;
      for (let i = 1; i < tabOrder.length; i++) {
        if (tabOrder[i].y < tabOrder[i - 1].y - 100) {
          majorBacktrack++;
        }
      }
      // Allow at most 1 backtrack (e.g., nav bar wraps)
      expect(majorBacktrack).toBeLessThanOrEqual(1);
    }
  });
});

/* ------------------------------------------------------------------ */
/*  Text & readability                                                 */
/* ------------------------------------------------------------------ */

test.describe('Text accessibility', () => {
  test('no text smaller than 12px anywhere', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });

    const tinyText = await page.evaluate(() => {
      const all = document.querySelectorAll('body *');
      const violations = [];
      all.forEach((el) => {
        if (el.children.length > 0 || !el.textContent?.trim()) return;
        const fontSize = parseFloat(window.getComputedStyle(el).fontSize);
        if (fontSize < 12) {
          violations.push({
            text: el.textContent.slice(0, 20),
            size: fontSize.toFixed(1),
          });
        }
      });
      return violations;
    });

    expect(tinyText).toHaveLength(0);
  });

  test('page has lang attribute', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });

    const lang = await page.getAttribute('html', 'lang');
    expect(lang).toBeTruthy();
  });

  test('page has a title', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });

    const title = await page.title();
    expect(title.length).toBeGreaterThan(0);
  });
});

/* ------------------------------------------------------------------ */
/*  Semantic HTML                                                      */
/* ------------------------------------------------------------------ */

test.describe('Semantic structure', () => {
  test('has exactly one h1', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });

    const h1Count = await page.locator('h1').count();
    expect(h1Count).toBe(1);
  });

  test('heading levels do not skip', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });

    const headingSkips = await page.evaluate(() => {
      const headings = Array.from(document.querySelectorAll('h1, h2, h3, h4, h5, h6'));
      const skips = [];
      for (let i = 1; i < headings.length; i++) {
        const prev = parseInt(headings[i - 1].tagName[1]);
        const curr = parseInt(headings[i].tagName[1]);
        if (curr > prev + 1) {
          skips.push({
            from: headings[i - 1].tagName,
            to: headings[i].tagName,
            text: headings[i].textContent?.slice(0, 30),
          });
        }
      }
      return skips;
    });

    expect(headingSkips).toHaveLength(0);
  });

  test('main landmark exists', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });

    const hasMain = await page.locator('main, [role="main"]').count();
    expect(hasMain).toBeGreaterThanOrEqual(1);
  });
});
