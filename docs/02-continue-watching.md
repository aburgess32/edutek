# Continue Watching Row

## Summary
- Horizontal scroll row (Netflix-style) displays the last 5 videos a user was watching, with thumbnail, title, and a progress bar overlay
- Tied to the active session user from Spec 03 (Simple Name Login) — entirely personal per user
- Thumbnails are lazy-loaded and served from local disk; queries hit the `watch_history` table with an indexed lookup
- Gracefully degrades to an empty state when the user has no history or content has been deleted from disk

## User Story
> As a **returning learner** (kid, teen, adult, or teacher), I want to see the last few things I was watching right on the home screen so I can pick up exactly where I left off without re-navigating.

> As a **shared-device user** (family tablet), I want my continue-watching row to show only *my* history after I switch to my name, not my sibling's videos.

> As a **teacher**, I want my continue-watching row to show the lesson videos I was previewing, so I can quickly resume preparation.

## Technical Approach

### Frontend (HTML/CSS/JS)

**Component markup:**
```html
<section class="continue-row" aria-label="Continue Watching">
  <h2 class="continue-row__heading">Continue Watching</h2>
  <div class="continue-row__scroll" role="list">
    <!-- JS or PHP renders .continue-card items here -->
  </div>
</section>
```

**Card component:**
```html
<a class="continue-card" href="/player.php?id=VIDEO_ID" role="listitem"
   aria-label="Continue: Intro to Fractions – 42% watched">
  <div class="continue-card__thumb">
    <img src="/assets/thumbs/VIDEO_ID.jpg"
         data-src="/assets/thumbs/VIDEO_ID.webp"
         alt="Intro to Fractions thumbnail"
         loading="lazy" decoding="async">
    <div class="continue-card__progress" style="--pct: 42%"></div>
  </div>
  <p class="continue-card__title">Intro to Fractions</p>
</a>
```

**CSS:**
```css
.continue-row__scroll {
  display: flex;
  gap: 10px;
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;   /* iOS momentum */
  scroll-snap-type: x mandatory;
  padding: 4px 12px 12px;
  scrollbar-width: none;               /* hide scrollbar on Firefox */
}
.continue-row__scroll::-webkit-scrollbar { display: none; }

.continue-card {
  flex: 0 0 160px;                     /* fixed card width */
  scroll-snap-align: start;
  border-radius: 8px;
  overflow: hidden;
  background: #1a1a1a;
  text-decoration: none;
  color: #fff;
  position: relative;
  min-height: 44px;
}

@media (min-width: 600px)  { .continue-card { flex: 0 0 200px; } }
@media (min-width: 900px)  { .continue-card { flex: 0 0 240px; } }

.continue-card__thumb {
  position: relative;
  aspect-ratio: 16 / 9;
  background: #333;
}
.continue-card__thumb img {
  width: 100%; height: 100%; object-fit: cover;
}

/* Progress bar overlay */
.continue-card__progress {
  position: absolute;
  bottom: 0; left: 0;
  height: 4px;
  width: var(--pct);
  background: #E50914;                 /* configurable accent color */
}

.continue-card__title {
  font-size: 0.8rem;
  padding: 6px 8px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
```

**Lazy-load JS (no external libs):**
```js
document.addEventListener('DOMContentLoaded', () => {
  const imgs = document.querySelectorAll('.continue-card img[data-src]');
  if (!('IntersectionObserver' in window)) {
    // Fallback: load all immediately on low-capability browsers
    imgs.forEach(img => { img.src = img.dataset.src || img.src; });
    return;
  }
  const io = new IntersectionObserver((entries) => {
    entries.forEach(e => {
      if (!e.isIntersecting) return;
      const img = e.target;
      img.src = img.dataset.src;
      io.unobserve(img);
    });
  }, { rootMargin: '100px' });
  imgs.forEach(img => io.observe(img));
});
```

**[ASSUMPTION]** `IntersectionObserver` is available on Android 5+. The fallback above handles older WebViews.

### Backend (PHP/MySQL)

**Endpoint:** `api/continue_watching.php` (called via XHR on page load, or PHP-rendered inline for no-JS path)

```php
// api/continue_watching.php
header('Content-Type: application/json');

session_start();
$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) { echo json_encode([]); exit; }

$pdo = getPDO(); // shared DB connection helper

$stmt = $pdo->prepare("
    SELECT
        wh.content_id,
        wh.content_title,
        wh.thumbnail_path,
        wh.progress_seconds,
        wh.duration_seconds,
        wh.last_watched
    FROM watch_history wh
    WHERE wh.user_id = :uid
      AND wh.duration_seconds > 0
    ORDER BY wh.last_watched DESC
    LIMIT 5
");
$stmt->execute([':uid' => $user_id]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Validate thumbnails exist on disk; mark missing ones
foreach ($rows as &$row) {
    $thumbPath = __DIR__ . '/../' . ltrim($row['thumbnail_path'], '/');
    $row['thumb_ok'] = file_exists($thumbPath);
    $row['progress_pct'] = $row['duration_seconds'] > 0
        ? round(($row['progress_seconds'] / $row['duration_seconds']) * 100)
        : 0;
    // Remove items that are ≥ 95% complete (consider "finished")
    // [ASSUMPTION] 95% threshold for "done" — confirm with product
}
unset($row);

// Filter out fully watched items
$rows = array_filter($rows, fn($r) => $r['progress_pct'] < 95);
$rows = array_values(array_slice($rows, 0, 5)); // re-index + re-cap

echo json_encode($rows);
```

**Writing watch progress** (called from player page):
```php
// api/update_progress.php — POST: content_id, progress_seconds, duration_seconds
$stmt = $pdo->prepare("
    INSERT INTO watch_history
        (user_id, content_id, content_title, thumbnail_path, progress_seconds, duration_seconds)
    VALUES
        (:uid, :cid, :title, :thumb, :prog, :dur)
    ON DUPLICATE KEY UPDATE
        progress_seconds = VALUES(progress_seconds),
        last_watched     = CURRENT_TIMESTAMP
");
```

**[ASSUMPTION]** A `UNIQUE KEY` on `(user_id, content_id)` needs to be added to `watch_history` for `ON DUPLICATE KEY UPDATE` to work. Current schema does not show this constraint.

```sql
-- Migration needed:
ALTER TABLE watch_history
  ADD CONSTRAINT uq_user_content UNIQUE (user_id, content_id);
```

### Data Model

Referencing the existing schema:

```sql
-- Existing watch_history table (from schema.sql):
CREATE TABLE IF NOT EXISTS watch_history (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    user_id          INT,
    content_id       VARCHAR(255) NOT NULL,
    content_title    VARCHAR(500),
    content_type     VARCHAR(50),
    thumbnail_path   VARCHAR(500),
    progress_seconds INT DEFAULT 0,
    duration_seconds INT DEFAULT 0,
    last_watched     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_last (user_id, last_watched DESC)
);
```

**Required addition** (migration script):
```sql
ALTER TABLE watch_history
  ADD CONSTRAINT uq_user_content UNIQUE (user_id, content_id);
```

**Query performance note:** The existing `INDEX idx_user_last (user_id, last_watched DESC)` covers the `ORDER BY last_watched DESC WHERE user_id = ?` query perfectly. No additional indexes needed.

## UI/UX Specification

| Element | Spec |
|---------|------|
| Card width | 160px phone / 200px tablet / 240px large |
| Card aspect ratio | 16:9 thumbnail + title below |
| Progress bar | 4px height, red (`#E50914` default), anchored to bottom of thumbnail |
| Row heading | "Continue Watching" — hidden if row is empty |
| Touch scrolling | Horizontal scroll, momentum, snap-to-card |
| Empty state | Section not rendered at all (PHP skips the block if 0 results) |
| Placeholder while loading | CSS skeleton shimmer on card area (`background: linear-gradient(90deg, #eee 25%, #ddd 50%, #eee 75%)`) |
| Missing thumbnail | Gray placeholder with play icon SVG centred |

## Edge Cases & Failure Modes

| Case | Handling |
|------|----------|
| User has no history | Row not rendered; no "empty" heading shown |
| `content_id` in DB but video file deleted from disk | Card still shows with thumbnail (if thumb exists); player handles missing file separately |
| Thumbnail file deleted from disk | `thumb_ok = false` in API response; frontend renders gray placeholder |
| `duration_seconds = 0` (never set) | Row in API filtered out (`WHERE duration_seconds > 0`) |
| Progress ≥ 95% | Filtered from the "continue watching" list — treat as complete |
| User switches account mid-session | Row re-fetches on next page load via new `user_id` in session |
| DB unavailable | PHP catches `PDOException`, returns `[]`, row not rendered |
| 6+ history items | Query `LIMIT 5` — only freshest 5 shown |
| Two sessions same user (different tabs) | `last_watched` auto-updates; next page load shows latest state |
| `content_id` contains path traversal characters | `content_id` is used only in DB query with prepared statements — not used for filesystem access directly |

## Test Plan

| # | Test | Expected |
|---|------|----------|
| T1 | Watch 3 videos as "Amara", load home | Row shows 3 cards in reverse-watch order |
| T2 | Watch a 4th video | Row shows 4 cards (capped at 5) |
| T3 | Watch a video to 96% completion | Card disappears from row on next load |
| T4 | Delete thumbnail file from disk | Card renders gray placeholder, no broken image |
| T5 | Log out, switch to new user "Kofi" with no history | Continue Watching row not present on page |
| T6 | Switch back to "Amara" | Amara's row reappears |
| T7 | Watch video to 50%, check progress bar | Bar visually covers ~50% of thumbnail width |
| T8 | Horizontal scroll on 320px phone | Scrolls smoothly, snap-to-card works |
| T9 | JS disabled (no-JS path) | PHP-rendered inline cards visible without JS |
| T10 | 1GB RAM device: load page with 5 cards | No jank; lazy-load defers off-screen thumbnails |
| T11 | `UNIQUE` constraint absent (legacy DB) | `INSERT ... ON DUPLICATE KEY` degrades to multiple rows; detect via migration check |

## Dependencies
- **Spec 03 (Simple Name Login):** `$_SESSION['user_id']` must be set before this feature functions
- **Spec 01 (Visual Home Tiles):** Continue Watching row renders on the home page (index.php), below or above the segment tiles
- **Player page (out of scope):** Must call `api/update_progress.php` on play and on pause/seek events
- DB migration: `UNIQUE KEY (user_id, content_id)` on `watch_history`
- Thumbnail generation pipeline: thumbnails for all video content must exist at `thumbnail_path`

## Estimated Effort

| Task | Estimate |
|------|----------|
| PHP API endpoint (fetch + write progress) | 1 day |
| DB migration + constraint | 0.25 days |
| Frontend component (CSS, scroll, progress bar) | 1 day |
| Lazy-load JS + no-JS fallback | 0.5 days |
| Edge case handling (missing thumb, empty state) | 0.5 days |
| Test + bug fix | 1 day |
| **Total** | **4.25 days** |

## Open Questions
1. **[BLOCKING]** Is there an existing player page that can be modified to write progress, or does that need to be built from scratch?
2. **[BLOCKING]** What is the video `content_id` format? (filename, UUID, path?) — needed to build the player URL in card `href`
3. Should fully watched items (≥95%) be shown in a separate "Watched" row or hidden entirely?
4. Should the row show content across all segments, or only content from the user's current segment?
5. Is there a thumbnail generation script, or are thumbs manually placed per video?
