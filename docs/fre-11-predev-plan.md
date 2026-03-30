# FRE-11 Pre-Dev Plan: Simple Name Login

## Scope Summary

Two distinct login flows sharing the same entry point, optimized for the audience:

- **Students/Explorers**: Name → Age range tile → Pick a avatar_name from a visual grid → Done
- **Teachers**: Email + password → Full account with admin capabilities

No passwords for students. Fun, fast, visual. Teachers get proper auth stored locally in MySQL.

---

## Key Decisions (Locked)

- **Avatar Names**: Pre-loaded grid — student picks the one they like
- **Name style**: Both African-origin AND universal cool names combined
- **Teacher auth**: Local DB only (email + bcrypt hash) — fully offline
- **Age input**: Visual age range tiles (Under 10 / 10-14 / 15-19 / 20+) — no typing
- **Session**: PHP cookie-based sessions, 7-day timeout

---

## User Flows

### Flow A: Student / Explorer / Learner

```
┌─────────────────────────────────────────────┐
│  /login.php (entry point)                    │
│                                              │
│  ┌─────────────┐  ┌──────────────────────┐  │
│  │  I'm a      │  │  I'm a Teacher       │  │
│  │  Learner    │  │                      │  │
│  └──────┬──────┘  └──────────┬───────────┘  │
│         │                    │               │
│         ▼                    ▼               │
│  STEP 1: What's your        /teacher-login   │
│  first name?                .php             │
│  ┌──────────────┐                            │
│  │ [  Name   ]  │                            │
│  └──────┬───────┘                            │
│         ▼                                    │
│  STEP 2: How old are you?                    │
│  ┌────┐ ┌─────┐ ┌─────┐ ┌────┐             │
│  │<10 │ │10-14│ │15-19│ │20+ │              │
│  └──┬─┘ └──┬──┘ └──┬──┘ └─┬──┘             │
│     └───────┴───────┴──────┘                 │
│         ▼                                    │
│  STEP 3: Pick your Avatar Name!                 │
│  ┌──────┐ ┌──────┐ ┌──────┐ ┌──────┐       │
│  │Simba │ │Nova  │ │Kibo  │ │Storm │       │
│  │ 🦁   │ │ ⭐   │ │ 🏔️   │ │ ⚡   │       │
│  │Phoenix│ │Zuri  │ │Atlas │ │Amani │       │
│  │ 🔥   │ │ 💎   │ │ 🌍   │ │ 🕊️   │       │
│  └──────┘ └──────┘ └──────┘ └──────┘       │
│         ▼                                    │
│  "Welcome, Kofi! You are now SIMBA 🦁"      │
│  → redirect to /                             │
└─────────────────────────────────────────────┘
```

### Flow B: Returning User (Student)

```
┌─────────────────────────────────────────────┐
│  /login.php                                  │
│                                              │
│  Welcome back! Tap your name:                │
│                                              │
│  ┌──────────┐ ┌──────────┐ ┌──────────┐    │
│  │ SIMBA 🦁 │ │ NOVA ⭐  │ │ KIBO 🏔️  │    │
│  │ Kofi     │ │ Amara    │ │ James    │    │
│  └──────────┘ └──────────┘ └──────────┘    │
│                                              │
│  [Search...]  [I'm new]  [I'm a Teacher]    │
│         ▼                                    │
│  "Is this you, Kofi (SIMBA)?"               │
│  [Yes, that's me!]  [Not me]                │
│         ▼                                    │
│  → redirect to /                             │
└─────────────────────────────────────────────┘
```

### Flow C: Teacher

```
┌─────────────────────────────────────────────┐
│  /teacher-login.php                          │
│                                              │
│  Teacher Login                               │
│  ┌──────────────────────┐                    │
│  │  Email               │                    │
│  └──────────────────────┘                    │
│  ┌──────────────────────┐                    │
│  │  Password            │                    │
│  └──────────────────────┘                    │
│  [Log In]                                    │
│                                              │
│  New teacher? [Create Account]               │
│         ▼                                    │
│  /teacher-register.php                       │
│  Full name + Email + Password + Confirm      │
│  → redirect to /                             │
└─────────────────────────────────────────────┘
```

---

## Avatar Name Library

~60 avatar_names organized by vibe. Each has a name, an emoji icon, and a color. Mix of African-origin and universal names.

### Sample Library

| Avatar Name | Origin | Icon | Color | Meaning |
|----------|--------|------|-------|---------|
| Simba | Swahili | 🦁 | #FF6B35 | Lion — brave, strong |
| Zuri | Swahili | 💎 | #E91E63 | Beautiful |
| Kibo | Swahili | 🏔️ | #1A535C | Peak of Kilimanjaro |
| Amani | Swahili | 🕊️ | #4ECDC4 | Peace |
| Jelani | Swahili | ⚡ | #F7C948 | Mighty |
| Keza | Kinyarwanda | 🌸 | #FF69B4 | Beautiful |
| Imara | Swahili | 🛡️ | #8B5CF6 | Firm, strong |
| Tendo | Luganda | 🙏 | #14B8A6 | Thankful |
| Nia | Swahili | 🎯 | #EF4444 | Purpose |
| Asante | Swahili | ❤️ | #E63946 | Thank you |
| Phoenix | Universal | 🔥 | #FF4500 | Rising from ashes |
| Nova | Universal | ⭐ | #FFD700 | New star |
| Storm | Universal | ⛈️ | #6366F1 | Powerful, dynamic |
| Atlas | Universal | 🌍 | #3B82F6 | World on shoulders |
| Blaze | Universal | 🔥 | #F97316 | Fire, passion |
| Titan | Universal | 💪 | #7C3AED | Unstoppable |
| Echo | Universal | 🔔 | #06B6D4 | Voice that carries |
| Arrow | Universal | 🏹 | #10B981 | Focused, precise |
| Zenith | Universal | 🌟 | #A855F7 | Highest point |
| Orbit | Universal | 🪐 | #6366F1 | Beyond this world |

Full library: 60 names stored in `htdocs/config/avatar-names.json`

### Avatar Name Rules
- Once picked, a avatar_name is taken — no two users can have the same one
- Avatar Name displayed prominently (it's their identity in the app)
- Real name stored in DB but avatar_name is what others see
- If all 60 are taken, system generates "[Name] the [Adjective]" combos

---

## Data Model

### Users table (updated)

```sql
CREATE TABLE IF NOT EXISTS users (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    display_name   VARCHAR(100) NOT NULL COMMENT 'Real first name',
    avatar_name       VARCHAR(50) DEFAULT NULL COMMENT 'Chosen avatar_name (students only)',
    avatar_icon  VARCHAR(10) DEFAULT NULL COMMENT 'Emoji icon for avatar_name',
    avatar_color CHAR(7) DEFAULT NULL COMMENT 'Accent color for avatar_name',
    email          VARCHAR(255) DEFAULT NULL COMMENT 'Teacher email (null for students)',
    password_hash  VARCHAR(255) DEFAULT NULL COMMENT 'Bcrypt hash (teachers only)',
    user_type      ENUM('student','teacher') DEFAULT 'student',
    age_range      ENUM('under_10','10_14','15_19','20_plus') DEFAULT NULL,
    avatar_color   CHAR(7) DEFAULT '#6C63FF',
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_active    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_avatar (avatar_name),
    UNIQUE KEY uq_email (email),
    INDEX idx_display_name (display_name),
    INDEX idx_user_type (user_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Session data

```php
$_SESSION['user_id']      = int;
$_SESSION['display_name'] = string;   // Real name
$_SESSION['avatar_name']     = string;   // Avatar Name (students) or null (teachers)
$_SESSION['role']         = string;   // 'student' or 'teacher'
$_SESSION['age_range']    = string;   // Age range (students only)
$_SESSION['login_time']   = int;      // Unix timestamp
```

---

## New Files

| File | Purpose |
|------|---------|
| `htdocs/login.php` | Entry point: returning user grid + "I'm new" / "I'm a Teacher" |
| `htdocs/register.php` | Student signup: name → age range → avatar_name picker |
| `htdocs/teacher-login.php` | Teacher email + password login |
| `htdocs/teacher-register.php` | Teacher account creation |
| `htdocs/logout.php` | Session destroy + redirect |
| `htdocs/includes/auth.php` | Auth helpers: requireLogin(), isTeacher(), getCurrentUser() |
| `htdocs/config/avatar-names.json` | 60 avatar_names with icons, colors, meanings |
| `htdocs/css/login.css` | Login/register page styles |
| `htdocs/js/login.js` | Client-side name filter, avatar_name picker, step wizard |
| `db/migrations/0004_update_users_table.sql` | Schema updates for avatar_names + teacher auth |

## Modified Files

| File | Changes |
|------|---------|
| `htdocs/navhome.php` | Add user indicator ("SIMBA 🦁 · Switch") |
| `htdocs/navbar.php` | Same user indicator |
| `htdocs/index.php` | Check session, redirect to login if no user |
| `htdocs/includes/security.php` | Add session validation helpers |

---

## Page Designs

### Login page (default — returning users)
- Grid of existing user cards showing: **avatar_name** (large), emoji icon, real name (small)
- Search bar to filter
- Cards sorted by last_active (most recent first)
- Two buttons at bottom: "I'm New" (student) and "I'm a Teacher" (teacher)
- Clean, friendly, colorful — each card uses the avatar_name's accent color

### Student registration (multi-step wizard)
- **Step 1**: "What's your first name?" — single text input, large, friendly
- **Step 2**: "How old are you?" — 4 large visual tiles (Under 10 / 10-14 / 15-19 / 20+), each with an aspirational image
- **Step 3**: "Pick your Avatar Name!" — grid of available avatar_names, each showing icon + name + color. Taken names greyed out. Tap to select, confirm.
- **Welcome screen**: "Welcome, [Name]! You are now [AVATAR NAME] [icon]" with a celebration animation
- All steps are client-side wizard (no page reload between steps)

### Teacher login
- Clean, professional form: email + password
- "Create Account" link → teacher registration
- Teacher registration: full name + email + password + confirm password
- Password requirements: min 8 chars (keep it simple for offline context)

---

## Session & Auth Logic

### Every page load (via includes/auth.php)
```
1. Check $_SESSION['user_id'] exists
2. If not → redirect to /login.php
3. If yes → update last_active in DB
4. Expose getCurrentUser() for templates
```

### Role gating
```php
// In includes/auth.php
function requireLogin() { ... }
function requireTeacher() { ... }
function isTeacher(): bool { ... }
function getCurrentUser(): ?array { ... }
function getUserDisplay(): string {
    // Returns "AVATAR NAME 🦁" for students, "Name" for teachers
}
```

### Switch user
- Always visible in nav: "[AVATAR NAME icon] · Switch"
- Tap "Switch" → logout.php → login.php
- Teachers: separate "Log Out" button

---

## Responsive Breakpoints

| Screen | User cards | Avatar Name picker | Age tiles |
|--------|-----------|-----------------|-----------|
| < 400px | 2 col | 2 col | 2 col (stacked) |
| 400-599px | 3 col | 3 col | 4 col (row) |
| 600-899px | 4 col | 4 col | 4 col (row) |
| 900px+ | 6 col | 5 col | 4 col (row) |

---

## Task Breakdown

| # | Task | Est | Depends |
|---|------|-----|---------|
| 1 | DB migration: update users table | 0.25d | — |
| 2 | avatar-names.json: 60 names with icons/colors | 0.25d | — |
| 3 | auth.php: session helpers, requireLogin, role gating | 0.5d | 1 |
| 4 | login.css: all login/register page styles | 0.5d | — |
| 5 | login.php: returning user grid + entry point | 1d | 1, 3, 4 |
| 6 | register.php: multi-step student wizard | 1d | 1, 2, 4 |
| 7 | teacher-login.php + teacher-register.php | 0.5d | 1, 3 |
| 8 | logout.php + switch user | 0.25d | 3 |
| 9 | login.js: wizard steps, name filter, avatar_name picker | 0.5d | 5, 6 |
| 10 | Update navs with user indicator | 0.25d | 3 |
| 11 | Wire login requirement to existing pages | 0.25d | 3 |
| 12 | Edge case hardening | 0.5d | all |
| 13 | Playwright tests | 0.5d | all |
| **Total** | | **~6d** | |

---

## Edge Cases

| Case | Handling |
|------|---------|
| Duplicate real name "Kofi" | Both can exist — avatar_names make them unique |
| All 60 avatar_names taken | Generate "[Name] the [Adjective]" combos (Brave, Swift, Wise, etc.) |
| Teacher tries student flow | "I'm a Teacher" button always visible |
| Student tries teacher login | No email/password → redirect back to student flow |
| Empty name submitted | Client + server validation |
| XSS in name field | htmlspecialchars + FILTER_SANITIZE_SPECIAL_CHARS |
| Teacher forgot password | No recovery flow (offline) — admin panel can reset |
| Session hijacking | httponly + samesite cookies, CSRF on all forms |
| 500+ users on one device | Search + pagination handles scale |
| DB down during login | Friendly error: "Cannot connect — ask your teacher" |

---

## Test Plan

| # | Test | Expected |
|---|------|----------|
| T1 | Load /login.php fresh (no users) | Shows "I'm New" and "I'm a Teacher" buttons |
| T2 | Student signup: name → age → avatar_name | Creates user, sets session, redirects to / |
| T3 | Return to /login.php | Shows new user card with avatar_name |
| T4 | Tap user card → confirm → login | Session set, redirect to / |
| T5 | Pick avatar_name already taken | Avatar Name appears greyed out / unavailable |
| T6 | Teacher register: name + email + password | Creates teacher account |
| T7 | Teacher login with correct credentials | Session set with role=teacher |
| T8 | Teacher login with wrong password | Error message, no session |
| T9 | Access /index.php without session | Redirect to /login.php |
| T10 | Tap "Switch" in nav | Session destroyed, back to login |
| T11 | XSS in name field | Stored safely, rendered as text |
| T12 | Mobile (320px) avatar_name grid | 2 columns, all tappable |
| T13 | Search filter on returning user grid | Filters by avatar_name and real name |

---

## Open Items (non-blocking)

1. Should login be required on ALL pages, or just pages that need user context (watch history, etc.)?
2. Avatar photos — skip for v1, use avatar_name icon + color as visual identity
3. "Delete my account" flow — defer to admin panel
