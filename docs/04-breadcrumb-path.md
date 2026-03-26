# Breadcrumb Path

## Summary
- Persistent breadcrumb bar shows the user's current location in the content hierarchy: `Home > Math > Video 3`, with an SVG icon at each level
- Built server-side in PHP from URL parameters; no JS required for render
- Each crumb is a tappable link with a 44px minimum touch target so users can jump back multiple levels in one tap
- Integrates directly with the URL structure from Spec 01 (Visual Home Tiles)

## User Story
> As a **low-literacy learner** deep in the content hierarchy, I want to see where I am and easily return to a previous level (e.g., the topic list) without pressing the browser back button, because I might have arrived via an external link or the continue-watching row.

> As a **teacher** browsing videos to build a lesson plan, I want a clear path back to the topic list so I can quickly pivot to another subject without starting over from the home screen.

## Technical Approach

### Frontend (HTML/CSS/JS)

**HTML component:**
```html
<nav class="breadcrumb" aria-label="Page location">
  <ol class="breadcrumb__list">
    <li class="breadcrumb__item">
      <a class="breadcrumb__link" href="/">
        <svg class="breadcrumb__icon" aria-hidden="true"><use href="/assets/icons/sprite.svg#home"/></svg>
        <span class="breadcrumb__label">Home</span>
      </a>
    </li>
    <li class="breadcrumb__item breadcrumb__item--sep" aria-hidden="true">›</li>
    <li class="breadcrumb__item">
      <a class="breadcrumb__link" href="/browse.php?seg=kid">
        <svg class="breadcrumb__icon" aria-hidden="true"><use href="/assets/icons/sprite.svg#kid"/></svg>
        <span class="breadcrumb__label">Kid</span>
      </a>
    </li>
    <li class="breadcrumb__item breadcrumb__item--sep" aria-hidden="true">›</li>
    <li class="breadcrumb__item">
      <a class="breadcrumb__link" href="/browse.php?seg=kid&topic=math">
        <svg class="breadcrumb__icon" aria-hidden="true"><use href="/assets/icons/sprite.svg#math"/></svg>
        <span class="breadcrumb__label">Math</span>
      </a>
    </li>
    <li class="breadcrumb__item breadcrumb__item--sep" aria-hidden="true">›</li>
    <li class="breadcrumb__item breadcrumb__item--current" aria-current="page">
      <svg class="breadcrumb__icon" aria-hidden="true"><use href="/assets/icons/sprite.svg#video"/></svg>
      <span class="breadcrumb__label">Intro to Fractions</span>
    </li>
  </ol>
</nav>
```

**CSS:**
```css
.breadcrumb {
  background: #f8f8f8;
  border-bottom: 1px solid #e0e0e0;
  padding: 0 8px;
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
  white-space: nowrap;
  scrollbar-width: none;
}
.breadcrumb::-webkit-scrollbar { display: none; }

.breadcrumb__list {
  display: flex;
  align-items: center;
  list-style: none;
  margin: 0; padding: 0;
  gap: 4px;
}

.breadcrumb__link {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 10px 6px;       /* ensures 44px height with line-height */
  min-height: 44px;        /* WCAG 2.5.5 touch target */
  text-decoration: none;
  color: #555;
  font-size: clamp(0.75rem, 2vw, 0.9rem);
  border-radius: 4px;
}
.breadcrumb__link:hover,
.breadcrumb__link:focus { color: #222; background: #eee; }

.breadcrumb__item--current {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 10px 6px;
  min-height: 44px;
  font-weight: 600;
  color: #222;
  font-size: clamp(0.75rem, 2vw, 0.9rem);
  /* Long title truncation */
  max-width: 180px;
}
.breadcrumb__item--current .breadcrumb__label {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  display: block;
  max-width: 160px;
}

.breadcrumb__item--sep {
  color: #aaa;
  font-size: 0.8rem;
  user-select: none;
}

.breadcrumb__icon {
  width: 16px; height: 16px;
  flex-shrink: 0;
}

/* Projector / large screen: bigger everything */
@media (min-width: 1024px) {
  .breadcrumb__link,
  .breadcrumb__item--current { font-size: 1rem; min-height: 52px; }
  .breadcrumb__icon          { width: 20px; height: 20px; }
}
```

**Icon system:**

All icons are SVG symbols compiled into a single sprite file: `/assets/icons/sprite.svg`.

| Symbol ID | Used for |
|-----------|----------|
| `#home` | Home crumb |
| `#kid` | Kid segment |
| `#teen` | Teen segment |
| `#adult` | Adult segment |
| `#teacher` | Teacher segment |
| `#math` | Math topic |
| `#science` | Science topic |
| `#health` | Health topic |
| `#farming` | Farming topic |
| `#video` | Video leaf page |
| `#article` | Article leaf page |
| `#quiz` | Quiz leaf page |

**[ASSUMPTION]** One icon per category is sufficient. Icons are 24×24px SVG, designed at 16px minimum display size for legibility on low-DPI screens.

### Backend (PHP/MySQL)

**Server-side breadcrumb builder** — included as a shared PHP function (e.g., in `/includes/breadcrumb.php`):

```php
/**
 * Builds breadcrumb data from current URL parameters.
 * Returns array of ['label' => string, 'icon' => string, 'href' => string|null]
 * Last item has href = null (current page, not a link)
 */
function buildBreadcrumb(array $params, array $tileConfig): array {
    $crumbs = [
        ['label' => 'Home', 'icon' => 'home', 'href' => '/']
    ];

    $seg   = $params['seg']   ?? null;
    $topic = $params['topic'] ?? null;
    $vid   = $params['id']    ?? null;

    if ($seg && isset($tileConfig['segments'][$seg])) {
        $segData = $tileConfig['segments'][$seg];
        $crumbs[] = [
            'label' => $segData['label'],
            'icon'  => $seg,
            'href'  => $topic || $vid ? "/browse.php?seg={$seg}" : null,
        ];
    }

    if ($seg && $topic && isset($tileConfig['segments'][$seg]['topics'][$topic])) {
        $topicData = $tileConfig['segments'][$seg]['topics'][$topic];
        $crumbs[] = [
            'label' => $topicData['label'],
            'icon'  => $topic,
            'href'  => $vid ? "/browse.php?seg={$seg}&topic={$topic}" : null,
        ];
    }

    if ($vid) {
        // Fetch video title — from tiles.json or a lightweight DB query
        $title = getContentTitle($vid); // returns string
        $crumbs[] = [
            'label' => $title,
            'icon'  => 'video',
            'href'  => null,   // current page
        ];
    }

    return $crumbs;
}
```

**Rendering the breadcrumb (in shared header template):**
```php
$crumbs = buildBreadcrumb($_GET, getTileConfig());
include __DIR__ . '/includes/breadcrumb.html.php';
```

**`includes/breadcrumb.html.php`:**
```php
<nav class="breadcrumb" aria-label="Page location">
  <ol class="breadcrumb__list">
    <?php foreach ($crumbs as $i => $crumb): ?>
      <?php $isLast = ($i === count($crumbs) - 1); ?>
      <?php if ($i > 0): ?>
        <li class="breadcrumb__item breadcrumb__item--sep" aria-hidden="true">›</li>
      <?php endif; ?>
      <li class="breadcrumb__item <?= $isLast ? 'breadcrumb__item--current' : '' ?>"
          <?= $isLast ? 'aria-current="page"' : '' ?>>
        <?php if (!$isLast && $crumb['href']): ?>
          <a class="breadcrumb__link" href="<?= htmlspecialchars($crumb['href']) ?>">
            <svg class="breadcrumb__icon" aria-hidden="true">
              <use href="/assets/icons/sprite.svg#<?= htmlspecialchars($crumb['icon']) ?>"/>
            </svg>
            <span class="breadcrumb__label"><?= htmlspecialchars($crumb['label']) ?></span>
          </a>
        <?php else: ?>
          <svg class="breadcrumb__icon" aria-hidden="true">
            <use href="/assets/icons/sprite.svg#<?= htmlspecialchars($crumb['icon']) ?>"/>
          </svg>
          <span class="breadcrumb__label"><?= htmlspecialchars($crumb['label']) ?></span>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ol>
</nav>
```

### Data Model

No dedicated DB table required. Breadcrumb data is derived from:
1. URL query parameters (`seg`, `topic`, `id`)
2. `tiles.json` config (labels and icons per segment/topic) — shared with Spec 01
3. A lightweight content title lookup (DB or flat index) for the leaf-level video/article title

**URL structure:**

| Page | URL | Breadcrumb |
|------|-----|-----------|
| Home | `/` | Home |
| Segment | `/browse.php?seg=kid` | Home › Kid |
| Topic | `/browse.php?seg=kid&topic=math` | Home › Kid › Math |
| Video | `/player.php?seg=kid&topic=math&id=V042` | Home › Kid › Math › Intro to Fractions |
| Teacher tools | `/teacher.php` | Home › Teacher |
| Lesson plan | `/teacher.php?plan=15` | Home › Teacher › My Lesson Plan |

**[ASSUMPTION]** `seg` and `topic` are always passed as query params to the player page. If the player is reached via the Continue Watching row (which may only carry `id`), the breadcrumb falls back to "Home › [Video Title]" — partial path only.

## UI/UX Specification

| Element | Spec |
|---------|------|
| Bar height | 44px minimum (touch target compliance) |
| Separator | `›` character, `aria-hidden` |
| Icon size | 16px display, 24px asset |
| Label max-width | 160px on current (last) crumb, with ellipsis |
| Horizontal scroll | Bar scrolls left on overflow (deep nesting); most-recent crumb always visible |
| Sticky behavior | **[ASSUMPTION]** Breadcrumb is pinned below the app header (`position: sticky; top: 56px`) |
| Projector mode | Font 1rem, min-height 52px, icons 20px |
| Background | `#f8f8f8` — subtle, not competing with content |

## Edge Cases & Failure Modes

| Case | Handling |
|------|----------|
| `seg` param present but not in `tiles.json` | Skip segment crumb; show "Home › [Video Title]" |
| Very long video title (80+ chars) | `max-width: 160px` + `text-overflow: ellipsis`; full title in `title` attribute |
| 5+ levels of nesting (hypothetical deep subcategories) | Bar scrolls horizontally; all crumbs remain navigable |
| Player reached from Continue Watching (no `seg`/`topic` in URL) | Breadcrumb shows "Home › [Video Title]" — accept partial path |
| `id` present but `getContentTitle()` returns null | Show "Video" as fallback label with generic video icon |
| Icon symbol not found in sprite | SVG `<use>` renders nothing; label still visible — graceful degradation |
| JavaScript disabled | Breadcrumb is fully server-rendered; no JS dependency |
| `htmlspecialchars` missed on output | All outputs are escaped via `htmlspecialchars()` — no XSS surface |

## Test Plan

| # | Test | Expected |
|---|------|----------|
| T1 | Load `/` | Breadcrumb shows "Home" only (single item, no links) |
| T2 | Load `/browse.php?seg=kid` | "Home › Kid" — "Home" is a link |
| T3 | Load `/browse.php?seg=kid&topic=math` | "Home › Kid › Math" — first two are links |
| T4 | Load `/player.php?seg=kid&topic=math&id=V042` | "Home › Kid › Math › Intro to Fractions" — first three are links |
| T5 | Tap "Kid" crumb from player page | Navigates to `/browse.php?seg=kid` |
| T6 | Tap "Home" crumb | Navigates to `/` |
| T7 | Video title is 100 chars long | Last crumb truncates with ellipsis; `title` attr shows full title |
| T8 | Unknown `seg=xyz` in URL | Breadcrumb shows "Home" only; no PHP error |
| T9 | Load on 320px phone | Breadcrumb scrolls horizontally; most-recent crumb visible without scroll |
| T10 | Load on projector (1024px+) | Larger font (1rem), larger icons (20px) |
| T11 | All breadcrumb links via keyboard tab | Each link receives focus in order; Enter activates |
| T12 | `seg=<script>alert(1)</script>` in URL | Output escaped; no XSS |

## Dependencies
- **Spec 01 (Visual Home Tiles):** `tiles.json` is the source of segment/topic labels and icon names — must be loaded before breadcrumb builder runs
- **Spec 06 (Mode Switch):** projector mode increases breadcrumb font/height
- Content title lookup function: needs agreement on whether title comes from `tiles.json`, a DB table, or a filename-derived string
- SVG icon sprite: design team must deliver `sprite.svg` with all required symbol IDs

## Estimated Effort

| Task | Estimate |
|------|----------|
| PHP breadcrumb builder function | 0.75 days |
| HTML/CSS breadcrumb component | 0.75 days |
| SVG icon sprite (basic set) | 0.5 days |
| Integration into all page templates | 0.5 days |
| Edge case handling + URL validation | 0.5 days |
| Test + bug fix | 0.5 days |
| **Total** | **3.5 days** |

## Open Questions
1. **[BLOCKING]** Is there a central content registry (DB table or JSON index) that maps `content_id → title`? Needed for the leaf crumb label.
2. **[BLOCKING]** What is the canonical URL pattern for the player page? Does it always receive `seg` + `topic` + `id`, or only `id`?
3. Should breadcrumb crumbs be logged as navigation events (for analytics)?
4. Do subcategories exist within topics (e.g., Kid > Math > Fractions > Video)? If yes, the builder needs to handle 5+ levels.
5. Should the breadcrumb be hidden in Kid mode or projector mode (Spec 06) to reduce visual clutter?
