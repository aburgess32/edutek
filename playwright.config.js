// @ts-check

/**
 * Playwright Configuration — EduPak Learning Hub
 *
 * 17 device profiles modelling the real African smartphone/tablet/projector
 * market (2025-2026 data from StatCounter, Omdia, GSMArena).
 *
 * Tiers:
 *   T1  Ultra-Budget  (<$50)   — Itel A60, Itel A70, Tecno Pop 8
 *   T2  Budget        ($50-100) — Samsung A06, Infinix Smart 9, Xiaomi Redmi A5, Tecno Spark 10
 *   T3  Lower-Mid     ($100-200)— Samsung A16 5G, Tecno Spark 20, Samsung A05
 *   T4  Tablets       (donated)  — Generic 7" and 10" Android tablets
 *   T5  Projector/TV             — 720p, 1080p projector, cheap TV
 *   T6  Feature Phone            — KaiOS 240×320
 *
 * Network profiles (local WiFi, no internet):
 *   local-wifi-fast      : 50 Mbps / 20 Mbps /   5 ms  — same room, few devices
 *   local-wifi-weak      :  5 Mbps /  2 Mbps /  50 ms  — far from EduPak / walls
 *   local-wifi-saturated :  1 Mbps / 500 Kbps / 200 ms — 60 devices sharing
 */

const { defineConfig } = require('@playwright/test');

/* ------------------------------------------------------------------ */
/*  Network throttle presets (used by test helpers via project.metadata) */
/* ------------------------------------------------------------------ */
const NETWORK = {
  'local-wifi-fast':      { downloadThroughput: (50 * 1024 * 1024) / 8, uploadThroughput: (20 * 1024 * 1024) / 8, latency: 5 },
  'local-wifi-weak':      { downloadThroughput: (5 * 1024 * 1024) / 8,  uploadThroughput: (2 * 1024 * 1024) / 8,  latency: 50 },
  'local-wifi-saturated': { downloadThroughput: (1 * 1024 * 1024) / 8,  uploadThroughput: (500 * 1024) / 8,       latency: 200 },
};

/* ------------------------------------------------------------------ */
/*  Device definitions                                                 */
/* ------------------------------------------------------------------ */

// TIER 1 — Ultra-Budget (<$50) — THE CRITICAL TIER
// Transsion (Itel/Tecno/Infinix) dominates with 48% Africa market share
const TIER_1 = [
  {
    name: 't1-itel-a60',
    use: {
      viewport: { width: 360, height: 806 },          // 720x1612 at 2x DPR
      deviceScaleFactor: 2,
      isMobile: true,
      hasTouch: true,
      userAgent: 'Mozilla/5.0 (Linux; Android 11; itel A60) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/95.0.4638.74 Mobile Safari/537.36',
    },
    metadata: { tier: 1, brand: 'Itel', model: 'A60', ram: '2GB', cpu: 'Spreadtrum SC9832E', os: 'Android 11', screen: '6.6" 720x1612 60Hz', price: '<$40', network: 'local-wifi-saturated', note: 'BASELINE DEVICE — if it works here, it works everywhere. 2GB RAM = ~300-400MB for browser.' },
  },
  {
    name: 't1-itel-a70',
    use: {
      viewport: { width: 360, height: 806 },
      deviceScaleFactor: 2,
      isMobile: true,
      hasTouch: true,
      userAgent: 'Mozilla/5.0 (Linux; Android 13; itel A70) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/112.0.5615.135 Mobile Safari/537.36',
    },
    metadata: { tier: 1, brand: 'Itel', model: 'A70', ram: '4GB', cpu: 'Unisoc T603', os: 'Android 13 Go', screen: '6.6" 720x1612 120Hz', price: '<$50' },
  },
  {
    name: 't1-tecno-pop8',
    use: {
      viewport: { width: 360, height: 806 },
      deviceScaleFactor: 2,
      isMobile: true,
      hasTouch: true,
      userAgent: 'Mozilla/5.0 (Linux; Android 13; TECNO BG6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/112.0.5615.135 Mobile Safari/537.36',
    },
    metadata: { tier: 1, brand: 'Tecno', model: 'Pop 8', ram: '4GB', cpu: 'Unisoc T606', os: 'Android 13 Go', screen: '6.6" 720x1612 90Hz', price: '<$50' },
  },
];

// TIER 2 — Budget ($50-$100) — High volume segment
const TIER_2 = [
  {
    name: 't2-samsung-a06',
    use: {
      viewport: { width: 360, height: 800 },           // 720x1600 at 2x
      deviceScaleFactor: 2,
      isMobile: true,
      hasTouch: true,
      userAgent: 'Mozilla/5.0 (Linux; Android 14; SM-A065F) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.6099.144 Mobile Safari/537.36',
    },
    metadata: { tier: 2, brand: 'Samsung', model: 'Galaxy A06', ram: '4-6GB', cpu: 'Helio G85', os: 'Android 14', screen: '6.7" 720x1600 60Hz', price: '$60-80' },
  },
  {
    name: 't2-infinix-smart9',
    use: {
      viewport: { width: 360, height: 800 },
      deviceScaleFactor: 2,
      isMobile: true,
      hasTouch: true,
      userAgent: 'Mozilla/5.0 (Linux; Android 14; Infinix X6532) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.6099.144 Mobile Safari/537.36',
    },
    metadata: { tier: 2, brand: 'Infinix', model: 'Smart 9', ram: '4GB', cpu: 'Helio G81', os: 'Android 14', screen: '6.7" 720x1600 120Hz', price: '$60-70' },
  },
  {
    name: 't2-xiaomi-redmi-a5',
    use: {
      viewport: { width: 360, height: 820 },           // 720x1640 at 2x
      deviceScaleFactor: 2,
      isMobile: true,
      hasTouch: true,
      userAgent: 'Mozilla/5.0 (Linux; Android 15; Redmi A5) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.6367.82 Mobile Safari/537.36',
    },
    metadata: { tier: 2, brand: 'Xiaomi', model: 'Redmi A5', ram: '4GB', cpu: 'Unisoc T615', os: 'Android 15', screen: '6.88" 720x1640 120Hz', price: '$70-90' },
  },
  {
    name: 't2-tecno-spark10',
    use: {
      viewport: { width: 360, height: 806 },
      deviceScaleFactor: 2,
      isMobile: true,
      hasTouch: true,
      userAgent: 'Mozilla/5.0 (Linux; Android 13; TECNO KI5q) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/112.0.5615.135 Mobile Safari/537.36',
    },
    metadata: { tier: 2, brand: 'Tecno', model: 'Spark 10', ram: '8GB', cpu: 'Helio G37', os: 'Android 13', screen: '6.6" 720x1612 90Hz', price: '$80-100' },
  },
];

// TIER 3 — Lower-Mid ($100-$200)
const TIER_3 = [
  {
    name: 't3-samsung-a16-5g',
    use: {
      viewport: { width: 412, height: 892 },           // 1080x2340 at ~2.625x (use 2.5)
      deviceScaleFactor: 2.625,
      isMobile: true,
      hasTouch: true,
      userAgent: 'Mozilla/5.0 (Linux; Android 14; SM-A166B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.6099.144 Mobile Safari/537.36',
    },
    metadata: { tier: 3, brand: 'Samsung', model: 'Galaxy A16 5G', ram: '4-8GB', cpu: 'Dimensity 6300', os: 'Android 14', screen: '6.7" 1080x2340 AMOLED 90Hz', price: '$130-170' },
  },
  {
    name: 't3-tecno-spark20',
    use: {
      viewport: { width: 360, height: 806 },
      deviceScaleFactor: 2,
      isMobile: true,
      hasTouch: true,
      userAgent: 'Mozilla/5.0 (Linux; Android 13; TECNO KJ6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/112.0.5615.135 Mobile Safari/537.36',
    },
    metadata: { tier: 3, brand: 'Tecno', model: 'Spark 20', ram: '8GB', cpu: 'Helio G85', os: 'Android 13', screen: '6.56" 720x1612 90Hz', price: '$100-130' },
  },
  {
    name: 't3-samsung-a05',
    use: {
      viewport: { width: 360, height: 800 },
      deviceScaleFactor: 2,
      isMobile: true,
      hasTouch: true,
      userAgent: 'Mozilla/5.0 (Linux; Android 13; SM-A055F) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/112.0.5615.135 Mobile Safari/537.36',
    },
    metadata: { tier: 3, brand: 'Samsung', model: 'Galaxy A05', ram: '4GB', cpu: 'Helio G85', os: 'Android 13', screen: '6.7" 720x1600 60Hz', price: '$100-120' },
  },
];

// TIER 4 — Tablets (school donations, shared devices)
const TIER_4 = [
  {
    name: 't4-tablet-7inch',
    use: {
      viewport: { width: 600, height: 1024 },
      deviceScaleFactor: 1,
      isMobile: true,
      hasTouch: true,
      userAgent: 'Mozilla/5.0 (Linux; Android 10; Generic Tablet) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/87.0.4280.141 Safari/537.36',
    },
    metadata: { tier: 4, brand: 'Generic', model: '7" Donated Tablet', ram: '1-2GB', cpu: 'Various', os: 'Android 8-10', screen: '7" 600x1024', note: 'Common donated school tablets. Very old Android + Chrome.' },
  },
  {
    name: 't4-tablet-10inch',
    use: {
      viewport: { width: 800, height: 1280 },
      deviceScaleFactor: 1,
      isMobile: true,
      hasTouch: true,
      userAgent: 'Mozilla/5.0 (Linux; Android 12; Lenovo TB-X306X) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/96.0.4664.104 Safari/537.36',
    },
    metadata: { tier: 4, brand: 'Generic', model: '10" Classroom Tablet', ram: '2-4GB', cpu: 'Various', os: 'Android 11-13', screen: '10.1" 800x1280' },
  },
];

// TIER 5 — Projectors & Shared Screens
const TIER_5 = [
  {
    name: 't5-projector-720p',
    use: {
      viewport: { width: 1280, height: 720 },
      deviceScaleFactor: 1,
      isMobile: false,
      hasTouch: false,
      userAgent: 'Mozilla/5.0 (X11; Linux armv7l) AppleWebKit/537.36 (KHTML, like Gecko) Chromium/92.0.4515.159 Safari/537.36',
    },
    metadata: { tier: 5, brand: 'Generic', model: 'Projector 720p', note: 'Keyboard/mouse only. Large text + high contrast required.' },
  },
  {
    name: 't5-projector-1080p',
    use: {
      viewport: { width: 1920, height: 1080 },
      deviceScaleFactor: 1,
      isMobile: false,
      hasTouch: false,
      userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    },
    metadata: { tier: 5, brand: 'Generic', model: 'Projector 1080p', note: 'Best-case projector scenario.' },
  },
  {
    name: 't5-tv-768p',
    use: {
      viewport: { width: 1366, height: 768 },
      deviceScaleFactor: 1,
      isMobile: false,
      hasTouch: false,
      userAgent: 'Mozilla/5.0 (SMART-TV; Linux; Tizen 5.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/69.0.3497.106 Safari/537.36',
    },
    metadata: { tier: 5, brand: 'Generic', model: 'Cheap TV / Smart TV', screen: '1366x768', note: 'Common cheap TV with basic WebView browser.' },
  },
  {
    name: 't5-rpi-kiosk',
    use: {
      viewport: { width: 1024, height: 768 },
      deviceScaleFactor: 1,
      isMobile: false,
      hasTouch: false,
      userAgent: 'Mozilla/5.0 (X11; Linux armv7l) AppleWebKit/537.36 (KHTML, like Gecko) Chromium/92.0.4515.159 Safari/537.36',
    },
    metadata: { tier: 5, brand: 'Raspberry Pi', model: 'Kiosk (Chromium)', screen: '1024x768', note: 'Limited GPU. Often connected to old monitors.' },
  },
];

// TIER 6 — Feature Phones (edge case but real in rural areas)
const TIER_6 = [
  {
    name: 't6-kaios-feature-phone',
    use: {
      viewport: { width: 240, height: 320 },
      deviceScaleFactor: 1,
      isMobile: true,
      hasTouch: false,                                 // D-pad navigation only
      userAgent: 'Mozilla/5.0 (Mobile; LYF/F90M/LYF-F90M-000-02-28-130318; Android; rv:48.0) Gecko/48.0 Firefox/48.0 KAIOS/2.5',
    },
    metadata: { tier: 6, brand: 'KaiOS', model: 'Feature Phone', ram: '256MB-512MB', screen: '2.4" 240x320', note: 'D-pad only — no touch. Extremely limited browser. Test basic HTML renders.' },
  },
];

/* ------------------------------------------------------------------ */
/*  Config                                                             */
/* ------------------------------------------------------------------ */

const ALL_PROJECTS = [
  ...TIER_1, ...TIER_2, ...TIER_3,
  ...TIER_4, ...TIER_5, ...TIER_6,
];

/** @type {import('@playwright/test').PlaywrightTestConfig} */
module.exports = defineConfig({
  testDir: './tests/e2e',

  timeout: 60_000,

  expect: {
    timeout: 10_000,
  },

  fullyParallel: false,

  forbidOnly: !!process.env.CI,

  retries: process.env.CI ? 2 : 0,

  workers: process.env.CI ? 1 : undefined,

  reporter: [
    ['html', { outputFolder: 'reports/playwright', open: 'never' }],
    ['list'],
    ...(process.env.CI ? [['junit', { outputFile: 'reports/playwright-junit.xml' }]] : []),
  ],

  use: {
    baseURL: process.env.BASE_URL || 'http://localhost:8080',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'on-first-retry',
  },

  projects: ALL_PROJECTS,

  outputDir: 'reports/playwright-artifacts',
});

/* Export helpers for use in tests */
module.exports.NETWORK = NETWORK;
module.exports.ALL_PROJECTS = ALL_PROJECTS;
module.exports.TIERS = { TIER_1, TIER_2, TIER_3, TIER_4, TIER_5, TIER_6 };
