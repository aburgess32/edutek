const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

// ── Africa-market device profiles ──
const devices = [
  { name: 'Tecno Spark 40',     width: 360, height: 800, dpr: 2 },
  { name: 'Tecno Spark Go',     width: 360, height: 806, dpr: 2 },
  { name: 'Tecno Camon 40',     width: 385, height: 854, dpr: 2 },
  { name: 'Tecno Spark Pro',    width: 384, height: 832, dpr: 2 },
  { name: 'Samsung Galaxy A15', width: 412, height: 915, dpr: 2.6 },
  { name: 'Itel Budget',        width: 360, height: 780, dpr: 2 },
  { name: 'Itel Legacy',        width: 360, height: 640, dpr: 2 },
  { name: 'Desktop 1366x768',   width: 1366, height: 768, dpr: 1 },
];

// ── Pages to test ──
const BASE = process.argv[2] || 'http://localhost/Edutek';
const pages = [
  { name: 'Homepage',         path: '/' },
  { name: 'Find User',        path: '/find-user.php' },
  { name: 'Login',            path: '/login.php' },
  { name: 'Register',         path: '/register.php' },
  { name: 'Browse',           path: '/browse.php' },
  { name: 'Search Math',      path: '/result.php?q=math' },
  { name: 'Watch',            path: '/watch.php' },
  { name: 'Audiobooks',       path: '/audiobooks.php' },
  { name: 'Books',            path: '/books.php' },
  { name: 'Music',            path: '/music.php' },
  { name: 'Comics',           path: '/Comic_books.php' },
  { name: 'Tutorials',        path: '/tutorials.php' },
  { name: 'Playlists',        path: '/playlist.php' },
  { name: 'PDF Reader',       path: '/readpdf.php' },
  { name: 'Teacher Login',    path: '/teacher-login.php' },
  { name: 'Khan Academy',     path: '/khan/' },
  { name: 'Wikipedia',        path: '/Wiki/' },
  { name: 'Health Check',     path: '/api/health-check.php' },
];

const SCREENSHOT_DIR = path.join(__dirname, 'screenshots');
const REPORT_PATH = path.join(__dirname, 'report.html');

async function run() {
  if (!fs.existsSync(SCREENSHOT_DIR)) fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });

  const results = [];
  const browser = await chromium.launch({ headless: true });

  for (const device of devices) {
    const safeDev = device.name.replace(/[^a-zA-Z0-9]/g, '-');
    console.log(`\n── ${device.name} (${device.width}x${device.height}) ──`);

    const context = await browser.newContext({
      viewport: { width: device.width, height: device.height },
      deviceScaleFactor: device.dpr,
      isMobile: device.width < 800,
      hasTouch: device.width < 800,
      userAgent: device.width < 800
        ? 'Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36'
        : undefined,
    });

    for (const pg of pages) {
      const page = await context.newPage();
      const jsErrors = [];
      page.on('console', msg => { if (msg.type() === 'error') jsErrors.push(msg.text()); });
      page.on('pageerror', err => jsErrors.push(err.message));

      let status = 0;
      let loadTime = 0;
      let error = null;
      const filename = `${safeDev}--${pg.name.replace(/[^a-zA-Z0-9]/g, '-')}.png`;
      const filepath = path.join(SCREENSHOT_DIR, filename);

      try {
        const t0 = Date.now();
        const response = await page.goto(`${BASE}${pg.path}`, {
          waitUntil: 'networkidle',
          timeout: 30000,
        });
        loadTime = Date.now() - t0;
        status = response ? response.status() : 0;

        // Wait a bit for any animations/rendering
        await page.waitForTimeout(500);

        await page.screenshot({ path: filepath, fullPage: true });
      } catch (err) {
        error = err.message.substring(0, 120);
        // Try to screenshot even on error
        try { await page.screenshot({ path: filepath, fullPage: true }); } catch(_) {}
      }

      const result = {
        device: device.name,
        deviceWidth: device.width,
        deviceHeight: device.height,
        page: pg.name,
        url: pg.path,
        status,
        loadTime,
        jsErrors: jsErrors.length,
        jsErrorDetails: jsErrors.slice(0, 3),
        error,
        screenshot: filename,
        slow: loadTime > 3000,
      };
      results.push(result);

      const statusIcon = status === 200 ? '✓' : '✗';
      const timeStr = `${loadTime}ms`;
      const errStr = jsErrors.length > 0 ? ` (${jsErrors.length} JS errors)` : '';
      const slowStr = result.slow ? ' [SLOW]' : '';
      console.log(`  ${statusIcon} ${pg.name}: HTTP ${status} ${timeStr}${errStr}${slowStr}`);

      await page.close();
    }

    await context.close();
  }

  await browser.close();

  // ── Generate HTML report ──
  const html = generateReport(results);
  fs.writeFileSync(REPORT_PATH, html);
  console.log(`\n✓ Report: ${REPORT_PATH}`);
  console.log(`✓ Screenshots: ${SCREENSHOT_DIR}/`);

  // ── Summary ──
  const total = results.length;
  const passed = results.filter(r => r.status === 200).length;
  const failed = results.filter(r => r.status !== 200).length;
  const slow = results.filter(r => r.slow).length;
  const withJsErrors = results.filter(r => r.jsErrors > 0).length;
  console.log(`\n═══ SUMMARY ═══`);
  console.log(`Total: ${total} | Passed: ${passed} | Failed: ${failed} | Slow: ${slow} | JS Errors: ${withJsErrors}`);
}

function generateReport(results) {
  const deviceGroups = {};
  for (const r of results) {
    if (!deviceGroups[r.device]) deviceGroups[r.device] = [];
    deviceGroups[r.device].push(r);
  }

  const pageNames = pages.map(p => p.name);

  let html = `<!DOCTYPE html>
<html><head><meta charset="utf-8">
<title>EduPak Responsive Test Report</title>
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #0f0f0f; color: #e0e0e0; padding: 20px; }
  h1 { font-size: 24px; margin-bottom: 4px; }
  .subtitle { color: #888; margin-bottom: 20px; font-size: 14px; }
  .summary { display: flex; gap: 16px; margin-bottom: 24px; flex-wrap: wrap; }
  .stat { background: #1a1a1a; border-radius: 8px; padding: 12px 20px; border: 1px solid #333; }
  .stat .num { font-size: 28px; font-weight: 700; }
  .stat .label { font-size: 12px; color: #888; text-transform: uppercase; }
  .stat.pass .num { color: #4ade80; }
  .stat.fail .num { color: #f87171; }
  .stat.slow .num { color: #fbbf24; }
  .stat.jserr .num { color: #fb923c; }
  .device-section { margin-bottom: 32px; }
  .device-header { font-size: 18px; font-weight: 600; margin-bottom: 12px; padding: 8px 0; border-bottom: 1px solid #333; }
  .device-header span { color: #888; font-weight: 400; font-size: 14px; }
  .page-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px; }
  .card { background: #1a1a1a; border-radius: 8px; border: 1px solid #333; overflow: hidden; transition: border-color 0.2s; }
  .card:hover { border-color: #555; }
  .card.fail { border-color: #f87171; }
  .card.slow { border-color: #fbbf24; }
  .card-img { width: 100%; height: 200px; object-fit: cover; object-position: top; cursor: pointer; }
  .card-img:hover { object-fit: contain; height: auto; max-height: 600px; }
  .card-body { padding: 10px 12px; }
  .card-title { font-weight: 600; font-size: 14px; margin-bottom: 4px; }
  .card-meta { font-size: 12px; color: #888; display: flex; gap: 12px; }
  .card-meta .status { color: #4ade80; } .card-meta .status.err { color: #f87171; }
  .card-meta .time { color: #888; } .card-meta .time.slow { color: #fbbf24; }
  .js-errors { font-size: 11px; color: #fb923c; margin-top: 4px; }
  .nav { position: sticky; top: 0; background: #0f0f0f; padding: 10px 0; z-index: 100; border-bottom: 1px solid #222; margin-bottom: 16px; }
  .nav-links { display: flex; gap: 8px; flex-wrap: wrap; }
  .nav-links a { color: #888; text-decoration: none; font-size: 12px; padding: 4px 8px; border-radius: 4px; background: #1a1a1a; border: 1px solid #333; }
  .nav-links a:hover { color: #e0e0e0; border-color: #555; }
  /* Page comparison view */
  .compare-section { margin-bottom: 32px; }
  .compare-header { font-size: 18px; font-weight: 600; margin-bottom: 12px; padding: 8px 0; border-bottom: 1px solid #333; }
  .compare-grid { display: flex; gap: 8px; overflow-x: auto; padding-bottom: 8px; }
  .compare-card { min-width: 180px; max-width: 220px; flex-shrink: 0; background: #1a1a1a; border-radius: 8px; border: 1px solid #333; overflow: hidden; }
  .compare-card .card-img { height: 300px; }
  .compare-card .card-body { padding: 6px 8px; }
  .compare-card .card-title { font-size: 11px; }
  .tab-bar { display: flex; gap: 4px; margin-bottom: 16px; }
  .tab-bar button { background: #1a1a1a; color: #888; border: 1px solid #333; padding: 6px 14px; border-radius: 4px; cursor: pointer; font-size: 13px; }
  .tab-bar button.active { color: #e0e0e0; border-color: #4ade80; background: #1a2a1a; }
  .view { display: none; } .view.active { display: block; }
</style></head><body>
<h1>EduPak Responsive Test Report</h1>
<p class="subtitle">Generated ${new Date().toISOString()} | ${results.length} tests across ${devices.length} devices and ${pageNames.length} pages</p>
`;

  // Summary stats
  const total = results.length;
  const passed = results.filter(r => r.status === 200).length;
  const failed = results.filter(r => r.status !== 200).length;
  const slow = results.filter(r => r.slow).length;
  const withJsErrors = results.filter(r => r.jsErrors > 0).length;

  html += `<div class="summary">
  <div class="stat pass"><div class="num">${passed}</div><div class="label">Passed</div></div>
  <div class="stat fail"><div class="num">${failed}</div><div class="label">Failed</div></div>
  <div class="stat slow"><div class="num">${slow}</div><div class="label">Slow (&gt;3s)</div></div>
  <div class="stat jserr"><div class="num">${withJsErrors}</div><div class="label">JS Errors</div></div>
</div>`;

  // Tab bar
  html += `<div class="tab-bar">
  <button class="active" onclick="showView('by-device')">By Device</button>
  <button onclick="showView('by-page')">By Page (Compare)</button>
</div>`;

  // ── View 1: By Device ──
  html += `<div id="by-device" class="view active">`;
  for (const [devName, devResults] of Object.entries(deviceGroups)) {
    const dev = devices.find(d => d.name === devName);
    html += `<div class="device-section" id="dev-${devName.replace(/[^a-zA-Z0-9]/g, '-')}">
  <div class="device-header">${devName} <span>${dev.width}x${dev.height} @${dev.dpr}x</span></div>
  <div class="page-grid">`;
    for (const r of devResults) {
      const cardClass = r.status !== 200 ? 'card fail' : r.slow ? 'card slow' : 'card';
      const statusClass = r.status === 200 ? 'status' : 'status err';
      const timeClass = r.slow ? 'time slow' : 'time';
      html += `<div class="${cardClass}">
    <img class="card-img" src="screenshots/${r.screenshot}" alt="${r.page}" loading="lazy">
    <div class="card-body">
      <div class="card-title">${r.page}</div>
      <div class="card-meta">
        <span class="${statusClass}">HTTP ${r.status}</span>
        <span class="${timeClass}">${r.loadTime}ms</span>
        ${r.jsErrors > 0 ? `<span style="color:#fb923c">${r.jsErrors} JS err</span>` : ''}
      </div>
      ${r.jsErrorDetails.length > 0 ? `<div class="js-errors">${r.jsErrorDetails.map(e => e.substring(0, 80)).join('<br>')}</div>` : ''}
      ${r.error ? `<div class="js-errors" style="color:#f87171">${r.error}</div>` : ''}
    </div>
  </div>`;
    }
    html += `</div></div>`;
  }
  html += `</div>`;

  // ── View 2: By Page (side-by-side comparison) ──
  html += `<div id="by-page" class="view">`;
  for (const pg of pageNames) {
    const pageResults = results.filter(r => r.page === pg);
    html += `<div class="compare-section">
  <div class="compare-header">${pg}</div>
  <div class="compare-grid">`;
    for (const r of pageResults) {
      html += `<div class="compare-card${r.status !== 200 ? ' fail' : ''}">
    <img class="card-img" src="screenshots/${r.screenshot}" alt="${r.device}" loading="lazy">
    <div class="card-body">
      <div class="card-title">${r.device} (${r.deviceWidth}px)</div>
      <div class="card-meta"><span class="${r.status === 200 ? 'status' : 'status err'}">HTTP ${r.status}</span><span class="time">${r.loadTime}ms</span></div>
    </div>
  </div>`;
    }
    html += `</div></div>`;
  }
  html += `</div>`;

  html += `<script>
function showView(id) {
  document.querySelectorAll('.view').forEach(v => v.classList.remove('active'));
  document.querySelectorAll('.tab-bar button').forEach(b => b.classList.remove('active'));
  document.getElementById(id).classList.add('active');
  event.target.classList.add('active');
}
</script></body></html>`;

  return html;
}

run().catch(err => { console.error('Fatal:', err); process.exit(1); });
