# EduPak Diagnostic Tools

## Setup (one-time)
```
cd scripts/diagnostics
npm init -y
npm install playwright
npx playwright install chromium
```

## responsive-test.js
Screenshots every page across 7 African-market device viewports + desktop.
```
node responsive-test.js "http://localhost/Edutek"
```
Output: `report.html` + `screenshots/` folder

## category-profiler.js
Profiles cold-start load time for every category and sub-category from the homepage.
```
node category-profiler.js "http://localhost/Edutek"
```
Output: `category-report.html` + `category-report.json` + `category-screenshots/`
