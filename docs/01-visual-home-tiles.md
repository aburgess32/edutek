# EduTek Home Page: Library Navigation

> **Status: Current feature specification**
>
> This document describes the current EduTek Home page implemented in [`htdocs/index.php`](../htdocs/index.php).
>
> The Home page is the primary starting point for offline learning. It provides a direct library search, clear navigation by content type, and links to broader browsing options.
>
> **Last aligned with implementation:** 2026-09-06

---

## Purpose

The EduTek Home page helps learners, teachers, and guests quickly begin using locally available learning resources.

The page is intentionally simple. It does not attempt to display every category, service, user-specific activity, or administrative feature on the initial screen. Instead, it directs users to the most useful starting paths:

1. Search for a specific topic, title, skill, or subject.
2. Browse video learning resources.
3. Browse audiobooks.
4. Browse books and PDF resources.
5. Browse music.
6. Open locally available learning tools.
7. Browse the full topic directory when a user wants to explore.

The Home page must remain useful when the EduTek device has no internet connection.

---

## Current page structure

The Home page contains three main sections.

| Section | Purpose |
|---|---|
| Hero and library search | Introduces EduTek and gives users immediate access to the main library search |
| Content-type cards | Gives users five clear starting destinations based on the type of resource they want |
| Explore-more actions | Provides quick links to the complete topic directory and the library search page |

The page is rendered by:

```text
htdocs/index.php
```

Its current page-level styles are provided by:

```text
htdocs/css/index.css
```

The shared navigation and footer are rendered by:

```text
htdocs/navhome.php
htdocs/footer.php
```

---

## Hero and search

The top of the Home page presents the EduTek Global identity and a short offline-learning message.

### Current visible text

| Element | Current text |
|---|---|
| Eyebrow | `EduTek Global` |
| Main heading | `Explore Learning Resources` |
| Supporting text | `Learn, teach, and explore — even without internet.` |
| Search placeholder | `Search videos, books, skills, or subjects` |
| Search button | `Search` |

### Search behavior

The Home-page search form:

- Uses the HTTP `GET` method.
- Sends the search term using the query parameter `q`.
- Sends users to:

  ```text
  result.php?q=<search-term>
  ```

- Is intended for library-wide topic, title, skill, and subject discovery.
- Must remain usable with keyboard-only navigation.
- Must work without JavaScript because it is a standard HTML form submission.

The input has an accessible label equivalent to:

```text
Search the learning library
```

Do not change the search endpoint, parameter name, or button behavior without verifying that `result.php` accepts the revised request.

---

## Content-type cards

The Home page contains five primary cards. Each card is a complete link and is designed to give a learner one clear next action.

| Card | Current destination | User purpose |
|---|---|---|
| Watch Videos | `directory.php` | Browse video lessons, demonstrations, documentaries, and topics |
| Audiobooks | `audiobooks.php` | Browse and search audiobook collections |
| Books & PDFs | `books.php` | Browse and search book and PDF categories |
| Music | `music.php` | Browse songs, playlists, and audio collections |
| Learning Tools | `tools.php` | Open offline learning applications, interactive tools, and reference resources |

### Card content

Each card contains:

- A content-type icon.
- A short content-type title.
- A plain-language description.
- A visual arrow indicating navigation.
- An accessible label describing the link destination.

Current card descriptions are:

| Card | Current description |
|---|---|
| Watch Videos | `Lessons, demonstrations, and documentaries.` |
| Audiobooks | `Listen to stories, learning, and ideas.` |
| Books & PDFs | `Read guides, textbooks, stories, and reference materials.` |
| Music | `Explore songs, playlists, and audio collections.` |
| Learning Tools | `Use offline apps, interactive learning, and reference tools.` |

### Navigation rule

Home cards must point to stable, maintained application pages. Do not change these cards to point directly to physical content folders, host-specific file paths, or external web URLs unless that behavior is explicitly tested for offline deployments.

---

## Explore-more actions

The bottom section helps users choose between broad browsing and search.

### Current visible text

| Element | Current text |
|---|---|
| Section eyebrow | `Explore more` |
| Section heading | `Find the right resource` |
| Supporting text | `Browse every available collection or use search to find a specific topic, subject, title, or skill.` |
| Primary action | `Browse All Topics` |
| Secondary action | `Search the Library` |

### Current destinations

| Action | Destination | Purpose |
|---|---|---|
| Browse All Topics | `directory.php` | Opens the full video/topic directory |
| Search the Library | `result.php` | Opens the search page without a prefilled query |

---

## Responsive design

The current Home-page layout is responsive and uses progressively fewer columns as screen width decreases.

| Approximate viewport | Card layout |
|---|---|
| Desktop wider than 1040px | Five cards in one row |
| Medium screens at or below 1040px | Three-card grid layout |
| Small screens at or below 720px | Two-card grid layout |
| Very narrow screens at or below 390px | One card per row |

On screens at or below approximately 720px:

- The hero becomes more compact.
- The search input and Search button stack vertically.
- The content cards use reduced padding and icon size.
- The final action buttons expand to the available width.
- Decorative hero artwork is removed to preserve space and readability.

Do not treat these breakpoint values as a design contract if the current stylesheet changes. Verify `htdocs/css/index.css` before documenting future layout behavior.

---

## Accessibility requirements

The Home page must preserve the following behavior:

- Search input has a visible or screen-reader-accessible label.
- Search can be completed using the keyboard.
- Every content-type card has an accessible destination label.
- Links and buttons have visible focus feedback.
- Text remains readable against the hero and card backgrounds.
- Card descriptions supplement icons; icons are not the only communication method.
- The layout remains usable at narrow viewport widths.
- Decorative icons and arrows are hidden from assistive technologies where they add no useful information.

Before changing the page, test:

1. Keyboard tab order from the page header through the footer.
2. Search form submission with Enter.
3. Content-card navigation with keyboard.
4. Browser zoom at 200%.
5. Narrow mobile viewport layout.
6. A screen reader’s announced label for each primary link.

---

## Offline-first requirements

The Home page is part of an offline educational platform.

Therefore:

- Do not add a required CDN dependency to this page.
- Do not rely on externally hosted fonts, JavaScript, CSS, images, or APIs for core page functionality.
- The search form, cards, shared navigation, and page layout must work when the local EduTek device is disconnected from the internet.
- Links must resolve to resources hosted by the local EduTek application or a locally configured service.
- Any new external destination must be clearly identified and must degrade safely when offline.

---

## Removed Home-page features

The following features are **not currently rendered on the Home page**:

| Former feature | Current status |
|---|---|
| Continue Watching row | Not displayed on Home; see [Continue Watching](02-continue-watching.md) |
| Personalized watch-progress cards | Not displayed on Home |
| My Assignments section | Not displayed on Home |
| Student assignment JavaScript loading from Home | Not displayed on Home |
| Choose Your Path segment tiles | Not displayed on Home |
| Generic all-content card grid | Not displayed on Home |
| Home-page directory/category anchor navigation | Not displayed on Home |

Do not document any of these as current Home-page functionality unless their code is intentionally restored, tested, and released.

---

## Related pages

| Page | Role |
|---|---|
| `directory.php` | Browsing video resources and topics |
| `result.php` | Library search results |
| `audiobooks.php` | Audiobook browsing and folder-name search |
| `books.php` | Book/PDF category browsing and search |
| `Comic_books.php` | Comic book, manga, superhero, and Marvel PDF catalog |
| `music.php` | Music browsing |
| `tools.php` | Locally configured learning tools |
| `launch-khan.php` | Local Khan Interactive launcher, when configured |

---

## Maintenance checklist

Update this document whenever a Home-page change affects any of the following:

- Visible heading, supporting text, button text, or card text.
- Search endpoint, request method, query parameter, or behavior.
- Number, label, description, order, or destination of content-type cards.
- Responsive grid behavior or breakpoints.
- Accessibility labels, focus behavior, or keyboard navigation.
- Offline dependency requirements.
- Whether a personalized, administrative, or browsing feature is added to or removed from Home.

Before changing this document’s **Current** status, verify the page in the supported local deployment environment and confirm that the implementation matches every documented destination and interaction.