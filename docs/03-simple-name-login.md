# Simple Name Login

## Summary
- Users pick their name from an A–Z alphabetical grid (or type to filter); no password required — designed for low-literacy, multi-user shared devices
- New users tap "I'm new" to enter their name once; the system handles duplicate names by appending a last initial or number
- PHP session carries `user_id`, `display_name`, and `role` (student/teacher); supports explicit user-switching
- Teacher role is selected at login time and gates access to teacher-only features throughout the app

## User Story
> As a **child learner** on a shared classroom tablet, I want to tap my name from a visual list so I can get to my own content and history without typing a password.

> As a **teacher** arriving at a new classroom session, I want to switch from the previous student's session to my own account in 2 taps so I don't continue watching in someone else's context.

> As a **new student** using EduPak for the first time, I want to add my name to the list in one step so I don't have to create a full account.

## Technical Approach

### Frontend (HTML/CSS/JS)

**UI flow:**

```
/login.php
  └── A–Z tab strip + scrollable name grid
        ├── Tap name → confirm dialog ("Is this you, Amara?") → POST /login.php
        ├── Type to filter → name grid narrows in real-time (JS, no server call)
        └── "I'm new" button → /register.php → enter name + role → POST /register.php
```

**Name picker grid:**
```html
<div class="name-picker">
  <div class="az-strip" role="tablist" aria-label="Filter by letter">
    <button class="az-btn" data-letter="A" role="tab">A</button>
    <!-- ... B through Z ... -->
    <button class="az-btn active" data-letter="all" role="tab">All</button>
  </div>

  <input class="name-search" type="search" placeholder="Type your name…"
         aria-label="Search for your name" autocomplete="off" autocorrect="off">

  <div class="name-grid" role="list">
    <button class="name-card" data-user-id="42" role="listitem"
            aria-label="Select Amara">
      <span class="name-card__avatar" aria-hidden="true">A</span>
      <span class="name-card__label">Amara</span>
    </button>
    <!-- ... -->
  </div>

  <button class="btn btn--new" onclick="location='/register.php'">
    ✚ I'm new
  </button>
</div>
```

**CSS:**
```css
.name-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 10px;
  padding: 10px;
}
@media (min-width: 600px) { .name-grid { grid-template-columns: repeat(4, 1fr); } }
@media (min-width: 900px) { .name-grid { grid-template-columns: repeat(6, 1fr); } }

.name-card {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  padding: 12px 8px;
  border-radius: 10px;
  border: 2px solid #e0e0e0;
  background: #fff;
  min-height: 44px;       /* touch target */
  cursor: pointer;
  font-size: 0.9rem;
  font-weight: 600;
}
.name-card:active { transform: scale(0.95); }

.name-card__avatar {
  width: 40px; height: 40px;
  border-radius: 50%;
  background: var(--avatar-color, #6C63FF);
  color: #fff;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.2rem;
  font-weight: 700;
}

.az-strip {
  display: flex;
  overflow-x: auto;
  gap: 4px;
  padding: 8px 12px;
  scrollbar-width: none;
}
.az-btn {
  flex: 0 0 32px;
  height: 32px;
  border-radius: 6px;
  border: 1px solid #ccc;
  background: #f5f5f5;
  font-weight: 700;
  min-height: 44px;
  cursor: pointer;
}
.az-btn.active { background: #6C63FF; color: #fff; border-color: #6C63FF; }
```

**JS — client-side filter (no server round-trip):**
```js
const cards = document.querySelectorAll('.name-card');
const search = document.querySelector('.name-search');
const azBtns = document.querySelectorAll('.az-btn');

function filterNames(letter, query) {
  cards.forEach(card => {
    const name = card.querySelector('.name-card__label').textContent.toLowerCase();
    const matchLetter = letter === 'all' || name.startsWith(letter.toLowerCase());
    const matchQuery  = name.includes(query.toLowerCase());
    card.hidden = !(matchLetter && matchQuery);
  });
}

let activeLetter = 'all';
azBtns.forEach(btn => {
  btn.addEventListener('click', () => {
    azBtns.forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    activeLetter = btn.dataset.letter;
    filterNames(activeLetter, search.value);
  });
});
search.addEventListener('input', () => filterNames(activeLetter, search.value));
```

**Confirmation dialog (before session is set):**
```html
<dialog id="confirm-dialog">
  <p>Is this you?</p>
  <p class="confirm-dialog__name" id="confirm-name"></p>
  <form method="POST" action="/login.php">
    <input type="hidden" name="user_id" id="confirm-user-id">
    <button type="submit" class="btn btn--primary">Yes, that's me</button>
    <button type="button" onclick="this.closest('dialog').close()">Not me</button>
  </form>
</dialog>
```

### Backend (PHP/MySQL)

**`login.php` — POST handler:**
```php
session_start();
$user_id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
if (!$user_id) { header('Location: /login.php?err=invalid'); exit; }

$stmt = $pdo->prepare("SELECT id, display_name, user_type FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();
if (!$user) { header('Location: /login.php?err=notfound'); exit; }

$_SESSION['user_id']      = $user['id'];
$_SESSION['display_name'] = $user['display_name'];
$_SESSION['role']         = $user['user_type'];
$_SESSION['login_time']   = time();

header('Location: /'); exit;
```

**`register.php` — new user POST handler:**
```php
$name = trim(filter_input(INPUT_POST, 'name', FILTER_SANITIZE_SPECIAL_CHARS));
$role = filter_input(INPUT_POST, 'role', FILTER_SANITIZE_SPECIAL_CHARS);
$allowed_roles = ['kid', 'teen', 'adult', 'teacher'];
if (!in_array($role, $allowed_roles)) $role = 'kid';

// Resolve name collision
$name = resolveNameConflict($pdo, $name);

$stmt = $pdo->prepare("INSERT INTO users (display_name, user_type) VALUES (?, ?)");
$stmt->execute([$name, $role]);
$new_id = $pdo->lastInsertId();

// Set session same as login
$_SESSION['user_id']   = $new_id;
// ... redirect to /
```

**Name collision resolver:**
```php
function resolveNameConflict(PDO $pdo, string $name): string {
    $stmt = $pdo->prepare("SELECT display_name FROM users WHERE display_name LIKE ?");
    $stmt->execute([$name . '%']);
    $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array($name, $existing)) return $name;  // no conflict

    // Try "Name A", "Name B" ... using last-initial convention
    foreach (range('A', 'Z') as $letter) {
        $candidate = $name . ' ' . $letter;
        if (!in_array($candidate, $existing)) return $candidate;
    }

    // Fallback: append incrementing number
    $i = 2;
    while (in_array($name . ' ' . $i, $existing)) $i++;
    return $name . ' ' . $i;
}
```

**Session persistence:**
```php
// In php.ini or via ini_set at top of every page:
ini_set('session.gc_maxlifetime', 86400 * 7);   // 7-day inactivity timeout
session_set_cookie_params([
    'lifetime' => 86400 * 7,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
]);
```

**[ASSUMPTION]** Cookie-based PHP sessions are used (not localStorage). This keeps auth server-side and works across page loads without JS dependency.

**User-switching:** A persistent "Switch User" button in the header POSTs to `logout.php`:
```php
// logout.php
session_start();
session_destroy();
setcookie(session_name(), '', time() - 3600, '/');
header('Location: /login.php'); exit;
```

### Data Model

```sql
-- Existing users table (from schema.sql):
CREATE TABLE IF NOT EXISTS users (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    display_name VARCHAR(100) NOT NULL,
    user_type    ENUM('kid','teen','adult','teacher') DEFAULT 'kid',
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_active  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

**Required additions:**
```sql
-- Index for fast A-Z listing and name-collision lookups
ALTER TABLE users ADD INDEX idx_display_name (display_name);

-- Optional: avatar color for consistent visual identity
ALTER TABLE users ADD COLUMN avatar_color CHAR(7) DEFAULT '#6C63FF';
```

**[ASSUMPTION]** `user_type` ENUM covers all needed roles. If "parent" or "admin" roles are needed later, the ENUM must be altered.

## UI/UX Specification

| Element | Spec |
|---------|------|
| Name card tap target | Min 44×44px (enforced via `min-height` and padding) |
| Avatar | First letter of name in a colored circle; color derived from `id % N` color palette |
| A–Z strip | Horizontal scroll, 32px buttons with 44px height for touch |
| Search input | Filters visible cards client-side instantly (no round-trip) |
| "I'm new" button | Visually distinct (outlined + icon), sits below the grid |
| Role selector (register) | 4 large icon tiles (Kid / Teen / Adult / Teacher) — same visual language as Spec 01 |
| Confirm dialog | `<dialog>` element — blocks background interaction |
| Max users shown on one screen | **[ASSUMPTION]** 200 max before pagination is needed |
| Session indicator | Top of every page shows "👤 Amara · Switch" |

## Edge Cases & Failure Modes

| Case | Handling |
|------|----------|
| Duplicate name "Kofi" | Resolver returns "Kofi A" (or "Kofi 2" if A–Z exhausted) |
| Session expires (7-day timeout on shared device) | User sees login screen on next visit; history preserved in DB |
| User tries to register with empty name | Client + server validation; "Please enter your name" error |
| Name with special chars (e.g., `<script>`) | `FILTER_SANITIZE_SPECIAL_CHARS` on input; `htmlspecialchars()` on output |
| 500+ users (large school) | A–Z filter and search handle this; grid virtualization not needed until ~1000+ |
| Teacher role forgetting to switch back | "Switch User" always visible; session auto-expires |
| DB unavailable during login | PHP shows error page: "Cannot connect — ask your teacher to restart the server" |
| Two users with near-identical names | Collision resolver handles exact matches only; "Kofi" and "Kofi K" can coexist |
| User accidentally taps wrong name | Confirmation dialog prevents accidental login |

## Test Plan

| # | Test | Expected |
|---|------|----------|
| T1 | Load `/login.php` with 20 users registered | All names visible in alphabetical grid |
| T2 | Tap letter "M" in A–Z strip | Only names starting with M shown |
| T3 | Type "am" in search box | Cards filter to names containing "am" |
| T4 | Tap "Amara" → confirm dialog → "Yes, that's me" | Session set, redirect to `/`, header shows "Amara" |
| T5 | Register new user "Kofi", then register another "Kofi" | Second becomes "Kofi A" |
| T6 | Register as Teacher role | `user_type = 'teacher'` in DB; teacher-gated pages accessible |
| T7 | Tap "Switch User" | Session destroyed, redirect to login |
| T8 | Wait 7 days (simulate `session.gc_maxlifetime` expiry) | Auto-redirect to login; watch history preserved |
| T9 | POST `/login.php` with non-existent `user_id` | Redirect to `/login.php?err=notfound` |
| T10 | Submit registration with name `<img onerror=alert(1)>` | Stored safely escaped; renders as text, no XSS |
| T11 | Load login on 320px phone | Grid readable, A–Z strip scrolls, search input usable |
| T12 | Load on projector (1920×1080) | 6-column grid, large tap targets |

## Dependencies
- **Spec 02 (Continue Watching):** requires `$_SESSION['user_id']`
- **Spec 01 (Visual Home Tiles):** segment tiles can pre-filter based on `$_SESSION['role']`
- **Spec 05 (Teacher Content Finder):** teacher-role gate must check `$_SESSION['role'] === 'teacher'`
- **Spec 06 (Mode Switch):** mode preference stored per session, which requires this login flow to exist

## Estimated Effort

| Task | Estimate |
|------|----------|
| Login page HTML/CSS (grid, A–Z, search) | 1.5 days |
| PHP login + session handler | 0.5 days |
| Registration + name-collision resolver | 0.75 days |
| Confirmation dialog | 0.25 days |
| Session persistence + switch-user flow | 0.5 days |
| Test + bug fix | 1 day |
| **Total** | **4.5 days** |

## Open Questions
1. **[BLOCKING]** Is there a max number of users per device? (school setup may want to cap registrations per server instance)
2. **[BLOCKING]** Should teachers require any additional verification (PIN? physical key tap?) even if no full password?
3. Should user avatars support custom photos (camera capture), or initials-only for v1?
4. Is there a "delete my account" / "remove my name" flow needed (GDPR-adjacent for institutional deployments)?
5. Should the login page show recently active users first, or strictly alphabetical?
