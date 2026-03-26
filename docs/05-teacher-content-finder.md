# Teacher Content Finder

## Summary
- Teachers search the full content catalog by keyword ("tractor", "grade 2") and browse results in a filterable grid; search runs against a **pre-built JSON index** for offline performance across a 4TB catalog
- A "Lesson Plan" feature lets teachers curate, save, name, and share collections of content items — backed by the `lesson_plans` DB table
- Lesson plans are shareable between teachers on the same EduPak server via a simple share code or direct DB record duplication
- All search and plan operations work entirely offline; no internet dependency

## User Story
> As a **teacher**, I want to type "grade 2 math" and immediately see matching videos and articles so I can pick content for tomorrow's lesson without manually browsing every topic folder.

> As a **teacher**, I want to save a set of 5 videos into a named lesson plan ("Week 3 – Fractions") so I can pull it up in class and play them in order without navigating between topics.

> As a **teacher**, I want to share a lesson plan with my colleague's device so she can use the same sequence in her classroom.

## Technical Approach

### Frontend (HTML/CSS/JS)

**Search UI:**
```html
<div class="content-finder">
  <div class="finder-search">
    <input type="search" id="finder-input" class="finder-search__input"
           placeholder="Search videos, topics, keywords…"
           aria-label="Search content"
           autocomplete="off" autocorrect="off" spellcheck="false">
    <button class="finder-search__btn" type="button" id="finder-btn">Search</button>
  </div>

  <div class="finder-filters">
    <select id="filter-seg" aria-label="Audience segment">
      <option value="">All Segments</option>
      <option value="kid">Kid</option>
      <option value="teen">Teen</option>
      <option value="adult">Adult</option>
    </select>
    <select id="filter-type" aria-label="Content type">
      <option value="">All Types</option>
      <option value="video">Video</option>
      <option value="article">Article</option>
      <option value="quiz">Quiz</option>
    </select>
  </div>

  <div id="finder-results" class="finder-results" role="list"
       aria-live="polite" aria-label="Search results">
    <!-- Rendered by JS from search index -->
  </div>
</div>
```

**Result card:**
```html
<div class="result-card" role="listitem">
  <img class="result-card__thumb" src="/assets/thumbs/V042.jpg"
       alt="Intro to Fractions thumbnail" loading="lazy">
  <div class="result-card__info">
    <p class="result-card__title">Intro to Fractions</p>
    <p class="result-card__meta">Math · Grade 2 · 8 min</p>
  </div>
  <div class="result-card__actions">
    <a class="btn btn--sm" href="/player.php?id=V042">Preview</a>
    <button class="btn btn--sm btn--add" data-id="V042" data-title="Intro to Fractions">
      + Add to Plan
    </button>
  </div>
</div>
```

**Client-side search against JSON index:**
```js
let searchIndex = null;

async function loadIndex() {
  if (searchIndex) return searchIndex;
  const res = await fetch('/data/search_index.json');
  searchIndex = await res.json();
  return searchIndex;
}

function tokenize(str) {
  return str.toLowerCase().replace(/[^a-z0-9\s]/g, '').split(/\s+/).filter(Boolean);
}

async function runSearch(query, filters) {
  const index = await loadIndex();
  const tokens = tokenize(query);
  if (tokens.length === 0) return [];

  return index.filter(item => {
    // All tokens must match somewhere in the item's text fields
    const haystack = item._search_text; // pre-normalized in index
    const matchesTokens = tokens.every(t => haystack.includes(t));

    const matchesSeg  = !filters.seg  || item.segment === filters.seg;
    const matchesType = !filters.type || item.content_type === filters.type;

    return matchesTokens && matchesSeg && matchesType;
  });
}

document.getElementById('finder-btn').addEventListener('click', async () => {
  const query   = document.getElementById('finder-input').value.trim();
  const seg     = document.getElementById('filter-seg').value;
  const type    = document.getElementById('filter-type').value;
  const results = await runSearch(query, { seg, type });
  renderResults(results);
});
```

**Fuzzy / typo handling:**  
**[ASSUMPTION]** v1 uses exact token matching (all query words must appear). For typo tolerance, add a [Levenshtein distance](https://en.wikipedia.org/wiki/Levenshtein_distance) check at edit-distance 1 for tokens longer than 4 characters — implementable in ~30 lines of JS without a library.

### Backend (PHP/MySQL)

**Search index generation script** (`scripts/build_search_index.php`):

```php
/**
 * Scans content directory + DB metadata, builds /htdocs/data/search_index.json
 * Run manually or via cron when content library is updated.
 */

$pdo  = getPDO();
$stmt = $pdo->query("SELECT * FROM content_meta"); // [ASSUMPTION] table exists
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$index = [];
foreach ($rows as $row) {
    $index[] = [
        'id'           => $row['content_id'],
        'title'        => $row['title'],
        'segment'      => $row['segment'],
        'topic'        => $row['topic'],
        'content_type' => $row['content_type'],
        'duration_sec' => $row['duration_seconds'] ?? null,
        'grade'        => $row['grade_level'] ?? null,
        'thumb'        => $row['thumbnail_path'],
        'href'         => "/player.php?id={$row['content_id']}",
        // Pre-normalized search text field
        '_search_text' => strtolower(implode(' ', [
            $row['title'],
            $row['topic'],
            $row['keywords'] ?? '',
            $row['grade_level'] ?? '',
            $row['segment'],
        ])),
    ];
}

file_put_contents(
    __DIR__ . '/../htdocs/data/search_index.json',
    json_encode($index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);
echo "Index built: " . count($index) . " items\n";
```

**Index size estimate:** 4TB library, assumed ~10,000–50,000 content items. At ~300 bytes per index entry (JSON), that is 3–15 MB — acceptable to load once and cache in memory. **[ASSUMPTION]** If item count exceeds 100,000, split index into per-segment files (`search_index_kid.json`, etc.) and load lazily on first search per segment.

**Lesson Plan CRUD:**

```php
// api/lesson_plans.php — RESTful-ish via POST action param

// CREATE
$title      = trim($_POST['title']);
$teacher_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("INSERT INTO lesson_plans (teacher_id, title, content_ids) VALUES (?,?,?)");
$stmt->execute([$teacher_id, $title, json_encode([])]);
echo json_encode(['id' => $pdo->lastInsertId()]);

// READ (list for current teacher)
$stmt = $pdo->prepare("SELECT * FROM lesson_plans WHERE teacher_id = ? ORDER BY created_at DESC");
$stmt->execute([$teacher_id]);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));

// UPDATE (add/remove content item)
$plan_id = (int)$_POST['plan_id'];
$stmt = $pdo->prepare("SELECT content_ids, teacher_id FROM lesson_plans WHERE id = ?");
$stmt->execute([$plan_id]);
$plan = $stmt->fetch();
if ($plan['teacher_id'] !== $teacher_id) { http_response_code(403); exit; }
$ids = json_decode($plan['content_ids'], true);
// Add or remove
if ($_POST['op'] === 'add')    $ids[] = $_POST['content_id'];
if ($_POST['op'] === 'remove') $ids = array_values(array_diff($ids, [$_POST['content_id']]));
$stmt = $pdo->prepare("UPDATE lesson_plans SET content_ids = ? WHERE id = ?");
$stmt->execute([json_encode(array_unique($ids)), $plan_id]);

// DELETE
$stmt = $pdo->prepare("DELETE FROM lesson_plans WHERE id = ? AND teacher_id = ?");
$stmt->execute([$plan_id, $teacher_id]);
```

**Lesson plan sharing:**  
**[ASSUMPTION]** "Sharing" means copying a plan to another teacher's account on the same server — not inter-server sync.

```php
// Share: duplicate a plan to another teacher by display_name
$target = $pdo->prepare("SELECT id FROM users WHERE display_name = ? AND user_type = 'teacher'");
$target->execute([$_POST['share_with_name']]);
$recipient = $target->fetch();
if (!$recipient) { echo json_encode(['error' => 'Teacher not found']); exit; }

$src  = $pdo->prepare("SELECT title, content_ids FROM lesson_plans WHERE id = ? AND teacher_id = ?");
$src->execute([$plan_id, $teacher_id]);
$plan = $src->fetch();

$copy = $pdo->prepare("INSERT INTO lesson_plans (teacher_id, title, content_ids) VALUES (?,?,?)");
$copy->execute([$recipient['id'], $plan['title'] . ' (shared)', $plan['content_ids']]);
```

### Data Model

```sql
-- Existing lesson_plans table (from schema.sql):
CREATE TABLE IF NOT EXISTS lesson_plans (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT,
    title      VARCHAR(255) NOT NULL,
    content_ids JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE
);
```

**Required additions:**
```sql
-- Index for fast teacher plan lookup
ALTER TABLE lesson_plans ADD INDEX idx_teacher (teacher_id);

-- [ASSUMPTION] A content_meta table is needed to drive the search index builder.
-- If content is file-system only, this must be created and populated.
CREATE TABLE IF NOT EXISTS content_meta (
    content_id      VARCHAR(255) PRIMARY KEY,
    title           VARCHAR(500) NOT NULL,
    segment         ENUM('kid','teen','adult','teacher'),
    topic           VARCHAR(100),
    content_type    ENUM('video','article','quiz'),
    duration_seconds INT,
    grade_level     VARCHAR(20),
    keywords        TEXT,
    thumbnail_path  VARCHAR(500),
    file_path       VARCHAR(500),
    added_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

**[ASSUMPTION]** `content_meta` is populated by a one-time scan script and updated when content is added to the library.

**Search index file structure (`/htdocs/data/search_index.json`):**
```json
[
  {
    "id": "V042",
    "title": "Intro to Fractions",
    "segment": "kid",
    "topic": "math",
    "content_type": "video",
    "duration_sec": 480,
    "grade": "Grade 2",
    "thumb": "assets/thumbs/V042.jpg",
    "href": "/player.php?id=V042",
    "_search_text": "intro to fractions math grade 2 kid"
  }
]
```

## UI/UX Specification

| Element | Spec |
|---------|------|
| Search input | Full-width, large (48px height), autofocus on page load |
| Results grid | 2 columns on phone, 3 on tablet, 4 on desktop |
| Result card | Thumbnail (16:9) + title + meta line + Preview + Add to Plan buttons |
| Empty state | "No results for 'xyz'. Try a different keyword." + suggested categories |
| Loading state | Skeleton cards while index loads (<500ms expected) |
| Lesson Plan sidebar | Collapsible right panel (or bottom sheet on mobile) showing current plan items with drag-to-reorder |
| Plan item count | Badge on "My Plan" button: "My Plan (5)" |
| Share lesson plan | Modal with dropdown of other teachers on the server |
| Teacher-only gate | Redirect to `/login.php` if `$_SESSION['role'] !== 'teacher'` |

## Edge Cases & Failure Modes

| Case | Handling |
|------|----------|
| `search_index.json` missing | Show error: "Search index not available. Ask admin to rebuild the content index." |
| Index > 15 MB (very large catalog) | Split into per-segment files; load lazily |
| Zero results | Show empty state with search tips and category browse links |
| Typo in query ("tracktor") | v1: no fuzzy match — document limitation; v2 adds Levenshtein edit-distance 1 |
| Very large result set (500+ matches) | Paginate client-side: show first 24 results, "Show 24 more" button |
| Lesson plan with deleted content | `content_ids` may reference items no longer in `content_meta`; resolve gracefully: show "[Deleted content]" with strike-through |
| Lesson plan title collision | Allowed — plans are identified by `id`, not title |
| Non-teacher user accesses `/teacher.php` | PHP role check → redirect to `/login.php` |
| `content_ids` JSON column malformed | `json_decode` returns `null`; treat as empty plan |
| Share with non-existent teacher name | API returns `{error: "Teacher not found"}`; UI shows inline error |

## Test Plan

| # | Test | Expected |
|---|------|----------|
| T1 | Search "math" | Returns all math-tagged items across segments |
| T2 | Search "grade 2" | Returns items with "grade 2" in keywords or grade_level |
| T3 | Search "tractor" | Returns farming videos with "tractor" in title or keywords |
| T4 | Search "zzzznothing" | Empty state message shown |
| T5 | Filter by Segment "kid" after search | Results narrow to kid segment only |
| T6 | Click "Add to Plan" on 3 videos | Plan badge shows "(3)", items appear in plan sidebar |
| T7 | Remove item from plan | Item removed; badge decrements |
| T8 | Save plan as "Week 3 – Fractions" | Plan appears in "My Lesson Plans" list |
| T9 | Share plan with "Teacher Fatou" | Fatou sees plan in her list as "Week 3 – Fractions (shared)" |
| T10 | Delete a video from disk; load plan containing it | "[Deleted content]" shown with strike-through |
| T11 | Non-teacher user visits `/teacher.php` | Redirected to `/login.php` |
| T12 | Search index file missing | User-friendly error message, no PHP fatal |
| T13 | 500 search results | First 24 shown, "Show more" loads next 24 |
| T14 | `build_search_index.php` runs after adding 10 new videos | New videos appear in search results |

## Dependencies
- **Spec 03 (Simple Name Login):** role gate requires `$_SESSION['role'] === 'teacher'`; teacher `user_id` required for plan ownership
- **`content_meta` table:** must be designed and populated (new requirement surfaced by this spec)
- **`search_index.json`:** build script must run as part of content ingestion workflow
- **Spec 02 (Continue Watching):** player links from search results must pass `content_id` to the same player page

## Estimated Effort

| Task | Estimate |
|------|----------|
| Search UI (input, filters, results grid) | 1.5 days |
| Client-side search against JSON index | 1 day |
| Index build script (`build_search_index.php`) | 1 day |
| Lesson plan CRUD (API + UI) | 1.5 days |
| Plan sharing feature | 0.75 days |
| Edge cases + empty states | 0.5 days |
| Test + bug fix | 1 day |
| **Total** | **7.25 days** |

## Open Questions
1. **[BLOCKING]** Does a `content_meta` DB table exist, or does content currently live only on the filesystem? If filesystem-only, the index builder must scan directories and parse filenames — define naming convention.
2. **[BLOCKING]** What fields are available per content item? (title, grade level, keywords, segment, duration?) Required to define the index structure.
3. Should lesson plans support ordering of content items (drag-to-reorder), or is insertion order sufficient for v1?
4. Should teachers be able to see and duplicate other teachers' public lesson plans, or only plans explicitly shared with them?
5. Is there a maximum number of items per lesson plan? (Suggest capping at 50 for v1.)
6. Is the index rebuild triggered manually, scheduled (cron), or automatically on content upload?
