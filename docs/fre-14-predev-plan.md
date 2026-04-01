# FRE-14 Pre-Dev Plan: Layout Mode Toggle

## Scope Summary

**Single icon button** sitting above the breadcrumb (same fixed-bottom-left zone) that cycles through three layouts: **Phone → Tablet → Screen**. The icon and the breadcrumb trail share the same collapse toggle — when breadcrumb is collapsed, the mode icon hides with it.

### Key Decisions (Locked)
- **Three modes only**: `phone`, `tablet`, `screen` (maps to projector/touch screen/classroom display)
- **One button, cycles on tap**: phone → tablet → screen → phone...
- **No auto-detect** — defaults to `phone`, user taps to change
- **Device-scoped cookie** (30-day), not user-scoped
- **CSS custom properties** drive font-scale, grid cols, tile gap, button sizes
- **No content filtering by mode** — all segments visible in all modes (content filtering is a separate concern, role-based)
- **No new PHP includes** — mode resolver added to `auth.php` (already loaded everywhere)

---

## Architecture

```
┌──────────────────────────────────────────────┐
│  Fixed bottom-left corner (same zone as bc)   │
│                                               │
│  ┌──────┐                                     │
│  │ 📱   │  ← mode icon (above bc toggle)      │
│  ├──────┤                                     │
│  │ ▸    │  ← existing bc toggle               │
│  └──────┘                                     │
│  Early Learners > Math > Fractions            │
│                                               │
│  Tap mode icon: cycles phone → tablet → screen│
│  Cookie set immediately, fetch POST to sync   │
│  session, CSS updates via data-mode on <body> │
└──────────────────────────────────────────────┘

When bc is collapsed:
  ┌──────┐
  │ ▾    │  ← only the bc toggle visible
  └──────┘
  (mode icon hidden along with bc trail)
```

### Mode Cookie Flow
1. Page load → PHP reads `edupak_mode` cookie → sets `data-mode` on `<body>`
2. No cookie → defaults to `phone`
3. User taps icon → JS updates `data-mode`, writes cookie, POSTs to `/api/set-mode.php`
4. Next page load → PHP reads cookie → correct mode from first paint

---

## What Exists vs What's Needed

| Component | Current State | Work |
|-----------|--------------|------|
| `breadcrumb.html.php` | Toggle button + trail, fixed bottom-left | Add mode icon above the toggle |
| `breadcrumb.css` | Toggle + trail styles | Add mode icon styles |
| `auth.php` | Starts session, loaded everywhere | Add 5-line mode resolver |
| `tiles.css` | Hardcoded grid flex values + `@media` breakpoints | Add `[data-mode]` overrides |
| `navbar.php` / `navhome.php` | `<body>` has `data-user-role` etc., no `data-mode` | Add `data-mode` attr |
| `api/` | Has `avatar-lookup.php` | Add `set-mode.php` |

---

## New Files

| File | Purpose |
|------|---------|
| `htdocs/api/set-mode.php` | POST endpoint — validate mode, set session + cookie |
| `tests/e2e/fre14-mode-switch.spec.js` | Playwright tests |

## Modified Files

| File | Changes |
|------|---------|
| `htdocs/includes/auth.php` | Add mode resolver (~5 lines) |
| `htdocs/includes/breadcrumb.html.php` | Add mode cycle icon above bc toggle |
| `htdocs/css/breadcrumb.css` | Add mode icon styles (positioned above toggle) |
| `htdocs/css/tiles.css` | Add `[data-mode="tablet"]` and `[data-mode="screen"]` grid overrides |
| `htdocs/navbar.php` | Add `data-mode="<?= getMode() ?>"` to `<body>` |
| `htdocs/navhome.php` | Same — add `data-mode` to `<body>` |

---

## Component Design

### PHP: Mode Resolver (append to `auth.php`)

```php
// ── Layout Mode ──────────────────────────────────────────────
$GLOBALS['edupak_mode'] = 'phone';
$_allowed_modes = ['phone', 'tablet', 'screen'];

if (!empty($_SESSION['mode']) && in_array($_SESSION['mode'], $_allowed_modes, true)) {
    $GLOBALS['edupak_mode'] = $_SESSION['mode'];
} elseif (!empty($_COOKIE['edupak_mode']) && in_array($_COOKIE['edupak_mode'], $_allowed_modes, true)) {
    $GLOBALS['edupak_mode'] = $_COOKIE['edupak_mode'];
    $_SESSION['mode'] = $GLOBALS['edupak_mode'];
}

function getMode(): string {
    return $GLOBALS['edupak_mode'] ?? 'phone';
}
```

### PHP: `<body>` Tag Update (navbar.php + navhome.php)

```php
<body class="fixed-nav sticky-footer" id="page-top"
  data-mode="<?php echo htmlspecialchars(getMode(), ENT_QUOTES, 'UTF-8'); ?>"
  data-user-role="..." ...>
```

### PHP: `api/set-mode.php`

```php
<?php
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$mode = filter_input(INPUT_POST, 'mode', FILTER_SANITIZE_SPECIAL_CHARS);
$allowed = ['phone', 'tablet', 'screen'];

if (!in_array($mode, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid mode']);
    exit;
}

$_SESSION['mode'] = $mode;
setcookie('edupak_mode', $mode, time() + 86400 * 30, '/', '', false, false);
echo json_encode(['ok' => true, 'mode' => $mode]);
```

### HTML/JS: Mode Icon in `breadcrumb.html.php`

Added directly above the existing `bc__toggle` button, inside the same `.bc` nav container:

```php
<!-- Mode cycle button — hidden when bc is collapsed -->
<button class="bc__mode" type="button"
        aria-label="Switch layout mode"
        style="pointer-events:auto"
        onclick="(function(b){
          var modes=['phone','tablet','screen'];
          var icons={phone:'\u{1F4F1}',tablet:'\u{1F4CB}',screen:'\u{1F4FA}'};
          var cur=document.body.dataset.mode||'phone';
          var next=modes[(modes.indexOf(cur)+1)%3];
          document.body.dataset.mode=next;
          b.querySelector('.bc__mode-icon').textContent=icons[next];
          document.cookie='edupak_mode='+next+';path=/;max-age='+(86400*30);
          var fd=new FormData();fd.append('mode',next);
          fetch('/api/set-mode.php',{method:'POST',body:fd}).catch(function(){});
        })(this)">
  <span class="bc__mode-icon"><?php
    $modeIcons = ['phone' => "\u{1F4F1}", 'tablet' => "\u{1F4CB}", 'screen' => "\u{1F4FA}"];
    echo $modeIcons[getMode()] ?? "\u{1F4F1}";
  ?></span>
</button>
```

**Icons**: 📱 Phone, 📋 Tablet, 📺 Screen — single emoji, no SVG needed.

### CSS: Mode Icon + Layout Overrides

Additions to `breadcrumb.css`:

```css
/* ── Mode cycle button (above bc toggle) ───────────────── */
.bc__mode {
    all: unset;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 20px;
    height: 20px;
    font-size: 14px;
    line-height: 1;
    color: rgba(0, 0, 0, 0.3);
    transition: color 0.15s;
    pointer-events: auto;
    position: absolute;
    bottom: 100%;          /* sits directly above the toggle */
    left: 12px;            /* aligned with bc padding */
    margin-bottom: 4px;
}
.bc__mode:hover { color: rgba(0, 0, 0, 0.5); }

/* Hidden when breadcrumb is collapsed */
.bc--collapsed .bc__mode { display: none; }

@media (pointer: coarse) {
    .bc__mode { width: 28px; height: 28px; font-size: 16px; }
}
```

Additions to `tiles.css` (after existing `@media` breakpoints):

```css
/* ── Layout mode overrides ─────────────────────────────── */

/* Tablet: 3-col segments, 3-col topics, slightly larger gap */
[data-mode="tablet"] .seg-tile {
    flex: 1 1 calc(33.333% - 14px);
    max-width: calc(33.333% - 10px);
}
[data-mode="tablet"] .topic-tile {
    flex: 1 1 calc(33.333% - 14px);
    max-width: calc(33.333% - 10px);
}
[data-mode="tablet"] .seg-grid,
[data-mode="tablet"] .topic-grid {
    gap: 14px;
}

/* Screen: 4-col, large gap, bigger font, high-contrast */
[data-mode="screen"] .seg-tile {
    flex: 1 1 calc(25% - 24px);
    max-width: calc(25% - 18px);
}
[data-mode="screen"] .topic-tile {
    flex: 1 1 calc(25% - 24px);
    max-width: calc(25% - 18px);
}
[data-mode="screen"] .seg-grid,
[data-mode="screen"] .topic-grid {
    gap: 24px;
}
[data-mode="screen"] body,
body[data-mode="screen"] {
    font-size: 1.35rem;
    background: #000;
    color: #fff;
}
[data-mode="screen"] .navbar {
    background: #111 !important;
}
[data-mode="screen"] .seg-tile,
[data-mode="screen"] .topic-tile {
    border: 2px solid rgba(255,255,255,0.15);
}
[data-mode="screen"] .bc__toggle,
[data-mode="screen"] .bc__mode,
[data-mode="screen"] .bc__link,
[data-mode="screen"] .bc__label {
    color: rgba(255,255,255,0.4);
}

/* Phone: default — no overrides needed, existing styles apply */
```

---

## Mode Comparison

| Property | Phone (default) | Tablet | Screen |
|----------|----------------|--------|--------|
| Seg grid cols | 2 | 3 | 4 |
| Topic grid cols | 2 (phone) / 4 (wide via @media) | 3 | 4 |
| Tile gap | 12px | 14px | 24px |
| Font scale | 1rem (default) | 1rem | 1.35rem |
| Background | #f5f5f5 | #f5f5f5 | #000 |
| Text | dark | dark | white |
| Navbar | default | default | dark |
| Breadcrumb | visible | visible | visible |
| Icon | 📱 | 📋 | 📺 |

---

## Edge Cases

| Case | Handling |
|------|----------|
| No cookie | Defaults to `phone` |
| Invalid cookie value | PHP allowlist rejects → `phone` |
| Cookie blocked | `phone` every load (no persistence, but functional) |
| Mode tap while video playing | Grid/chrome updates; player untouched |
| JS disabled | PHP sets `data-mode` from cookie on `<body>`; CSS applies; no cycle button (it won't fire) |
| Breadcrumb collapsed | Mode icon hidden — expand breadcrumb to access it |
| Screen mode on a phone | Works fine — user explicitly chose it, 4-col will just be dense/scrollable |

---

## Test Plan

| # | Test | Expected |
|---|------|----------|
| T1 | Fresh load, no cookie | `data-mode="phone"`, 2-col grid, 📱 icon |
| T2 | Tap mode icon once | Switches to `tablet`, 3-col grid, 📋 icon |
| T3 | Tap mode icon again | Switches to `screen`, 4-col grid, dark bg, 📺 icon |
| T4 | Tap mode icon third time | Back to `phone` |
| T5 | Refresh after switching to `tablet` | Mode persists (cookie), still `tablet` |
| T6 | Switch user (new login) on same device | Mode stays (device-scoped cookie) |
| T7 | Collapse breadcrumb | Mode icon disappears along with trail |
| T8 | Expand breadcrumb | Mode icon reappears |
| T9 | Screen mode: verify dark bg + white text | `background: #000`, `color: #fff` |
| T10 | Screen mode: verify 4-col tiles | `.seg-tile` at 25% flex basis |
| T11 | Set cookie to `bogus` | PHP falls back to `phone` |
| T12 | JS disabled + cookie set to `tablet` | PHP outputs `data-mode="tablet"`, CSS applies 3-col |

---

## Task Breakdown

| # | Task | Est | Depends |
|---|------|-----|---------|
| 1 | Mode resolver in `auth.php` + `getMode()` | 0.25d | — |
| 2 | `api/set-mode.php` endpoint | 0.25d | 1 |
| 3 | `data-mode` attr on `<body>` in `navbar.php` + `navhome.php` | 0.15d | 1 |
| 4 | Mode icon button in `breadcrumb.html.php` + cycle JS | 0.25d | 1, 3 |
| 5 | Mode icon styles in `breadcrumb.css` (positioning, collapse hide) | 0.15d | 4 |
| 6 | Layout overrides in `tiles.css` (`[data-mode]` selectors) | 0.5d | 3 |
| 7 | Screen mode dark theme (navbar, tiles, breadcrumb text) | 0.25d | 6 |
| 8 | Playwright tests | 0.5d | all |
| **Total** | | **~2.3d** | |

---

## Risks

| Risk | Mitigation |
|------|------------|
| Emoji icons render inconsistently across old Android WebViews | Fallback: use single-character text (P / T / S) instead of emoji |
| Screen mode dark bg clashes with Bootstrap inherited styles | Scope all dark overrides under `[data-mode="screen"]` with high specificity |
| Mode icon not discoverable (no label) | Acceptable for v1 — it's a power-user control. Add tooltip via `title` attr. |

### Blocking Question
**Are the emoji icons (📱📋📺) fine, or should we use plain text labels (P / T / S) for max device compatibility?**
