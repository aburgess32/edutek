# FRE-12 Pre-Dev Plan: Breadcrumb Path

## Scope Summary

Bottom-pinned floating breadcrumb showing **3 levels max**: Category (segment) > Sub-category (topic) > Current Video. Always visible, server-rendered, minimal JS (toggle only). No background bar — just subtle left-justified text with chevron arrow separators. Includes a small collapse/expand toggle button in the bottom-left corner so users can hide the breadcrumb when it's in the way.

### Key Decisions (Locked)
- **3 levels only**: Category > Sub-category > Current Video — no "Home" crumb
- **Bottom-pinned**: `position: fixed; bottom: 0` — always visible
- **No bar/background** — floating text directly on the page surface
- **Left-justified** with 12px left padding
- **SVG chevron arrows** as separators (not text characters)
- **Server-rendered**: PHP builds crumb array from URL params + `tiles.json`
- **Collapse/expand toggle**: Small icon button, bottom-left corner, hides/shows breadcrumb trail
- **Graceful fallback**: Missing params → shorter trail; missing title → "Video" generic

### Changes from Original Spec
- ~~Home crumb~~ → removed (3 levels is sufficient)
- ~~Top sticky~~ → **bottom-fixed**
- ~~5+ level nesting~~ → capped at 3
- ~~Dark bar background~~ → no bar, no background
- ~~Centered text~~ → left-justified
- ~~Text `›` separator~~ → inline SVG chevron arrows
- ~~SVG sprite icons per crumb~~ → removed (color dots only)
- ~~Always visible, no dismiss~~ → **collapsible** via toggle button

---

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────┐
│  URL params (seg, topic, id)                                 │
│         │                                                    │
│         ▼                                                    │
│  includes/breadcrumb.php                                     │
│  buildBreadcrumb($_GET, getTileConfig())                     │
│  → returns max 3 items: [category, subcategory, current]     │
│         │                                                    │
│         ▼                                                    │
│  includes/breadcrumb.html.php                                │
│  Renders fixed-bottom <nav> (no bar, floating text)          │
│         │                                                    │
│  Included by: browse.php, watch.php, directory.php,          │
│  tutorials.php, audiobooks.php, listen.php, readpdf.php      │
└─────────────────────────────────────────────────────────────┘

Visual:
┌──────────────────────────────────────┐
│  [navbar - fixed top]                │
│                                      │
│        page content                  │
│                                      │
│                                      │
│                                      │
│                                      │
│ Early Learners > Math > Fractions    │  ← fixed bottom, no bar
└──────────────────────────────────────┘
  ^ left-justified, floating text
  ^ chevron arrows between crumbs
  ^ 4px color dots per segment
```

### URL → Breadcrumb Mapping

| Page | URL | Breadcrumb |
|------|-----|------------|
| Home | `/` | (no breadcrumb) |
| Segment | `/browse.php?seg=early_learners` | Early Learners |
| Topic | `/browse.php?seg=early_learners&topic=primary-multiplication` | Early Learners > Primary Multiplication |
| Video | `/watch.php?...&seg=early_learners&topic=primary-multiplication` | Early Learners > Primary Multiplication > Intro to Fractions |
| Directory | `/directory.php` | Directory |
| Deep link (id only) | `/watch.php?id=V042` | [Video Title] (single crumb) |

---

## Visual Design

### Typography & Color
- **Font size**: 10px desktop, 11px on touch devices (`@media (pointer: coarse)`)
- **Link crumbs**: `rgba(0,0,0,0.3)` — very subtle
- **Current crumb**: `rgba(0,0,0,0.5)` — slightly bolder
- **Hover state**: `rgba(0,0,0,0.55)`
- **Chevron arrows**: `rgba(0,0,0,0.15)` — barely visible
- **Color dots**: 4px circles at 60% opacity using segment accent color
- **Current crumb truncation**: `max-width: 180px` with ellipsis

### Chevron Arrow Separator
Inline SVG, not a text character:
```html
<svg width="10" height="10" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5">
  <path d="M6 4l4 4-4 4"/>
</svg>
```

### Collapse/Expand Toggle
- **Position**: Fixed bottom-left corner, always visible even when breadcrumb is collapsed
- **Icon**: Small chevron — points right (`▸`) when collapsed, points down (`▾`) when expanded
- **Size**: 16x16px icon area, 28px touch target on coarse pointer devices
- **Color**: `rgba(0,0,0,0.2)` — same subtlety as the breadcrumb itself
- **Behavior**: Single click toggles `.bc--collapsed` class on the `<nav>`. Collapsed state hides the crumb list, shows only the toggle. State persisted in `localStorage('bc-collapsed')` so it survives page navigations.
- **JS disabled fallback**: Toggle button hidden via `<noscript>` or `.no-js` — breadcrumb always visible

### Interaction
- `pointer-events: none` on container — clicks pass through empty space to content below
- `pointer-events: auto` on individual crumb links and toggle button
- Touch targets: `min-height: 28px` on coarse pointer devices
- No hover underline — color shift only

### Responsive
- No bar means no `padding-bottom` needed on body (text is small/transparent enough)
- Touch devices get slightly larger text (11px) and taller tap targets (28px)
- Very long trails scroll horizontally (overflow-x: auto, hidden scrollbar)

---

## Data Flow

No new DB tables. All data derived from:

1. **URL query params**: `seg`, `topic`, `id`
2. **`tiles.json`** (via `getTileConfig()`): segment + topic labels + accent colors
3. **`content_meta` table** (migration 0002): video title for leaf crumb
4. **Fallback**: missing title → "Video"; missing seg → skip; missing topic → skip

### Content Title Resolution

```php
function getContentTitle(string $contentId): string {
    // 1. Try content_meta DB table
    // 2. Fallback: derive from filename
    // 3. Final fallback: "Video"
}
```

**[ASSUMPTION]** `content_meta` table has a `title` column. Filename-derived title is fine for v1.

---

## New Files

| File | Purpose |
|------|---------|
| `htdocs/includes/breadcrumb.php` | `buildBreadcrumb()` — builds max-3 crumb array |
| `htdocs/includes/breadcrumb.html.php` | Template partial — renders fixed-bottom `<nav>` |
| `htdocs/css/breadcrumb.css` | Styles — fixed bottom, floating text, chevrons, dots, truncation |
| `tests/e2e/fre12-breadcrumb.spec.js` | Playwright tests |

## Modified Files

| File | Changes |
|------|---------|
| `htdocs/browse.php` | Include breadcrumb; pass seg context |
| `htdocs/watch.php` | Include breadcrumb; receive seg + topic params |
| `htdocs/directory.php` | Include breadcrumb ("Directory" single crumb) |
| `htdocs/navhome.php` | Add `<link>` to `breadcrumb.css` |
| `htdocs/navbar.php` | Add `<link>` to `breadcrumb.css` |
| `htdocs/tutorials.php` | Include breadcrumb |
| `htdocs/audiobooks.php` | Include breadcrumb |
| `htdocs/listen.php` | Include breadcrumb |

---

## Component Design

### PHP: `buildBreadcrumb()`

```php
function buildBreadcrumb(array $params, array $tileConfig): array {
    $crumbs = [];

    $seg   = $params['seg']   ?? null;
    $topic = $params['topic'] ?? null;
    $id    = $params['id']    ?? null;

    // Level 1: Category (segment)
    if ($seg && isset($tileConfig['segments'][$seg])) {
        $segData = $tileConfig['segments'][$seg];
        $crumbs[] = [
            'label' => $segData['label'],
            'color' => $segData['accentColor'] ?? null,
            'href'  => ($topic || $id) ? "/browse.php?seg={$seg}" : null,
        ];
    }

    // Level 2: Sub-category (topic)
    if ($seg && $topic && isset($tileConfig['segments'][$seg]['topics'])) {
        $topicData = findTopicBySlug($tileConfig['segments'][$seg]['topics'], $topic);
        if ($topicData) {
            $crumbs[] = [
                'label' => $topicData['label'],
                'color' => $topicData['accentColor'] ?? $segData['accentColor'] ?? null,
                'href'  => $id ? "/browse.php?seg={$seg}&topic={$topic}" : null,
            ];
        }
    }

    // Level 3: Current video/content
    if ($id) {
        $title = getContentTitle($id);
        $crumbs[] = [
            'label' => $title,
            'color' => null,
            'href'  => null, // current page
        ];
    }

    return $crumbs; // max 3 items
}
```

**Key detail**: `tiles.json` stores topics as an **array** (not keyed object), so `findTopicBySlug()` iterates to match by `slug` field.

```php
function findTopicBySlug(array $topics, string $slug): ?array {
    foreach ($topics as $t) {
        if (($t['slug'] ?? '') === $slug) return $t;
    }
    return null;
}
```

### HTML: `breadcrumb.html.php`

```php
<?php if (!empty($crumbs)): ?>
<nav class="bc" aria-label="You are here" style="pointer-events:none">
  <!-- Toggle button: always visible, even when collapsed -->
  <button class="bc__toggle" type="button"
          aria-label="Toggle breadcrumb"
          aria-expanded="true"
          style="pointer-events:auto"
          onclick="this.closest('.bc').classList.toggle('bc--collapsed');
                   var c=this.closest('.bc').classList.contains('bc--collapsed');
                   this.setAttribute('aria-expanded',!c);
                   localStorage.setItem('bc-collapsed',c?'1':'0')">
    <svg class="bc__toggle-icon" width="12" height="12" viewBox="0 0 16 16"
         fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M6 4l4 4-4 4"/>
    </svg>
  </button>
  <ol class="bc__list">
    <?php foreach ($crumbs as $i => $crumb): ?>
      <?php $isLast = ($i === count($crumbs) - 1); ?>
      <?php if ($i > 0): ?>
        <li class="bc__sep" aria-hidden="true">
          <svg width="10" height="10" viewBox="0 0 16 16" fill="none"
               stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M6 4l4 4-4 4"/>
          </svg>
        </li>
      <?php endif; ?>
      <li class="bc__item<?= $isLast ? ' bc__item--current' : '' ?>"
          <?= $isLast ? 'aria-current="page"' : '' ?>>
        <?php if ($crumb['color']): ?>
          <span class="bc__dot" style="background:<?= htmlspecialchars($crumb['color']) ?>"></span>
        <?php endif; ?>
        <?php if (!$isLast && $crumb['href']): ?>
          <a class="bc__link" href="<?= htmlspecialchars($crumb['href']) ?>"
             title="<?= htmlspecialchars($crumb['label']) ?>"
             style="pointer-events:auto">
            <?= htmlspecialchars($crumb['label']) ?>
          </a>
        <?php else: ?>
          <span class="bc__label" title="<?= htmlspecialchars($crumb['label']) ?>">
            <?= htmlspecialchars($crumb['label']) ?>
          </span>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ol>
</nav>
<!-- Restore collapsed state from localStorage -->
<script>
  if(localStorage.getItem('bc-collapsed')==='1'){
    var n=document.querySelector('.bc');
    if(n){n.classList.add('bc--collapsed');n.querySelector('.bc__toggle').setAttribute('aria-expanded','false');}
  }
</script>
<?php endif; ?>
```

### CSS: `breadcrumb.css`

```css
.bc {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    z-index: 1030;
    padding: 6px 12px;
    pointer-events: none;
    display: flex;
    align-items: center;
    gap: 4px;
    /* no background, no border, no bar */
}

/* --- Toggle button --- */
.bc__toggle {
    all: unset;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 16px;
    height: 16px;
    color: rgba(0, 0, 0, 0.2);
    transition: color 0.15s, transform 0.2s;
    flex-shrink: 0;
    pointer-events: auto;
}
.bc__toggle:hover { color: rgba(0, 0, 0, 0.4); }
.bc__toggle-icon { transition: transform 0.2s; }

/* Expanded state: rotate chevron to point down */
.bc:not(.bc--collapsed) .bc__toggle-icon {
    transform: rotate(90deg);
}

/* Collapsed state: hide the crumb list */
.bc--collapsed .bc__list {
    display: none;
}

.bc__list {
    display: flex;
    align-items: center;
    list-style: none;
    margin: 0;
    padding: 0;
    gap: 5px;
    justify-content: flex-start; /* left-justified */
}

.bc__dot {
    width: 4px;
    height: 4px;
    border-radius: 50%;
    display: inline-block;
    opacity: 0.6;
    flex-shrink: 0;
}

.bc__link {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    text-decoration: none;
    color: rgba(0, 0, 0, 0.3);
    font-size: 10px;
    transition: color 0.15s;
    pointer-events: auto;
}
.bc__link:hover {
    color: rgba(0, 0, 0, 0.55);
}

.bc__item--current {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    color: rgba(0, 0, 0, 0.5);
    font-size: 10px;
    max-width: 200px;
}
.bc__item--current .bc__label {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    max-width: 180px;
}

.bc__sep {
    color: rgba(0, 0, 0, 0.15);
    display: inline-flex;
    align-items: center;
}

/* Touch devices */
@media (pointer: coarse) {
    .bc__link,
    .bc__item--current { font-size: 11px; min-height: 28px; }
    .bc__toggle { width: 28px; height: 28px; }
}
```

---

## Integration: watch.php Param Passthrough

**Problem**: `buildTopicHref()` in `tiles.php` generates encrypted `tutorials.php` links without `seg` or `topic` params. Breadcrumb on watch.php/tutorials.php won't know the category context.

**Fix**: Update `buildTopicHref()` to append `seg` and `topic` as additional query params:

```php
// tutorials.php?&ENCKEY=ENCVAL&seg=early_learners&topic=primary-multiplication
```

Then on tutorials.php/watch.php, read `seg` and `topic` from `$_GET` for breadcrumb context.

**Fallback**: If reached without `seg`/`topic` (Continue Watching, direct link), show single crumb: "[Video Title]".

---

## Edge Cases

| Case | Handling |
|------|----------|
| Homepage (`/`) | No breadcrumb rendered (empty crumbs array) |
| `seg` not in `tiles.json` | No crumbs → nothing rendered |
| `topic` slug not in segment's topics | Skip topic crumb → category only |
| Video title 80+ chars | `max-width: 180px` + ellipsis; `title` attr for full text |
| Player reached without seg/topic | Single crumb: "[Video Title]" |
| `getContentTitle()` returns null | Fallback label "Video" |
| JS disabled | Breadcrumb always visible; toggle button hidden (no `.no-js` needed — button just won't work, trail stays shown) |
| XSS in params | All output via `htmlspecialchars()` |
| Landscape phone (short viewport) | Text is small enough to not obstruct content |
| Click on empty space near breadcrumb | Passes through to content (`pointer-events: none` on container) |
| Toggle collapsed, navigate to new page | Collapsed state persists via `localStorage('bc-collapsed')` |
| Toggle collapsed, clear localStorage | Breadcrumb defaults to expanded (visible) |

---

## Test Plan

| # | Test | Expected |
|---|------|----------|
| T1 | Load `/` | No breadcrumb visible |
| T2 | Load `/browse.php?seg=early_learners` | "Early Learners" (single, not a link) with color dot |
| T3 | Load browse with seg+topic | "Early Learners > Primary Multiplication" — first is a link, chevron arrow between |
| T4 | Load video page with seg+topic+id | Full 3-level crumb; first two are links |
| T5 | Tap "Early Learners" from video page | Navigates to `/browse.php?seg=early_learners` |
| T6 | Video title 100+ chars | Truncates with ellipsis; `title` attr has full text |
| T7 | Unknown `seg=xyz` | No breadcrumb rendered |
| T8 | 320px viewport | Left-justified, text visible, no overflow issues |
| T9 | Touch device | Slightly larger text (11px), taller tap targets (28px) |
| T10 | Keyboard tab through crumbs | Focus in order; Enter activates |
| T11 | `seg=<script>alert(1)</script>` | Escaped, no XSS |
| T12 | Load `/directory.php` | Single crumb: "Directory" |
| T13 | Video page with only `id` (no seg/topic) | Single crumb: "[Video Title]" |
| T14 | Click empty space near breadcrumb | Click passes through to content below |
| T15 | `aria-label` + `aria-current` | Screen reader announces "You are here" and current page |
| T16 | Color dots render per segment | 4px dot with segment accent color at 60% opacity |
| T17 | Click toggle button | Breadcrumb trail hides; toggle icon rotates to point right |
| T18 | Click toggle again | Breadcrumb trail reappears; toggle icon rotates to point down |
| T19 | Collapse, navigate to new page | Breadcrumb still collapsed on new page (localStorage) |
| T20 | Clear localStorage, reload | Breadcrumb defaults to expanded |

---

## Task Breakdown

| # | Task | Est | Depends |
|---|------|-----|---------|
| 1 | `breadcrumb.php`: buildBreadcrumb(), findTopicBySlug(), getContentTitle() | 0.5d | — |
| 2 | `breadcrumb.html.php`: template partial with chevron SVGs, color dots, toggle button + localStorage script | 0.25d | 1 |
| 3 | `breadcrumb.css`: fixed-bottom floating text, toggle button, collapsed state + all styles | 0.25d | — |
| 4 | Integrate into browse.php, directory.php + CSS link in navs | 0.25d | 1, 2, 3 |
| 5 | Integrate into watch.php/tutorials.php + update `buildTopicHref()` to pass seg/topic | 0.5d | 1, 2, 4 |
| 6 | Edge case hardening | 0.25d | 1, 5 |
| 7 | Playwright tests | 0.5d | all |
| **Total** | | **~2.5d** | |

---

## Risks & Open Items

### Risks
- **watch.php param passthrough**: `buildTopicHref()` generates encrypted links without seg/topic. Updating it is the biggest integration task.

### Open Items (non-blocking)
1. ~~Should breadcrumb auto-hide during video fullscreen?~~ → User can manually collapse via toggle
2. When FRE-14 (Mode Switch) lands, should projector mode hide the breadcrumb?
3. Color dot colors — need to confirm `accentColor` field exists in `tiles.json` segments.

### Blocking Question
**Does `buildTopicHref()` currently pass `seg` as a query param to content pages?** If not, tutorials.php/watch.php will never have category context and breadcrumbs will always be single-crumb. Need to verify before starting task 5.
