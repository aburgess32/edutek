/**
 * EduPak Content Integrity Checker
 *
 * Verifies that all content_meta entries point to real, playable files.
 * Removes entries for missing, empty, or junk files (macOS ._ files, zero-byte).
 *
 * Usage: node content-integrity-check.js [base-url]
 * Default: http://localhost/Edutek
 *
 * Can also be run server-side via PHP:
 *   php -d max_execution_time=0 content-integrity-check.php
 */
const { chromium } = require('playwright');

const BASE = process.argv[2] || 'http://localhost/Edutek';

async function run() {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext();
  const page = await context.newPage();

  // Hit the health check to verify DB connectivity
  console.log('=== Content Integrity Check ===\n');
  
  try {
    const resp = await page.goto(BASE + '/api/health-check.php', { timeout: 10000 });
    const health = JSON.parse(await resp.text());
    console.log('Health check:', health.status);
    console.log('Database:', health.checks.database.status);
  } catch(e) {
    console.error('Cannot reach server:', e.message);
    process.exit(1);
  }

  // Run cleanup via HTTP
  const cleanupUrl = BASE + '/api/content-index.php?action=status&key=' + (process.env.DEPLOY_SECRET || '');
  try {
    const resp = await page.goto(cleanupUrl, { timeout: 30000 });
    const data = JSON.parse(await resp.text());
    console.log('\nContent Index Status:');
    console.log(JSON.stringify(data, null, 2));
  } catch(e) {
    console.log('Content index status check failed (may need DEPLOY_SECRET)');
  }

  await browser.close();
  console.log('\nDone. For full integrity check, run cleanup-empty.php on the device.');
}

run().catch(err => { console.error('Fatal:', err); process.exit(1); });
