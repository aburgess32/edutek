# FRE-9 Pre-Dev Plan: Visual Home Tiles

## Scope Summary

Replace the flat text-box homepage with a **two-level visual tile system** plus a **category directory page** and **admin panel** for managing content-to-segment assignments.

### Key Decisions (Locked)
- **Segment labels**: Neutral/inviting — no age language (Early Learners, Explorers, Advanced, Educators)
- **Directory page**: Craigslist-style category index with links drilling into existing content pages
- **Auto-classification**: AI-based — classify new videos by title/folder/metadata into segments
- **Admin UI**: Simple PHP admin page with controls to assign/move content between segments
- **Thumbnail generation**: Programmatic — extract from video content or generate per-category
- **Flat grid**: Stays, but upgraded visually alongside the new tile system

---

## Architecture Overview

```
┌─────────────────────────────────────────────────────┐
│  index.php (HOME)                                    │
│  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌────────┐ │
│  │ Early    │ │Explorers │ │Advanced  │ │Educators│ │
│  │ Learners │ │          │ │          │ │         │ │
│  └────┬─────┘ └────┬─────┘ └────┬─────┘ └───┬────┘ │
│       │             │            │            │      │
│       ▼             ▼            ▼            ▼      │
│  browse.php?seg=early_learners                       │
│  ┌──────┐ ┌──────┐ ┌──────┐ ┌──────┐               │
│  │Math  │ │Comics│ │Khan  │ │Music │ ...            │
│  └──────┘ └──────┘ └──────┘ └──────┘               │
│       │                                              │
│       ▼                                              │
│  tutorials.php / audiobooks.php / etc. (existing)    │
├──────────────────────────────────────────────────────┤
│  directory.php (ALL CONTENT INDEX)                   │
│  Anchor-nav category index → drills into pages       │
├──────────────────────────────────────────────────────┤
│  admin/tiles.php (ADMIN PANEL)                       │
│  Assign content to segments, reorder, preview        │
└──────────────────────────────────────────────────────┘
```

---

## Segment Definitions

| Segment Key        | Label              | Description                                    | Color   | Content Examples                              |
|--------------------|--------------------|-------------------------------------------------|---------|-----------------------------------------------|
| `early_learners`   | Early Learners     | Foundation skills, stories, play                | #FF6B35 | Primary Multiplication, Comic Books, Khan     |
| `explorers`        | Explorers          | Curiosity-driven, broad topics                  | #4ECDC4 | Audiobooks, Wiki, Generative AI               |
| `advanced`         | Advanced           | Vocational, professional, deep                  | #1A535C | Electrical Engineering, Hotel Management      |
| `educators`        | Educators          | Teaching tools, lesson resources                | #F7C948 | (future: lesson plans, grade tools)           |
| `knowledge_power`  | Knowledge is Power | Admin-curated priority content for local needs  | #E63946 | (populated by local admins: health, sanitation, etc.) |

Content can belong to **multiple segments** (e.g., Khan fits Early Learners AND Explorers).

---

## New Files

| File                          | Purpose                                                  |
|-------------------------------|----------------------------------------------------------|
| `htdocs/browse.php`           | Topic tile grid for a selected segment                   |
| `htdocs/directory.php`        | Craigslist-style full content index                      |
| `htdocs/admin/tiles.php`      | Admin panel for segment assignment                       |
| `htdocs/admin/classify.php`   | AI auto-classification endpoint (called on new content)  |
| `htdocs/config/tiles.json`    | Tile configuration data (segments → topics → content)    |
| `htdocs/includes/tiles.php`   | PHP helper: load/cache tile config, thumbnail generator  |
| `htdocs/css/tiles.css`        | Tile grid styles, responsive breakpoints                 |
| `htdocs/assets/tiles/`        | Generated tile images (WebP + JPG fallback)              |
| `scripts/generate-thumbs.sh`  | Batch thumbnail extraction from video files              |

## Modified Files

| File                  | Changes                                               |
|-----------------------|-------------------------------------------------------|
| `htdocs/index.php`    | Replace flat grid with segment tile grid              |
| `htdocs/navhome.php`  | Add directory link, update nav styling                |
| `htdocs/navbar.php`   | Add directory link, consistent nav                    |
| `htdocs/css/index.css`| Upgrade flat grid styling                             |
| `db/schema.sql`       | Add `content_segments` table                          |

---

## Data Model

### tiles.json (primary config)

```json
{
  "segments": {
    "early_learners": {
      "label": "Early Learners",
      "description": "Foundation skills, stories, and play",
      "icon": "assets/icons/early-learners.svg",
      "image": "assets/tiles/seg_early_learners.webp",
      "color": "#FF6B35",
      "topics": [
        {
          "slug": "primary-multiplication",
          "label": "Primary Multiplication",
          "image": "assets/tiles/primary-multiplication.webp",
          "color": "#4ECDC4",
          "type": "video",
          "href": "tutorials.php?course=Primary+Multiplication"
        },
        {
          "slug": "comic-books",
          "label": "Comic Books",
          "image": "assets/tiles/comic-books.webp",
          "color": "#FF6B6B",
          "type": "media",
          "href": "Comic_books.php"
        }
      ]
    }
  },
  "services": [
    {
      "slug": "kiwix-khan",
      "label": "Kiwix Khan",
      "port": 7862,
      "segments": ["early_learners", "explorers"],
      "image": "assets/tiles/kiwix-khan.webp"
    }
  ],
  "uncategorized": []
}
```

### content_segments table (DB — for admin panel)

```sql
CREATE TABLE content_segments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    content_path VARCHAR(255) NOT NULL,
    content_type ENUM('video_group', 'video', 'service', 'resource') NOT NULL,
    segment VARCHAR(50) NOT NULL,
    label VARCHAR(100),
    suggested_by ENUM('ai', 'admin', 'keyword') DEFAULT 'admin',
    confirmed TINYINT(1) DEFAULT 0,
    sort_order TINYINT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_content_segment (content_path, segment)
);
```

---

## Thumbnail Generation Strategy

### Video categories
1. **`ffmpeg`** extracts a frame at 10% duration from the first video in each subfolder
2. Resize to 400x400, output as WebP (primary) + JPG (fallback)
3. Script: `scripts/generate-thumbs.sh` — runs on demand or as part of `make optimize-images`

### Services (Kiwix, AI tools, Khan)
- Use branded icon/logo images (manually placed or AI-generated)

### Fallback chain
```
tile image → category color background → segment color → grey + icon
```

---

## AI Auto-Classification

When new video folders are detected (folders in `videos/` not in `content_segments`):

1. `classify.php` reads folder name + subfolder names
2. Sends to a local keyword-matching pass first (fast):
   - "Primary", "Kids", "Children" → `early_learners`
   - "Engineering", "Management", "Professional" → `advanced`
   - "Teaching", "Lesson", "Curriculum" → `educators`
3. If no keyword match, flag as `uncategorized` with `suggested_by = 'ai'`
4. Admin panel shows uncategorized items with a "Suggest" button
5. **On EduPak (offline)**: keyword-only classification
6. **On dev/connected**: optional LLM classification via API call

---

## Admin Panel (`admin/tiles.php`)

### Features
- List all content grouped by current segment
- Drag-and-drop or checkbox to move content between segments
- "Uncategorized" section at top showing new/unassigned content
- Preview tile appearance
- Reorder tiles within a segment
- Save → writes to DB + regenerates `tiles.json`

### Auth
- Simple password gate for now (matches existing EduPak pattern)
- Future: tie to teacher login (FRE-11)

---

## Directory Page (`directory.php`)

Craigslist-style single page with:
- Anchor nav at top: `[Early Learners] [Explorers] [Advanced] [Educators] [Services] [All]`
- Each section lists category links as a compact list (not tiles)
- Shows: label, content count, content type badge (Video / Audio / Book / Tool)
- Links drill into existing pages (tutorials.php, audiobooks.php, etc.)
- Search input at top filters the list client-side (JS `filter()` on DOM)

---

## Responsive Breakpoints

| Screen              | Segment tiles | Topic tiles | Directory    |
|---------------------|---------------|-------------|--------------|
| < 400px (phone)     | 1 col         | 2 col       | Single list  |
| 400-599px (phone)   | 2 col         | 2 col       | Single list  |
| 600-899px (tablet)  | 2 col         | 3 col       | 2 col list   |
| 900-1199px (tablet) | 4 col         | 4 col       | 3 col list   |
| 1200px+ (projector) | 4 col         | 5 col       | 4 col list   |

---

## Task Breakdown

| #  | Task                                       | Est    | Depends On |
|----|--------------------------------------------|--------|------------|
| 1  | Create `tiles.json` schema + seed data     | 0.5d   | —          |
| 2  | Build tile CSS components + grid           | 1d     | —          |
| 3  | Thumbnail generation script (ffmpeg)       | 0.5d   | —          |
| 4  | PHP tile config loader + helpers           | 0.5d   | 1          |
| 5  | Rework `index.php` → segment tiles        | 1d     | 1, 2, 4    |
| 6  | Build `browse.php` → topic tiles           | 1d     | 1, 2, 4    |
| 7  | Build `directory.php` → category index     | 0.5d   | 1          |
| 8  | Build `admin/tiles.php` → admin panel      | 1.5d   | 1, 4       |
| 9  | AI classification (`classify.php`)         | 0.5d   | 8          |
| 10 | DB migration for `content_segments`        | 0.25d  | —          |
| 11 | Upgrade existing flat grid styling         | 0.5d   | 2          |
| 12 | Wire Kiwix/AI services into tile config    | 0.25d  | 1          |
| 13 | Fallback handling + edge cases             | 0.5d   | 5, 6       |
| 14 | Playwright tests on device matrix          | 1d     | 5, 6, 7    |
| **Total** |                                    | **~9d**|            |

---

## Edge Cases

| Case                                    | Handling                                              |
|-----------------------------------------|-------------------------------------------------------|
| `tiles.json` missing/malformed          | Fallback to hardcoded 4 segments, log error           |
| Tile image missing                      | Accent color background + icon + label                |
| New video folder added                  | Appears in "Uncategorized" on admin, auto-suggested   |
| Content in multiple segments            | Tile appears in each segment's browse page            |
| 20+ topics in one segment              | Cap at 20, show "See all" link                        |
| Kiwix service not running (dev mode)   | Tile shows "Unavailable" badge, still navigable       |
| Admin changes during active sessions   | JSON regenerated, next page load picks up changes     |
| Very long category name                | `text-overflow: ellipsis`, full name in `title` attr  |

---

## Test Plan

| # | Test | Expected |
|---|------|----------|
| T1 | Load `/` on 1GB RAM Android | 4 segment tiles visible < 2s |
| T2 | Tap "Early Learners" | Navigates to browse.php, shows topic tiles |
| T3 | Tap video topic tile | Opens tutorials.php with correct content |
| T4 | Load directory.php | All content listed, anchor nav works |
| T5 | Filter directory search | Instant client-side filtering |
| T6 | Admin: move content between segments | Tile appears in new segment on next browse |
| T7 | Admin: new uncategorized video | Shows in admin with AI suggestion |
| T8 | Delete tile image from disk | Tile renders with color + icon fallback |
| T9 | Load on 320px phone | Single column, no overflow |
| T10 | Load on 1280px projector | 4-col segments, 5-col topics |
| T11 | Malformed seg param | Redirect to `/` |

---

## Open Items (non-blocking)

1. Exact segment-to-content mapping — need your input on the 6 video categories + services
2. Educator segment content — placeholder until FRE-13 (Teacher Content Finder) is built
3. Tile image art direction — bright photos vs flat illustrations vs AI-generated
</content>
</invoke>