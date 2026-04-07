/**
 * EduPak Category Load Time Profiler
 * 
 * Opens each category from the homepage, measures how long each takes to load.
 * Discovers all category links dynamically from the actual homepage.
 * Produces a JSON report + console table sorted by load time.
 *
 * Usage: node category-profiler.js [base-url]
 * Default: http://localhost/Edutek
 */

const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.argv[2] || 'http://localhost/Edutek';
const SCREENSHOT_DIR = path.join(__dirname, 'category-screenshots');
const REPORT_PATH = path.join(__dirname, 'category-report.html');
const JSON_PATH = path.join(__dirname, 'category-report.json');

// Use a mid-range African phone viewport
const DEVICE = { width: 360, height: 800, dpr: 2, name: 'Tecno Spark 40' };

async function run() {
  if (!fs.existsSync(SCREENSHOT_DIR)) fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });

  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({
    viewport: { width: DEVICE.width, height: DEVICE.height },
    deviceScaleFactor: DEVICE.dpr,
    isMobile: true,
    hasTouch: true,
    userAgent: 'Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
  });

  // ── Step 1: Discover all category links from the homepage ──
  console.log('=== Step 1: Discovering categories from homepage ===\n');
  const homePage = await context.newPage();
  await homePage.goto(BASE + '/', { waitUntil: 'networkidle', timeout: 30000 });

  // Find all segment tile links (browse.php?seg=X)
  const segLinks = await homePage.evaluate(function() {
    var links = [];
    document.querySelectorAll('a[href*="browse.php"]').forEach(function(a) {
      var text = a.textContent.trim().replace(/\s+/g, ' ');
      if (text && a.href) {
        links.push({ label: text.substring(0, 60), url: a.href });
      }
    });
    return links;
  });

  // Also find direct content links (tutorials.php, audiobooks.php, etc.)
  const directLinks = await homePage.evaluate(function() {
    var links = [];
    var selectors = 'a[href*="tutorials.php"], a[href*="audiobooks.php"], a[href*="books.php"], a[href*="music.php"], a[href*="listen.php"], a[href*="Comic_books.php"]';
    document.querySelectorAll(selectors).forEach(function(a) {
      var text = a.textContent.trim().replace(/\s+/g, ' ');
      if (text && a.href) {
        links.push({ label: text.substring(0, 60), url: a.href, type: 'direct' });
      }
    });
    return links;
  });

  await homePage.close();

  // Deduplicate
  var allLinks = [];
  var seen = {};

  // Add segment links
  segLinks.forEach(function(l) { 
    if (!seen[l.url]) { allLinks.push({ label: l.label, url: l.url, type: 'segment' }); seen[l.url] = true; }
  });
  directLinks.forEach(function(l) {
    if (!seen[l.url]) { allLinks.push(l); seen[l.url] = true; }
  });

  console.log('Found ' + allLinks.length + ' category links from homepage\n');

  // ── Step 2: For each segment, also discover sub-category links ──
  console.log('=== Step 2: Profiling each category (cold start) ===\n');

  var results = [];

  for (var i = 0; i < allLinks.length; i++) {
    var link = allLinks[i];
    var page = await context.newPage();
    var jsErrors = [];
    page.on('console', function(msg) { if (msg.type() === 'error') jsErrors.push(msg.text()); });
    page.on('pageerror', function(err) { jsErrors.push(err.message); });

    var status = 0;
    var loadTime = 0;
    var error = null;
    var safeName = link.label.replace(/[^a-zA-Z0-9]/g, '-').substring(0, 40);
    var screenshotFile = (i + 1) + '-' + safeName + '.png';
    var screenshotPath = path.join(SCREENSHOT_DIR, screenshotFile);

    // Cold start — fresh page, no cache
    try {
      var t0 = Date.now();
      var response = await page.goto(link.url, { waitUntil: 'networkidle', timeout: 60000 });
      loadTime = Date.now() - t0;
      status = response ? response.status() : 0;
      await page.waitForTimeout(300);
      await page.screenshot({ path: screenshotPath, fullPage: true });
    } catch (err2) {
      error = err2.message.substring(0, 150);
      loadTime = Date.now() - t0;
      try { await page.screenshot({ path: screenshotPath, fullPage: true }); } catch(e) {}
    }

    // Discover sub-category links from this page
    var subLinks = [];
    try {
      subLinks = await page.evaluate(function() {
        var links = [];
        var selectors = 'a[href*="tutorials.php"], a[href*="watch.php"], a[href*="listen.php"], a[href*="audiobooks.php"], a[href*="books.php"], a[href*="music.php"]';
        document.querySelectorAll(selectors).forEach(function(a) {
          var text = a.textContent.trim().replace(/\s+/g, ' ').substring(0, 60);
          if (text && a.href) links.push({ label: text, url: a.href });
        });
        // Also get topic tiles
        document.querySelectorAll('.topic-tile, .dir-item-link').forEach(function(a) {
          var text = a.textContent.trim().replace(/\s+/g, ' ').substring(0, 60);
          if (text && a.href && !links.some(function(l) { return l.url === a.href; })) {
            links.push({ label: text, url: a.href });
          }
        });
        return links;
      });
    } catch(e) {}

    var result = {
      index: i + 1,
      label: link.label,
      url: link.url,
      type: link.type || 'segment',
      status: status,
      loadTime: loadTime,
      jsErrors: jsErrors.length,
      error: error,
      screenshot: screenshotFile,
      subCategories: subLinks.length,
      grade: loadTime < 1000 ? 'A' : loadTime < 2000 ? 'B' : loadTime < 5000 ? 'C' : loadTime < 10000 ? 'D' : 'F',
    };
    results.push(result);

    var gradeColor = result.grade === 'A' ? '\x1b[32m' : result.grade === 'B' ? '\x1b[32m' : result.grade === 'C' ? '\x1b[33m' : result.grade === 'D' ? '\x1b[33m' : '\x1b[31m';
    var reset = '\x1b[0m';
    console.log('  ' + gradeColor + result.grade + reset + ' [' + (i+1) + '/' + allLinks.length + '] ' + link.label.substring(0, 35).padEnd(35) + ' ' + loadTime + 'ms  HTTP ' + status + (subLinks.length > 0 ? '  (' + subLinks.length + ' sub-links)' : ''));

    // Profile sub-categories too (first 3 from each segment page)
    if (link.type === 'segment' && subLinks.length > 0) {
      var subSample = subLinks.slice(0, 5); // Test first 5 sub-categories
      for (var j = 0; j < subSample.length; j++) {
        var sub = subSample[j];
        if (seen[sub.url]) continue;
        seen[sub.url] = true;

        var subPage = await context.newPage();
        var subJsErrors = [];
        subPage.on('console', function(msg) { if (msg.type() === 'error') subJsErrors.push(msg.text()); });
        
        var subStatus = 0;
        var subLoadTime = 0;
        var subError = null;
        var subSafeName = sub.label.replace(/[^a-zA-Z0-9]/g, '-').substring(0, 40);
        var subScreenFile = (i+1) + '-sub-' + (j+1) + '-' + subSafeName + '.png';
        var subScreenPath = path.join(SCREENSHOT_DIR, subScreenFile);

        try {
          var st0 = Date.now();
          var subResp = await subPage.goto(sub.url, { waitUntil: 'networkidle', timeout: 60000 });
          subLoadTime = Date.now() - st0;
          subStatus = subResp ? subResp.status() : 0;
          await subPage.waitForTimeout(300);
          await subPage.screenshot({ path: subScreenPath, fullPage: true });
        } catch (subErr) {
          subError = subErr.message.substring(0, 150);
          subLoadTime = Date.now() - st0;
          try { await subPage.screenshot({ path: subScreenPath, fullPage: true }); } catch(e) {}
        }

        var subResult = {
          index: results.length + 1,
          label: '  > ' + sub.label,
          url: sub.url,
          type: 'sub-category',
          parentLabel: link.label,
          status: subStatus,
          loadTime: subLoadTime,
          jsErrors: subJsErrors.length,
          error: subError,
          screenshot: subScreenFile,
          subCategories: 0,
          grade: subLoadTime < 1000 ? 'A' : subLoadTime < 2000 ? 'B' : subLoadTime < 5000 ? 'C' : subLoadTime < 10000 ? 'D' : 'F',
        };
        results.push(subResult);

        var sg = subResult.grade === 'A' ? '\x1b[32m' : subResult.grade === 'B' ? '\x1b[32m' : subResult.grade === 'C' ? '\x1b[33m' : subResult.grade === 'D' ? '\x1b[33m' : '\x1b[31m';
        console.log('  ' + sg + subResult.grade + reset + '   > ' + sub.label.substring(0, 33).padEnd(33) + ' ' + subLoadTime + 'ms  HTTP ' + subStatus);

        await subPage.close();
      }
    }

    await page.close();
  }

  await browser.close();

  // ── Sort by load time (slowest first) ──
  var sorted = results.slice().sort(function(a, b) { return b.loadTime - a.loadTime; });

  // ── Summary ──
  console.log('\n=== RESULTS (sorted by load time, slowest first) ===\n');
  console.log('Grade | Load Time | Page');
  console.log('------|-----------|-----');
  sorted.forEach(function(r) {
    var g = r.grade === 'F' ? '  F  ' : '  ' + r.grade + '  ';
    var t = (r.loadTime + 'ms').padStart(9);
    console.log(g + ' | ' + t + ' | ' + r.label + (r.error ? ' [ERROR]' : ''));
  });

  var grades = { A: 0, B: 0, C: 0, D: 0, F: 0 };
  results.forEach(function(r) { grades[r.grade]++; });
  
  console.log('\n=== GRADE SUMMARY ===');
  console.log('A (<1s): ' + grades.A + '  B (<2s): ' + grades.B + '  C (<5s): ' + grades.C + '  D (<10s): ' + grades.D + '  F (>10s): ' + grades.F);
  console.log('Total categories tested: ' + results.length);

  // Save JSON
  fs.writeFileSync(JSON_PATH, JSON.stringify(results, null, 2));
  console.log('\nJSON report: ' + JSON_PATH);

  // Generate HTML report
  fs.writeFileSync(REPORT_PATH, generateHTML(results, sorted, grades));
  console.log('HTML report: ' + REPORT_PATH);
}

function generateHTML(results, sorted, grades) {
  var total = results.length;
  var h = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>EduPak Category Load Time Report</title><style>';
  h += '*{margin:0;padding:0;box-sizing:border-box}body{font-family:-apple-system,BlinkMacSystemFont,sans-serif;background:#0f0f0f;color:#e0e0e0;padding:20px}';
  h += 'h1{font-size:24px;margin-bottom:4px}.subtitle{color:#888;margin-bottom:20px;font-size:14px}';
  h += '.grades{display:flex;gap:12px;margin-bottom:24px;flex-wrap:wrap}';
  h += '.grade-box{padding:12px 20px;border-radius:8px;border:1px solid #333;background:#1a1a1a;text-align:center;min-width:80px}';
  h += '.grade-box .letter{font-size:28px;font-weight:700}.grade-box .count{font-size:12px;color:#888}';
  h += '.grade-A .letter{color:#4ade80}.grade-B .letter{color:#86efac}.grade-C .letter{color:#fbbf24}.grade-D .letter{color:#fb923c}.grade-F .letter{color:#f87171}';
  h += 'table{width:100%;border-collapse:collapse;margin-top:16px}th{text-align:left;padding:8px 12px;border-bottom:2px solid #333;color:#888;font-size:12px;text-transform:uppercase}';
  h += 'td{padding:8px 12px;border-bottom:1px solid #222;font-size:14px}tr:hover{background:#1a1a1a}';
  h += '.grade-cell{font-weight:700;font-size:16px;width:50px;text-align:center}';
  h += '.grade-cell.A{color:#4ade80}.grade-cell.B{color:#86efac}.grade-cell.C{color:#fbbf24}.grade-cell.D{color:#fb923c}.grade-cell.F{color:#f87171}';
  h += '.time-cell{font-family:monospace;text-align:right;width:100px}.sub{color:#888;padding-left:24px}';
  h += '.bar{height:6px;border-radius:3px;margin-top:4px}.bar-A{background:#4ade80}.bar-B{background:#86efac}.bar-C{background:#fbbf24}.bar-D{background:#fb923c}.bar-F{background:#f87171}';
  h += '.thumb{width:60px;height:40px;object-fit:cover;border-radius:4px;cursor:pointer}.thumb:hover{position:fixed;top:20px;right:20px;width:360px;height:auto;z-index:999;border:2px solid #555}';
  h += '</style></head><body>';
  h += '<h1>Category Load Time Profiler</h1>';
  h += '<p class="subtitle">Cold-start load times from homepage categories and sub-categories | Tested on ' + DEVICE.name + ' (' + DEVICE.width + 'x' + DEVICE.height + ')</p>';

  h += '<div class="grades">';
  ['A','B','C','D','F'].forEach(function(g) {
    var labels = {A:'<1s',B:'<2s',C:'<5s',D:'<10s',F:'>10s'};
    h += '<div class="grade-box grade-' + g + '"><div class="letter">' + g + '</div><div class="count">' + (grades[g]||0) + ' (' + labels[g] + ')</div></div>';
  });
  h += '</div>';

  // Find max load time for bar scaling
  var maxTime = Math.max.apply(null, sorted.map(function(r) { return r.loadTime; }));

  h += '<table><thead><tr><th>Grade</th><th>Page</th><th>Load Time</th><th>Bar</th><th>HTTP</th><th>Screenshot</th></tr></thead><tbody>';
  sorted.forEach(function(r) {
    var barWidth = Math.max(2, Math.round((r.loadTime / maxTime) * 200));
    var labelClass = r.type === 'sub-category' ? ' class="sub"' : '';
    h += '<tr>';
    h += '<td class="grade-cell ' + r.grade + '">' + r.grade + '</td>';
    h += '<td' + labelClass + '>' + r.label.replace(/</g,'&lt;') + '</td>';
    h += '<td class="time-cell">' + (r.loadTime / 1000).toFixed(1) + 's</td>';
    h += '<td><div class="bar bar-' + r.grade + '" style="width:' + barWidth + 'px"></div></td>';
    h += '<td>' + r.status + '</td>';
    h += '<td><img class="thumb" src="category-screenshots/' + r.screenshot + '" loading="lazy"></td>';
    h += '</tr>';
  });
  h += '</tbody></table></body></html>';
  return h;
}

run().catch(function(err) { console.error('Fatal:', err); process.exit(1); });
