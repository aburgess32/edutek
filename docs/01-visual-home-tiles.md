# Visual Home Tiles

## Summary
- Big colorful image tiles present audience segments (Kid, Teen, Adult, Teacher) on the home screen; tapping a segment reveals topic tiles (farming, math, science, health, etc.)
- Icon-driven design ensures usability for low-literacy users; text labels are supplementary
- Tile config is stored in a JSON file (or DB table) so content editors can update categories without code changes
- Serves all target device classes: low-spec Android phones, tablets, projectors, and Raspberry Pi kiosks

## User Story
> As a **low-literacy rural learner** arriving at EduPak for the first time, I want to tap a large picture that represents me (e.g., a child's face for "Kid") so I can immediately reach content relevant to my age group without needing to read anything.

> As a **teacher**, I want a distinct tile that immediately separates my tools from student-facing content, so I reach lesson management features in one tap.

> As a **content editor**, I want to add or reorder topic tiles by editing a config file (not PHP source), so I can update the catalog without a developer.

## Technical Approach

### Frontend (HTML/CSS/JS)

**Home screen structure — two-level tile grid:**
```
/index.php          → Audience segment tiles (Kid / Teen / Adult / Teacher)
/browse.php?seg=kid → Topic tiles for selected segment
```

**CSS Grid layout:**
```css
/* --- Audience Segment Grid (Home) --- */
.tile-grid--segments {
  display: grid;
  grid-template-columns: repeat(2, 1fr); /* phone default */
  gap: 12px;
  padding: 12px;
}

@media (min-width: 600px) {
  .tile-grid--segments { grid-template-columns: repeat(2, 1fr); gap: 16px; }
}

@media (min-width: 900px) {               /* tablet landscape / projector */
  .tile-grid--segments { grid-template-columns: repeat(4, 1fr); gap: 24px; }
}

/* --- Topic Tile Grid (Browse) --- */
.tile-grid--topics {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 10px;
  padding: 10px;
}

@media (min-width: 600px)  { .tile-grid--topics { grid-template-columns: repeat(3, 1fr); } }
@media (min-width: 900px)  { .tile-grid--topics { grid-template-columns: repeat(4, 1fr); } }
@media (min-width: 1200px) { .tile-grid--topics { grid-template-columns: repeat(5, 1fr); } }
```

**Tile component HTML:**
```html
<a class="tile" href="/browse.php?seg=kid" aria-label="Kid section">
  <div class="tile__img-wrap">
    <picture>
      <source srcset="/assets/tiles/kid.webp" type="image/webp">
      <img src="/assets/tiles/kid.jpg" alt="Kid" loading="lazy" decoding="async">
    </picture>
  </div>
  <span class="tile__icon" aria-hidden="true">🧒</span>
  <span class="tile__label">Kid</span>
</a>
```

**CSS for tile:**
```css
.tile {
  display: flex;
  flex-direction: column;
  align-items: center;
  border-radius: 12px;
  overflow: hidden;
  background: #fff;
  box-shadow: 0 2px 6px rgba(0,0,0,.15);
  text-decoration: none;
  color: inherit;
  min-height: 44px;  /* touch target floor */
}

.tile__img-wrap {
  width: 100%;
  aspect-ratio: 1 / 1;
  overflow: hidden;
}

.tile__img-wrap img {
  width: 100%; height: 100%;
  object-fit: cover;
}

.tile__label {
  font-size: clamp(0.85rem, 2.5vw, 1.1rem);
  font-weight: 600;
  padding: 6px 4px;
  text-align: center;
}
```

**Image asset requirements:**

| Use | Dimensions | Format | Max file size |
|-----|-----------|--------|---------------|
| Segment tile (home) | 400×400 px | WebP + JPG fallback | 60 KB |
| Topic tile (browse) | 300×300 px | WebP + JPG fallback | 40 KB |
| Projector/large screen | 600×600 px | WebP + JPG fallback | 120 KB |

- **[ASSUMPTION]** WebP is supported by all Android WebView versions in use (requires Chrome 23+ / Android 4.2+). JPG fallback covers anything older via `<picture>`.
- Store images under `/htdocs/assets/tiles/`. Filename convention: `{segment}_{topic_slug}.webp` e.g. `kid_math.webp`.
- Images are **square** to simplify CSS — no letterboxing required.
- Use bright, high-contrast photographs or flat illustrations. Avoid text in images.

### Backend (PHP/MySQL)

**Route: `index.php`** — renders audience segment tiles from config.

**Route: `browse.php?seg={segment}`** — renders topic tiles for one segment.

```php
// browse.php (simplified)
$seg = filter_input(INPUT_GET, 'seg', FILTER_SANITIZE_SPECIAL_CHARS);
$allowed = ['kid','teen','adult','teacher'];
if (!in_array($seg, $allowed)) { header('Location: /'); exit; }

$config = getTileConfig();  // loads JSON config
$topics = $config['segments'][$seg]['topics'] ?? [];
```

**PHP helper — config loader (cached with `apc_store` or flat-file cache):**
```php
function getTileConfig(): array {
    $path = __DIR__ . '/config/tiles.json';
    $cached = apcu_fetch('tile_config', $ok);
    if ($ok) return $cached;
    $data = json_decode(file_get_contents($path), true);
    apcu_store('tile_config', $data, 300); // 5-minute TTL
    return $data;
}
```

**[ASSUMPTION]** APCu is enabled on the XAMPP install. If not, use a simple file-based cache keyed by `filemtime()`.

### Data Model

**Primary source: `/htdocs/config/tiles.json`**

```json
{
  "segments": {
    "kid": {
      "label": "Kid",
      "icon": "🧒",
      "color": "#FF6B35",
      "image": "assets/tiles/seg_kid.webp",
      "topics": [
        {
          "slug": "math",
          "label": "Math",
          "icon": "assets/icons/math.svg",
          "image": "assets/tiles/kid_math.webp",
          "color": "#4ECDC4",
          "href": "/browse.php?seg=kid&topic=math"
        }
      ]
    }
  }
}
```

**Why JSON over DB table:** Config changes are infrequent and do not require relational joins. A JSON file is editable with any text editor and avoids a DB round-trip on every page load. **[ASSUMPTION]** If the content team needs a GUI editor, migrate to a `tile_config` DB table later.

**Optional DB table (future migration path):**
```sql
CREATE TABLE tile_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    segment ENUM('kid','teen','adult','teacher') NOT NULL,
    topic_slug VARCHAR(80),
    label VARCHAR(100) NOT NULL,
    icon_path VARCHAR(255),
    image_path VARCHAR(255),
    sort_order TINYINT DEFAULT 0,
    active TINYINT(1) DEFAULT 1,
    color_hex CHAR(7),
    href VARCHAR(255)
);
```

## UI/UX Specification

| Element | Spec |
|---------|------|
| Segment tile size | Square, fills grid cell, min 120px side |
| Topic tile size | Square, fills grid cell, min 90px side |
| Tap target | Entire tile is the link — no inner buttons |
| Label font | Min 16px on phone, 20px on tablet, 28px on projector |
| Icon | SVG or emoji, 32–48px, always visible even if image fails |
| Color coding | Each segment has a distinct accent color applied to tile border/background |
| Active state | `transform: scale(0.97)` on `:active` for tactile feedback |
| Projector mode | 2-column grid, font ≥ 28px, high-contrast backgrounds (see spec 06) |

**Empty state:** If a segment has zero active topics, show a friendly message: "More content coming soon!" with the segment icon.

## Edge Cases & Failure Modes

| Case | Handling |
|------|----------|
| `tiles.json` missing or malformed | PHP logs error, renders static fallback HTML with the 4 hardcoded segments |
| Tile image file missing | CSS `background-color` (from `color` field) fills tile; icon + label remain visible |
| Too many topics (>20) | Cap display at 20; add a "See all" link that loads a full list page |
| Very long topic label | CSS `text-overflow: ellipsis` + single line; full label exposed via `title` attribute |
| Unknown `seg` query param | Redirect to `/` |
| User taps tile while page is still loading | Debounce / `pointer-events: none` during load transition (100ms) |
| Low-RAM device triggers partial image load | `loading="lazy"` + `decoding="async"` reduces jank; images sized ≤60 KB |

## Test Plan

| # | Test | Expected |
|---|------|----------|
| T1 | Load `/` on 1GB RAM Android tablet | All 4 segment tiles visible within 2s |
| T2 | Tap "Kid" tile | Navigates to `/browse.php?seg=kid`, shows topic tiles |
| T3 | Delete one image file from disk | Tile renders with accent color background + icon only; no broken image icon |
| T4 | Set `tiles.json` to malformed JSON | Fallback static segments render; PHP error logged |
| T5 | Load on 1280×720 projector (landscape) | 4-column segment grid, 5-column topic grid |
| T6 | Load on 320px-wide feature phone | 2-column grid, no horizontal overflow |
| T7 | VoiceOver/TalkBack on segment tile | Announces `aria-label` ("Kid section"), not the emoji |
| T8 | Add 21st topic to `kid` segment in JSON | 20 shown, "See all" link appears |
| T9 | Malformed `seg` param (`/browse.php?seg=<script>`) | Redirect to `/`; no XSS output |
| T10 | APCu disabled | Falls back to file-based cache; tiles still load |

## Dependencies
- **Spec 03 (Simple Name Login):** segment preference can optionally be pre-selected based on `user_type` in the session
- **Spec 06 (Mode Switch):** tile grid columns and font sizes must respond to active mode
- **Spec 04 (Breadcrumb):** browse page must expose `seg` + `topic` to breadcrumb builder
- Image assets: design/content team must deliver tile images before sprint closes

## Estimated Effort

| Task | Estimate |
|------|----------|
| HTML/CSS tile grid components | 1.5 days |
| PHP route + JSON config loader | 0.5 days |
| Image fallback + edge case handling | 0.5 days |
| Responsive + projector breakpoints | 0.5 days |
| Test + bug fix | 1 day |
| **Total** | **4 days** |

## Open Questions
1. **[BLOCKING]** Should segment selection persist in the session? (i.e., does landing on `/` after navigation show your last segment?)
2. **[BLOCKING]** Are tile images provided by the content team or auto-generated from video thumbnails?
3. Are there more than 4 audience segments planned? (affects grid column math)
4. Does "Teacher" segment gate access behind the teacher role check from Spec 03, or is it freely accessible?
5. Should topic tiles show a content count badge (e.g., "12 videos")?
