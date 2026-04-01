# FRE-14 Pre-Dev Plan: Mode Switch (Kid/Group/Class/Projector)

## Scope Summary

Three layout modes — **Kid**, **Group** (default), **Projector/Class** — controlled by CSS custom properties on `<body data-mode>`. Auto-detected from screen size on first load, persisted in a device-scoped cookie (30-day), manually overridable via a toggle in the header. No new DB tables.

### Key Decisions (Locked)
- **Device-scoped, not user-scoped** — a projector stays in projector mode regardless of who logs in
- **CSS custom properties** (`--font-scale`, `--grid-cols-seg`, `--grid-cols-topic`, etc.) as single source of truth — no duplicate stylesheets
- **No `init.php`** — mode resolver goes into `includes/config.php` (already loaded everywhere via `auth.php`) to avoid a new include chain
- **Content filtering** — Kid mode limits to `early_learners` segment only; filtering is PHP-side in `tiles.php` helpers
- **Projector mode** — black bg, white text, `1.5×` font, hidden breadcrumb, oversized controls
- **Three modes only** — no assessment/exam mode for v1

### Changes from Original Spec
- ~~Separate `init.php` file~~ → integrate into existing `config.php` / `auth.php` include chain (simpler, already loaded)
- ~~`api/set_mode.php` as standalone file~~ → `api/set-mode.php` (kebab-case, consistent with existing `api/avatar-lookup.php`)
- ~~Auto-detect from User-Agent server-side~~ → JS-only auto-detect (UA sniffing is unreliable); PHP reads cookie/session
- ~~Segment grid 4 cols in projector~~ → **3 cols** (spec says 4 but 3 is more readable on projector at distance)
- ~~`--content-filter` CSS var~~ → removed (content filtering is PHP-side, not CSS-driven)

---

## Architecture Overview

```
┌────────────────────────────────────────────────────────────────┐
│  First page load (no cookie)                                    │
│                                                                 │
│  1. Inline <script> in <head> runs BEFORE first paint           │
│     → reads screen dimensions                                   │
│     → sets document.documentElement.dataset.mode                │
│     → writes edupak_mode cookie (30-day)                        │
│                                                                 │
│  2. PHP reads cookie on NEXT request                            │
│     → sets $_SESSION['mode']                                    │
│     → outputs <body data-mode="...">                            │
│     → filters content in tiles.php based on mode                │
│                                                                 │
│  Subsequent loads:                                              │
│  PHP reads cookie → sets data-mode on <body> server-side        │
│  JS inline script reads cookie → sets data-mode on <html>       │
│  → No flash-of-wrong-mode (both HTML and BODY have the attr)    │
├────────────────────────────────────────────────────────────────┤
│  Manual override:                                               │
│  User clicks mode button in header                              │
│  → JS updates data-mode on <body> instantly                     │
│  → JS writes cookie                                             │
│  → JS POSTs to /api/set-mode.php (sync session)                │
│  → CSS transitions smoothly between modes                       │
└────────────────────────────────────────────────────────────────┘
```

### Mode Resolution Order (PHP)
1. `$_SESSION['mode']` (if set)
2. `$_COOKIE['edupak_mode']` (promoted to session)
3. Default: `'group'`

JS auto-detect only fires when no cookie exists.

---

## Codebase Audit: What Exists vs What's Needed

| Component | Current State | Work Needed |
|-----------|--------------|-------------|
| CSS custom properties in `tiles.css` | `:root` has `--tile-radius`, `--tile-gap`, `--tile-min-touch`, colors — **no** `--font-scale`, `--grid-cols-*`, `--btn-min-h`, `--contrast-*`, `--breadcrumb-show` | Add mode-specific vars to `:root` + `[data-mode]` blocks |
| `<body>` tag in `navbar.php` / `navhome.php` | Has `data-user-role`, `data-avatar-name`, etc. — **no** `data-mode` | Add `data-mode="<?= htmlspecialchars($mode) ?>"` |
| Grid classes | `.seg-grid` / `.topic-grid` use hardcoded `flex: 1 1 calc(50%...)` with `@media` breakpoints | Refactor to use `--grid-cols-*` vars; mode overrides the vars |
| `includes/config.php` | Loads env, defines DB/app constants — **no** session start, no mode logic | Add mode resolver after session is available (in `auth.php` instead, since it starts session) |
| `includes/auth.php` | Starts session, includes `config.php`, `security.php` — **loaded by every page** | Add mode resolver here (after session_start, before any output) |
| `breadcrumb.css` | `.bc { display: flex }` — **no** `--breadcrumb-show` var | Wire `display: var(--breadcrumb-show)` |
| Content filtering | `tiles.php` has `getSegments()`, `getSegmentTopics()` — **no mode-based filtering** | Add `getFilteredSegments($mode)` wrapper |
| `api/` directory | Has `avatar-lookup.php` only | Add `set-mode.php` |
| Inline head script | Neither `navbar.php` nor `navhome.php` has auto-detect JS | Add inline `<script>` block in `<head>` of both |
| Mode switcher UI | Does not exist | New HTML/CSS/JS in navbar area |

---

## New Files

| File | Purpose |
|------|---------|
| `htdocs/css/mode.css` | All mode-specific CSS: custom property overrides, mode switcher styles, transitions |
| `htdocs/api/set-mode.php` | POST endpoint — validates mode, sets session + cookie |
| `htdocs/js/mode-detect.js` | **NOT a file** — inline `<script>` in `<head>` to avoid extra HTTP request (perf budget) |
| `tests/e2e/fre14-mode-switch.spec.js` | Playwright tests for mode switching |

## Modified Files

| File | Changes |
|------|---------|
| `htdocs/includes/auth.php` | Add mode resolver (read cookie/session, set `$mode` global) |
| `htdocs/navbar.php` | Add `data-mode` to `<body>`, inline auto-detect `<script>`, mode switcher HTML, `<link>` to `mode.css` |
| `htdocs/navhome.php` | Same as navbar.php (both are `<html>` shells) |
| `htdocs/css/tiles.css` | Refactor grids to use CSS vars; remove hardcoded column counts from `@media` queries (mode overrides them) |
| `htdocs/css/breadcrumb.css` | Wire `.bc { display: var(--breadcrumb-show, flex) }` |
| `htdocs/includes/tiles.php` | Add `getFilteredSegments($mode)` — Kid mode returns only `early_learners` |
| `htdocs/index.php` | Use `getFilteredSegments($mode)` instead of `getSegments()` |
| `htdocs/browse.php` | Use `getFilteredSegments($mode)` for segment validation |

---

## Component Design

### PHP: Mode Resolver (in `auth.php`, after session start)

```php
// ── Mode Resolution ──────────────────────────────────────────
$GLOBALS['edupak_mode'] = 'group'; // default
$allowed_modes = ['kid', 'group', 'projector'];

if (!empty($_SESSION['mode']) && in_array($_SESSION['mode'], $allowed_modes, true)) {
    $GLOBALS['edupak_mode'] = $_SESSION['mode'];
} elseif (!empty($_COOKIE['edupak_mode']) && in_array($_COOKIE['edupak_mode'], $allowed_modes, true)) {
    $GLOBALS['edupak_mode'] = $_COOKIE['edupak_mode'];
    $_SESSION['mode'] = $GLOBALS['edupak_mode']; // promote to session
}

/**
 * Get current display mode.
 * @return string 'kid'|'group'|'projector'
 */
function getMode(): string {
    return $GLOBALS['edupak_mode'] ?? 'group';
}
```

**Why `$GLOBALS` not a constant**: Mode can change mid-request if `set-mode.php` is called (though unlikely in normal flow). Using a function keeps it testable.

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
$allowed = ['kid', 'group', 'projector'];

if (!in_array($mode, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid mode']);
    exit;
}

$_SESSION['mode'] = $mode;
setcookie('edupak_mode', $mode, [
    'expires'  => time() + 86400 * 30,
    'path'     => '/',
    'httponly'  => true,
    'samesite'  => 'Lax',
]);

echo json_encode(['ok' => true, 'mode' => $mode]);
```

### JS: Inline Auto-Detect (in `<head>`, before CSS paint)

```html
<script>
(function(){
  var c = document.cookie.match(/edupak_mode=([^;]+)/);
  if (c) { document.documentElement.dataset.mode = c[1]; return; }
  var w = Math.max(screen.width, screen.height);
  var s = Math.min(screen.width, screen.height);
  var m = (w >= 1280 && s >= 720) ? 'projector'
        : (s <= 414) ? 'kid'
        : 'group';
  document.documentElement.dataset.mode = m;
  document.cookie = 'edupak_mode=' + m + ';path=/;max-age=' + (86400*30);
})();
</script>
```

**Note**: Spec used `<= 360` for kid threshold. Changed to `<= 414` — iPhone 6/7/8 Plus is 414px, and most kid devices are phones in this range. The 360 cutoff would miss common phones.

**[ASSUMPTION]** 414px is the right kid threshold. Confirm against actual EduPak device inventory.

### JS: Mode Toggle Handler

```js
document.querySelectorAll('.mode-btn').forEach(function(btn) {
  btn.addEventListener('click', function() {
    var mode = this.dataset.mode;
    document.body.dataset.mode = mode;
    document.documentElement.dataset.mode = mode;
    document.cookie = 'edupak_mode=' + mode + ';path=/;max-age=' + (86400*30);

    // Sync session
    var fd = new FormData();
    fd.append('mode', mode);
    fetch('/api/set-mode.php', { method: 'POST', body: fd })
      .catch(function() {}); // swallow — cookie is already set

    // Update aria-pressed
    document.querySelectorAll('.mode-btn').forEach(function(b) {
      b.setAttribute('aria-pressed', b === btn ? 'true' : 'false');
    });
  });
});
```

### HTML: Mode Switcher (in navbar, right side)

```html
<div class="mode-switcher" role="group" aria-label="Display mode">
  <button class="mode-btn" data-mode="kid"
          aria-pressed="<?= getMode() === 'kid' ? 'true' : 'false' ?>">
    <span class="mode-btn__icon">&#x1F476;</span>
    <span class="mode-btn__label">Kid</span>
  </button>
  <button class="mode-btn" data-mode="group"
          aria-pressed="<?= getMode() === 'group' ? 'true' : 'false' ?>">
    <span class="mode-btn__icon">&#x1F465;</span>
    <span class="mode-btn__label">Group</span>
  </button>
  <button class="mode-btn" data-mode="projector"
          aria-pressed="<?= getMode() === 'projector' ? 'true' : 'false' ?>">
    <span class="mode-btn__icon">&#x1F4FD;</span>
    <span class="mode-btn__label">Class</span>
  </button>
</div>
```

**Mobile**: Collapse to a single icon button that opens a bottom sheet. Threshold: `@media (max-width: 576px)`.

### CSS: `mode.css`

```css
/* ── Mode Custom Properties ─────────────────────────────────── */
:root,
[data-mode="group"] {
  --font-scale:       1;
  --grid-cols-seg:    2;
  --grid-cols-topic:  2;
  --tile-gap:         12px;
  --btn-min-h:        44px;
  --nav-show:         flex;
  --contrast-bg:      #f5f5f5;
  --contrast-text:    #1a1a1a;
  --breadcrumb-show:  flex;
  --player-ctrl-h:    44px;
}

[data-mode="kid"] {
  --font-scale:       1.15;
  --grid-cols-seg:    2;
  --grid-cols-topic:  2;
  --tile-gap:         14px;
  --btn-min-h:        56px;
  --player-ctrl-h:    56px;
}

[data-mode="projector"] {
  --font-scale:       1.5;
  --grid-cols-seg:    3;
  --grid-cols-topic:  3;
  --tile-gap:         24px;
  --btn-min-h:        64px;
  --contrast-bg:      #000000;
  --contrast-text:    #ffffff;
  --breadcrumb-show:  none;
  --player-ctrl-h:    72px;
}

/* ── Apply properties ───────────────────────────────────────── */
body {
  font-size: calc(1rem * var(--font-scale, 1));
  background: var(--contrast-bg, #f5f5f5);
  color: var(--contrast-text, #1a1a1a);
  transition: background 0.3s, color 0.3s;
}

/* ── Projector overrides ────────────────────────────────────── */
[data-mode="projector"] .navbar {
  background: #111 !important;
  border-bottom: 1px solid #333;
}

[data-mode="projector"] .seg-tile,
[data-mode="projector"] .topic-tile {
  border: 2px solid rgba(255,255,255,0.2);
}

[data-mode="projector"] a,
[data-mode="projector"] .nav-link {
  color: #ffffff;
}

/* ── Mode Switcher ──────────────────────────────────────────── */
.mode-switcher {
  display: flex;
  align-items: center;
  gap: 2px;
  margin-left: 8px;
}

.mode-btn {
  all: unset;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 4px 8px;
  border-radius: 6px;
  font-size: 12px;
  color: rgba(255,255,255,0.6);
  transition: background 0.15s, color 0.15s;
}

.mode-btn:hover,
.mode-btn:focus-visible {
  background: rgba(255,255,255,0.1);
  color: #fff;
}

.mode-btn[aria-pressed="true"] {
  background: rgba(255,255,255,0.15);
  color: #fff;
  font-weight: 600;
}

.mode-btn__icon {
  font-size: 14px;
  line-height: 1;
}

.mode-btn__label {
  font-size: 11px;
}

/* Mobile: collapse mode switcher to icon-only trigger */
@media (max-width: 576px) {
  .mode-btn__label { display: none; }
  .mode-btn { padding: 6px; }
}

/* ── Smooth transitions between modes ───────────────────────── */
.seg-tile, .topic-tile, .btn, .name-card, .continue-card {
  transition: min-height 0.2s, padding 0.2s;
  min-height: var(--btn-min-h, 44px);
}
```

### CSS: tiles.css Refactor (grid columns → CSS vars)

**Before** (hardcoded):
```css
.seg-tile { flex: 1 1 calc(50% - var(--tile-gap)); }
@media (min-width: 900px) { .seg-tile { flex: 1 1 calc(33.333% - var(--tile-gap)); } }
```

**After** (mode-driven):
```css
.seg-grid  { gap: var(--tile-gap); }
.seg-tile  {
  flex: 1 1 calc(100% / var(--grid-cols-seg) - var(--tile-gap));
  max-width: calc(100% / var(--grid-cols-seg) - var(--tile-gap) * (var(--grid-cols-seg) - 1) / var(--grid-cols-seg));
}
```

**Problem**: `calc()` with `var()` division is not supported in older WebViews. EduPak targets low-spec Android devices.

**Safer approach**: Keep `@media` breakpoints for default Group mode, then let `[data-mode]` selectors override with explicit flex values:

```css
/* Group mode (default) — responsive breakpoints stay */
.seg-tile { flex: 1 1 calc(50% - 12px); max-width: calc(50% - 6px); }
@media (min-width: 900px) {
  .seg-tile { flex: 1 1 calc(33.333% - 12px); max-width: calc(33.333% - 8px); }
}

/* Kid mode — always 2 cols, bigger gap */
[data-mode="kid"] .seg-tile {
  flex: 1 1 calc(50% - 14px);
  max-width: calc(50% - 7px);
}
[data-mode="kid"] .topic-tile {
  flex: 1 1 calc(50% - 14px);
  max-width: calc(50% - 7px);
}

/* Projector — always 3 cols, big gap */
[data-mode="projector"] .seg-tile {
  flex: 1 1 calc(33.333% - 24px);
  max-width: calc(33.333% - 16px);
}
[data-mode="projector"] .topic-tile {
  flex: 1 1 calc(33.333% - 24px);
  max-width: calc(33.333% - 16px);
}
```

**This is the safer path for EduPak's device constraints.** The custom properties (`--font-scale`, `--contrast-bg`, etc.) still work everywhere — it's only the `calc(100% / var())` division that's risky.

### PHP: Content Filtering in `tiles.php`

```php
/**
 * Get segments filtered by current display mode.
 *
 * Kid mode: only 'early_learners'
 * Group/Projector: all segments (teacher content filtered by role, not mode)
 *
 * @param string $mode 'kid'|'group'|'projector'
 * @return array Filtered segments
 */
function getFilteredSegments(string $mode = 'group'): array {
    $all = getSegments();
    if ($mode === 'kid') {
        return array_intersect_key($all, array_flip(['early_learners']));
    }
    return $all;
}
```

**Note**: The spec mentions filtering `adult` and `teacher` segments in Kid mode, but current data has no `adult`/`teacher` segments — only `early_learners`, `explorers`, `advanced`, `educators`, `knowledge_power`. Kid mode showing only `early_learners` is the simplest meaningful filter. If more granular content rating is needed later, add a `content_rating` column to `content_segments`.

---

## Mode Comparison Table

| Property | Kid | Group (default) | Projector/Class |
|----------|-----|-----------------|-----------------|
| `--font-scale` | 1.15× | 1× | 1.5× |
| Segment grid cols | 2 | 2 (phone) / 3 (wide) | 3 always |
| Topic grid cols | 2 | 2 (phone) / 4 (wide) | 3 always |
| `--tile-gap` | 14px | 12px | 24px |
| `--btn-min-h` | 56px | 44px | 64px |
| Breadcrumb | Visible | Visible | Hidden |
| Background | #f5f5f5 | #f5f5f5 | #000000 |
| Text color | #1a1a1a | #1a1a1a | #ffffff |
| Content filter | `early_learners` only | All segments | All segments |
| Navbar | Standard | Standard | Dark bg, high contrast |
| Player controls | 56px height | 44px height | 72px height |

---

## Data Flow

No new DB tables. Mode lives in:
- **Cookie**: `edupak_mode` (30-day, device-scoped)
- **Session**: `$_SESSION['mode']` (per login session)

No migration needed.

---

## Edge Cases & Failure Modes

| Case | Handling |
|------|----------|
| Cookie blocked by browser | `group` default on every load; JS auto-detect re-runs each time |
| Unknown mode value in cookie (`projector2`) | PHP allowlist rejects → falls back to `group` |
| Mode switch mid-video playback | CSS transitions only affect chrome; player iframe/element untouched |
| Kid opens app on projector-size screen | Auto-detects `projector`; adult can tap Kid mode to switch |
| Projector device used by kid account | Mode is device-scoped — stays projector. Student can manually switch. |
| `set-mode.php` POST fails | JS swallows error; cookie already set so next load is correct |
| No JS (feature phone) | PHP sets `data-mode` from cookie/session on `<body>`. CSS applies. Auto-detect doesn't fire — defaults to `group` (safe). |
| Stale session after server restart | Cookie persists; PHP reads cookie and promotes to new session |
| Mode switcher in collapsed mobile navbar | Mode buttons visible in collapsed hamburger menu. Alternative: show mode icon in the always-visible navbar area. |
| `font-size: calc(1rem * 1.5)` on very old WebView | `calc()` with multiplication is well-supported (Android 5+). Safe. |

---

## Test Plan

| # | Test | Expected |
|---|------|----------|
| T1 | Load on 1920×1080 (no cookie) | Auto-detects `projector`: dark bg, large text, 3-col grid |
| T2 | Load on 375px phone (no cookie) | Auto-detects `kid`: 2-col, bigger buttons |
| T3 | Load on 768px tablet (no cookie) | Auto-detects `group`: standard layout |
| T4 | Click Kid mode button | `data-mode="kid"` on body; tiles enlarge; font scales up |
| T5 | Click Projector mode button | Dark bg, white text, breadcrumb hidden, large controls |
| T6 | Refresh after switching to Kid | Mode persists (cookie read by both JS and PHP) |
| T7 | Switch user (login as different student) in Kid mode | Mode stays Kid (device-scoped) |
| T8 | Kid mode: browse segments | Only `early_learners` segment shown |
| T9 | Kid mode: direct URL to `browse.php?seg=advanced` | Redirects to `/` (segment not in filtered set) |
| T10 | Projector mode: breadcrumb hidden | `.bc { display: none }` via `--breadcrumb-show` var |
| T11 | Set cookie to `invalid_mode` manually | PHP rejects → falls back to `group` |
| T12 | Mode switch while video playing | Player uninterrupted; surrounding UI updates |
| T13 | `aria-pressed` on mode buttons | Screen reader announces current mode correctly |
| T14 | Mobile (576px): mode switcher | Labels hidden, icon-only buttons |
| T15 | JS disabled: load with existing cookie | PHP reads cookie → `data-mode` set server-side → CSS applies |
| T16 | Projector → Kid → Group rapid switching | No layout jank, transitions smooth |

---

## Task Breakdown

| # | Task | Est | Depends |
|---|------|-----|---------|
| 1 | `mode.css`: all mode custom properties, switcher styles, projector overrides, transitions | 0.75d | — |
| 2 | Mode resolver in `auth.php`: read cookie/session, set `$mode`, expose `getMode()` | 0.25d | — |
| 3 | `api/set-mode.php`: POST endpoint, validate, set session + cookie | 0.25d | 2 |
| 4 | Inline auto-detect `<script>` + `data-mode` attr in `navbar.php` + `navhome.php` | 0.5d | 2 |
| 5 | Mode switcher HTML/JS in `navbar.php` + `navhome.php` | 0.5d | 1, 3, 4 |
| 6 | `tiles.css` refactor: mode-specific grid overrides for `[data-mode]` selectors | 0.5d | 1 |
| 7 | `breadcrumb.css`: wire `display: var(--breadcrumb-show)` | 0.15d | 1 |
| 8 | `tiles.php`: add `getFilteredSegments($mode)` | 0.25d | 2 |
| 9 | Update `index.php` + `browse.php` to use `getFilteredSegments()` | 0.25d | 8 |
| 10 | Projector mode polish: navbar dark theme, tile borders, oversized player controls | 0.5d | 1, 6 |
| 11 | Mobile mode switcher: icon-only collapse / bottom sheet | 0.25d | 5 |
| 12 | Playwright tests (`fre14-mode-switch.spec.js`) | 0.75d | all |
| **Total** | | **~4.9d** | |

### Suggested Order
1 → 2 → 3 → 4 → 5 → 6 → 7 (parallel with 8 → 9) → 10 → 11 → 12

Tasks 1, 2, and 8 have no dependencies and can start in parallel.

---

## Risks & Open Items

### Risks
| Risk | Impact | Mitigation |
|------|--------|------------|
| Old Android WebView doesn't support `calc()` with CSS var division | Projector/Kid grids break | Use explicit flex values per `[data-mode]` selector (no `calc(100%/var())`) — **already planned above** |
| Navbar duplication (`navbar.php` + `navhome.php`) makes changes error-prone | Miss adding mode switcher to one | Tasks 4 + 5 explicitly modify both files; consider extracting shared `<head>` partial in a follow-up |
| Bootstrap CSS conflicts with `--contrast-bg` / body background overrides | Projector mode looks broken | Scope projector overrides with `[data-mode="projector"]` specificity; test on actual Bootstrap theme |
| Cookie `httponly` flag blocks JS from reading it for auto-detect | Mode not detected by inline script | Set `httponly: false` on the mode cookie (it's not sensitive data) — OR let JS always write its own non-httponly cookie |

### Open Items (Non-Blocking)
1. **Kid threshold**: Spec says 360px, plan uses 414px. Confirm against EduPak device list.
2. **Projector cols**: Spec says 4, plan uses 3 (more readable). Confirm with stakeholders.
3. **Teacher tools visibility**: Spec says "hide teacher tools in kid + projector." Currently, teacher tools are role-gated via `isTeacher()`. Mode switch shouldn't change this — teacher role check is orthogonal. Verify this assumption.
4. **`httponly` cookie conflict**: The inline JS script reads the cookie to set `data-mode`. If `set-mode.php` sets `httponly: true`, the JS read will fail on subsequent loads. **Fix**: Use `httponly: false` for `edupak_mode` since the value is non-sensitive (kid/group/projector).

### Blocking Question
**Does the mode cookie need to be `httponly`?** The inline auto-detect script reads it via `document.cookie`. If we set `httponly: true` in `set-mode.php`, the JS fallback breaks on the next page load. Recommend `httponly: false` since the value is just a display preference, not auth data.
