# Mode Switch

## Summary
- Three layout modes — **Kid**, **Group** (family/informal use), and **Class/Projector** — adjust UI density, font size, touch target size, and content filtering across the entire app
- Mode is **auto-detected** from screen dimensions on first load, then persisted in the PHP session and a device-scoped cookie; users can manually override at any time
- CSS custom properties (`--font-scale`, `--grid-cols`, etc.) drive all mode-specific styling from a single declaration block — no duplicate stylesheets
- Projector mode enforces high-contrast, large text, and simplified navigation for shared-screen classroom display

## User Story
> As a **teacher projecting EduPak on a classroom screen**, I want the interface to automatically show large text and simplified navigation so students at the back of the room can read it clearly.

> As a **parent using EduPak with a young child**, I want Kid mode to show larger tiles and filter out content not appropriate for children, without needing to configure anything.

> As a **student on a personal phone in Group mode**, I want a denser layout that fits more content on screen at once, since I can hold the device close.

## Technical Approach

### Frontend (HTML/CSS/JS)

**CSS custom property approach — single source of truth:**

```css
/* --- Base (Group mode defaults) --- */
:root {
  --font-scale:       1;
  --grid-cols-seg:    2;
  --grid-cols-topic:  3;
  --tile-gap:         10px;
  --btn-min-h:        44px;
  --nav-show:         flex;
  --content-filter:   'all';        /* informational only; filtering is PHP-side */
  --contrast-bg:      #ffffff;
  --contrast-text:    #1a1a1a;
  --breadcrumb-show:  flex;
}

/* --- Kid mode --- */
[data-mode="kid"] {
  --font-scale:       1.15;
  --grid-cols-seg:    2;
  --grid-cols-topic:  2;
  --tile-gap:         14px;
  --btn-min-h:        56px;
}

/* --- Projector / Class mode --- */
[data-mode="projector"] {
  --font-scale:       1.5;
  --grid-cols-seg:    4;
  --grid-cols-topic:  4;
  --tile-gap:         24px;
  --btn-min-h:        64px;
  --contrast-bg:      #000000;
  --contrast-text:    #ffffff;
  --breadcrumb-show:  none;          /* hide breadcrumb — too small to read at distance */
}

/* Apply custom properties throughout */
body {
  font-size: calc(1rem * var(--font-scale));
  background: var(--contrast-bg);
  color: var(--contrast-text);
}
.tile-grid--segments { grid-template-columns: repeat(var(--grid-cols-seg), 1fr); gap: var(--tile-gap); }
.tile-grid--topics   { grid-template-columns: repeat(var(--grid-cols-topic), 1fr); gap: var(--tile-gap); }
.btn, .name-card, .continue-card { min-height: var(--btn-min-h); }
.breadcrumb          { display: var(--breadcrumb-show); }
```

**Mode applied via `data-mode` attribute on `<body>` — set by PHP:**
```html
<body data-mode="<?= htmlspecialchars($mode) ?>">
```

**Auto-detect logic (JS, runs before first paint via inline `<script>` in `<head>`):**
```html
<script>
(function() {
  // 1. Honor stored preference first
  var stored = document.cookie.match(/edupak_mode=([^;]+)/);
  if (stored) {
    document.documentElement.dataset.mode = stored[1];
    return;
  }
  // 2. Auto-detect from screen dimensions
  var w = window.screen.width, h = window.screen.height;
  var longer = Math.max(w, h), shorter = Math.min(w, h);
  var mode;
  if (longer >= 1280 && shorter >= 720) {
    mode = 'projector';       // wide display ≥ 1280px
  } else if (shorter <= 360) {
    mode = 'kid';             // narrow phone ≤ 360px
  } else {
    mode = 'group';           // default
  }
  document.documentElement.dataset.mode = mode;
  // Persist as cookie (30-day expiry) so PHP picks it up on next request
  document.cookie = 'edupak_mode=' + mode + ';path=/;max-age=' + (86400*30);
})();
</script>
```

**[ASSUMPTION]** The inline script runs before CSS paint, preventing a flash-of-wrong-mode. PHP also reads the cookie server-side to set `data-mode` on `<body>`, so the attribute is present even before JS runs.

**Mode toggle UI (persistent, always accessible):**
```html
<div class="mode-switcher" role="group" aria-label="Display mode">
  <button class="mode-btn" data-mode="kid"       aria-pressed="false">👶 Kid</button>
  <button class="mode-btn" data-mode="group"     aria-pressed="true">👥 Group</button>
  <button class="mode-btn" data-mode="projector" aria-pressed="false">📽 Class</button>
</div>
```

```js
document.querySelectorAll('.mode-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    const mode = this.dataset.mode;
    document.body.dataset.mode = mode;
    // Persist
    document.cookie = 'edupak_mode=' + mode + ';path=/;max-age=' + (86400*30);
    // Sync server-side session via background fetch
    fetch('/api/set_mode.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: 'mode=' + encodeURIComponent(mode)
    });
    // Update aria-pressed
    document.querySelectorAll('.mode-btn').forEach(b =>
      b.setAttribute('aria-pressed', b === this ? 'true' : 'false'));
  });
});
```

### Backend (PHP/MySQL)

**Mode resolution order** (each step overrides the previous):
1. PHP session value (`$_SESSION['mode']`)
2. Request cookie (`$_COOKIE['edupak_mode']`)
3. Auto-detect from `User-Agent` / screen hint (not reliable — defer to JS)
4. Default: `'group'`

**Mode resolver (in shared `init.php`):**
```php
$allowed_modes = ['kid', 'group', 'projector'];
$mode = 'group'; // default

if (!empty($_SESSION['mode']) && in_array($_SESSION['mode'], $allowed_modes)) {
    $mode = $_SESSION['mode'];
} elseif (!empty($_COOKIE['edupak_mode']) && in_array($_COOKIE['edupak_mode'], $allowed_modes)) {
    $mode = $_COOKIE['edupak_mode'];
    $_SESSION['mode'] = $mode; // promote cookie to session
}
```

**`api/set_mode.php` — POST endpoint for JS sync:**
```php
session_start();
$mode = filter_input(INPUT_POST, 'mode', FILTER_SANITIZE_SPECIAL_CHARS);
$allowed = ['kid', 'group', 'projector'];
if (in_array($mode, $allowed)) {
    $_SESSION['mode'] = $mode;
    setcookie('edupak_mode', $mode, time() + 86400 * 30, '/', '', false, true);
    echo json_encode(['ok' => true]);
} else {
    http_response_code(400);
    echo json_encode(['error' => 'invalid mode']);
}
```

**Content filtering by mode:**  
Kid mode filters out content tagged `adult` or `teacher` segment from browse and search results.

```php
// In browse.php and search API:
if ($mode === 'kid') {
    $allowed_segments = ['kid'];
} elseif ($mode === 'group') {
    // Show all except teacher-only
    $allowed_segments = ['kid', 'teen', 'adult'];
} else { // projector / class
    $allowed_segments = ['kid', 'teen', 'adult']; // same as group; teacher content separate
}
```

**[ASSUMPTION]** Content filtering is based on `segment` field — not a separate `age_rating` field. If a more granular rating system is needed, add a `content_rating` column to `content_meta`.

### Data Model

No new DB table. Mode state lives in:
- **PHP session:** `$_SESSION['mode']` (per login session)
- **Cookie:** `edupak_mode` (per browser/device, 30-day expiry)

**[ASSUMPTION]** We do NOT store mode preference per user in the `users` DB table for v1. Mode is device-scoped (cookie), not user-scoped. Rationale: a projector device should always stay in projector mode regardless of which user logs in. If per-user mode preference is needed later, add `preferred_mode ENUM('kid','group','projector')` to `users`.

## UI/UX Specification

### Mode Comparison Table

| Property | Kid | Group (default) | Projector/Class |
|----------|-----|-----------------|-----------------|
| Font scale | 1.15× | 1× | 1.5× |
| Segment grid columns | 2 | 2 (phone) / 4 (wide) | 4 always |
| Topic grid columns | 2 | 3 (phone) / 4 (wide) | 4 always |
| Button min-height | 56px | 44px | 64px |
| Breadcrumb | Visible | Visible | Hidden |
| Background | White | White | Black |
| Text color | Dark | Dark | White |
| Content filter | Kids only | All except teacher | All except teacher |
| Nav complexity | Simplified (no teacher tools) | Standard | Simplified (no teacher tools) |
| Mode switcher visibility | Shown (for adult to change) | Shown | Shown |

### Projector mode specifics
- Background `#000`, text `#fff`, tile borders `2px solid #fff`
- No breadcrumb nav (text too small for projection distance)
- Segment and topic tiles: white label on dark card, icon prominent
- Player controls: oversized (height 72px), high-contrast
- **[ASSUMPTION]** Projector mode activates automatically when width ≥ 1280px and no user cookie override exists

### Mode switcher placement
- **[ASSUMPTION]** Mode switcher lives in the app header, right-aligned, always visible
- On mobile, collapse to a single icon button that opens a bottom sheet with 3 mode options
- On projector mode, show switcher prominently so a teacher can hand off to student mode

## Edge Cases & Failure Modes

| Case | Handling |
|------|----------|
| Cookie blocked by browser | Mode defaults to `group` on every page load; JS auto-detect re-applies per load |
| User switches mode mid-session | `data-mode` on `<body>` updated immediately via JS; CSS transitions for smooth change; PHP session updated async via `fetch` |
| Projector device used by a student with the Kid account | Mode is device-scoped — projector stays in projector mode. Student can manually switch to Kid mode if desired. |
| `set_mode.php` POST fails (network error — impossible offline?) | **[ASSUMPTION]** On a local WiFi server, POST should always succeed. If not, JS swallows error; cookie already set so next page load reflects mode. |
| Unknown mode value in cookie (e.g., `projector2`) | PHP allowlist rejects it; falls back to `group` |
| Kid opens app on projector screen | Auto-detect fires `projector` mode; an adult can tap Kid mode button to switch |
| Mode switch while video is playing | Mode change does not interrupt the player; UI around player updates but player iframe/element untouched |
| No JS (feature phone) | `data-mode` not set by JS; PHP sets it from cookie/session; CSS still applies correctly |

## Test Plan

| # | Test | Expected |
|---|------|----------|
| T1 | Load app on 1920×1080 screen (no cookie) | Auto-detects projector mode: large text, dark background |
| T2 | Load app on 320px phone (no cookie) | Auto-detects kid mode: 2-column grid, large buttons |
| T3 | Load app on 768px tablet (no cookie) | Auto-detects group mode: standard layout |
| T4 | Manually switch to Kid mode via toggle button | `data-mode="kid"` on body; grid collapses to 2 cols; font increases |
| T5 | Kid mode: browse content | Adult/teacher-tagged content not shown |
| T6 | Refresh page after switching to Kid mode | Mode persists (cookie + session) |
| T7 | Switch user (Spec 03) while in Kid mode | Mode remains kid (device-scoped cookie) |
| T8 | Projector mode content filter | Adult segment content not shown |
| T9 | Switch to Projector mode on 320px phone | Manual override works; dark background, large font |
| T10 | Set cookie to `projector2` manually | PHP rejects; falls back to `group` |
| T11 | Open app with JS disabled | PHP sets `data-mode` from cookie; CSS mode styles still apply |
| T12 | Switch mode while video playing | Player uninterrupted; surrounding UI updates |
| T13 | Projector mode: verify breadcrumb hidden | `.breadcrumb { display: none }` via CSS var |
| T14 | Accessibility: mode buttons have `aria-pressed` | Screen reader announces current mode |

## Dependencies
- **Spec 01 (Visual Home Tiles):** tile grid column counts must reference `--grid-cols-seg` and `--grid-cols-topic` CSS vars
- **Spec 03 (Simple Name Login):** session must be initialized before mode resolver runs (`init.php` load order matters)
- **Spec 04 (Breadcrumb):** breadcrumb visibility controlled by `--breadcrumb-show` CSS var
- **Spec 05 (Teacher Content Finder):** teacher tools must remain hidden in kid and projector modes (role check, not mode check — these are orthogonal)
- All page templates: must include `init.php` (mode resolver) and apply `data-mode` to `<body>`

## Estimated Effort

| Task | Estimate |
|------|----------|
| CSS custom properties + mode declarations | 1 day |
| PHP mode resolver + `init.php` integration | 0.5 days |
| JS auto-detect + mode toggle UI | 0.75 days |
| `api/set_mode.php` endpoint | 0.25 days |
| Content filtering by mode (PHP browse/search) | 0.5 days |
| Projector mode specifics (high contrast, player controls) | 0.75 days |
| Apply to all page templates | 0.5 days |
| Test + bug fix | 1 day |
| **Total** | **5.25 days** |

## Open Questions
1. **[BLOCKING]** Should mode be per-device (cookie) or per-user (DB)? Decision affects architecture — document above assumes per-device.
2. **[BLOCKING]** Is there a fourth mode needed? (e.g., "Assessment mode" that hides navigation and locks to one quiz?) If so, add to ENUM now.
3. Should Kid mode also filter by content complexity/difficulty, or purely by `segment = 'kid'`?
4. Should the mode switcher be hidden from students (only accessible to teachers), or freely available to all users?
5. In projector mode, should the URL be shareable as "projector mode" (query param `?mode=projector`) so a teacher can bookmark it?
