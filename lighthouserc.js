/**
 * EduPak — Lighthouse CI Configuration
 *
 * Runs automated performance, accessibility, and best-practices audits
 * against the local XAMPP development server.
 *
 * Usage:
 *   npx lhci autorun --config=lighthouserc.js
 *   make perf
 *
 * Reports are written to dist/lighthouse/ as both HTML and JSON.
 *
 * @see https://github.com/GoogleChrome/lighthouse-ci/blob/main/docs/configuration.md
 */

'use strict';

module.exports = {
  // ──────────────────────────────────────────────────────────
  // ci: Lighthouse CI runner options
  // ──────────────────────────────────────────────────────────
  ci: {
    collect: {
      // URL(s) to audit — local XAMPP / Docker Compose default port
      url: [
        'http://localhost:8080',
        'http://localhost:8080/index.php',
      ],

      // Number of Lighthouse runs per URL (average reduces variance)
      numberOfRuns: 3,

      // Chromium settings for low-spec offline device simulation
      settings: {
        // Simulate a mid-range mobile device (appropriate for target hardware)
        formFactor: 'desktop',
        throttlingMethod: 'simulate',

        // Network: simulated LAN (offline device, fast local network)
        throttling: {
          rttMs: 10,
          throughputKbps: 100000,
          cpuSlowdownMultiplier: 2,   // simulate 2× CPU slowdown for low-spec HW
        },

        // Skip PWA audits — offline XAMPP is not a PWA target
        onlyCategories: [
          'performance',
          'accessibility',
          'best-practices',
        ],

        // Chrome flags for headless audit
        chromeFlags: '--no-sandbox --headless --disable-gpu',
      },
    },

    // ────────────────────────────────────────────────────────
    // assert: Score budgets and resource size limits
    // ────────────────────────────────────────────────────────
    assert: {
      preset: 'lighthouse:no-pwa',

      assertions: {
        // ── Category score thresholds ──────────────────────
        'categories:performance':    ['warn',  { minScore: 0.80 }],
        'categories:accessibility':  ['error', { minScore: 0.90 }],
        'categories:best-practices': ['warn',  { minScore: 0.80 }],

        // ── Core Web Vitals / timing ───────────────────────
        'first-contentful-paint':    ['warn',  { maxNumericValue: 2000 }],  // < 2s
        'interactive':               ['warn',  { maxNumericValue: 5000 }],  // < 5s TTI
        'largest-contentful-paint':  ['warn',  { maxNumericValue: 4000 }],  // < 4s
        'total-blocking-time':       ['warn',  { maxNumericValue: 300  }],  // < 300ms
        'cumulative-layout-shift':   ['warn',  { maxNumericValue: 0.1  }],  // < 0.1 CLS

        // ── Resource size budgets ──────────────────────────
        // Max JS bundle: 50 KB (transferSize)
        'resource-summary:script:size': ['warn', { maxNumericValue: 51200 }],

        // Max CSS: 20 KB
        'resource-summary:stylesheet:size': ['warn', { maxNumericValue: 20480 }],

        // ── Accessibility ──────────────────────────────────
        // Critical a11y rules — fail the build on violations
        'color-contrast':            ['error', { minScore: 1 }],
        'image-alt':                 ['error', { minScore: 1 }],
        'label':                     ['error', { minScore: 1 }],
        'document-title':            ['error', { minScore: 1 }],
        'html-has-lang':             ['error', { minScore: 1 }],

        // ── Best practices ─────────────────────────────────
        'uses-https':                'off',  // Disabled — offline HTTP is intentional
        'is-on-https':               'off',
        'no-vulnerable-libraries':   ['warn', { minScore: 1 }],
        'errors-in-console':         ['warn', { minScore: 1 }],

        // ── SEO (informational only — not scored) ─────────
        'meta-description':          'off',
      },
    },

    // ────────────────────────────────────────────────────────
    // upload: Report output destination
    // ────────────────────────────────────────────────────────
    upload: {
      // Output HTML + JSON reports to dist/lighthouse/
      // No external LHCI server required (offline-safe)
      target: 'filesystem',
      outputDir: './dist/lighthouse',
      reportFilenamePattern: '%%PATHNAME%%-%%DATETIME%%-report.%%EXTENSION%%',
    },
  },
};
