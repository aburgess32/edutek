# Continue Watching

> **Status: Historical feature specification**
>
> Continue Watching is **not currently displayed on the EduTek Home page**.
>
> This document is retained to preserve the original design and implementation intent for a personalized video-resume experience. It may be useful if the feature is restored or implemented on another page in the future.
>
> For the current Home-page experience, use [README.md](../README.md) and review [`htdocs/index.php`](../htdocs/index.php).
>
> **Current Home page:** Library search, content-type cards for Videos, Audiobooks, Books & PDFs, Music, and Learning Tools, plus links to Browse All Topics and Search the Library.

---

## Current product behavior

As of the current implementation:

- The Home page does not query or render `watch_history`.
- The Home page does not display a Continue Watching row.
- The Home page does not show personalized resume progress.
- The Home page does not show a visual progress bar for partially watched videos.
- The Home page does not render Continue Watching cards from a logged-in user's viewing history.
- The application may still retain watch-history data and related code elsewhere in the project.
- Do not add or update UI documentation claiming that Continue Watching appears on the Home page unless the feature is intentionally restored and tested.

The current Home page is intentionally organized as an offline learning-library starting point:

1. Search the learning library.
2. Browse Videos.
3. Browse Audiobooks.
4. Browse Books & PDFs.
5. Browse Music.
6. Open Learning Tools.
7. Browse all topics or search the full library.

---

## Historical feature intent

Continue Watching was designed as a personalized section for authenticated, non-guest users. Its purpose was to help a learner resume partially watched video content without having to find the video again through search or the topic directory.

The intended experience was:

1. A signed-in learner opens the EduTek Home page.
2. EduTek reads that learner's saved viewing history.
3. EduTek identifies recently watched videos that are not essentially complete.
4. EduTek displays a horizontal collection of resume cards.
5. Each card links directly back to the selected video.
6. A visual progress indicator communicates how much of the video has been watched.

This was a personalized feature. It was not intended for guest users.

---

## Historical eligibility rules

The former Home-page implementation applied the following conditions before rendering a Continue Watching card:

| Condition | Historical behavior |
|---|---|
| User is signed in | Required |
| User is a guest | Continue Watching was not rendered |
| Video has a known duration | Required |
| Video has watch-history data | Required |
| Video is 95% or more complete | Excluded |
| Multiple watch-history rows exist for one video | The most recently watched row was used |
| Maximum cards | Up to five cards were displayed |

The 95% completion cutoff was intended to keep nearly completed videos from filling the resume list.

---

## Historical data source

The previous Home-page implementation used the `watch_history` table.

The query concept was:

1. Filter history records by the signed-in user's ID.
2. Ignore records with no usable `duration_seconds`.
3. Select the most recent `last_watched` entry for each `content_id`.
4. Order results by most recent viewing activity.
5. Limit the result set.
6. Calculate progress percentage:

\[
\text{progress percent} =
\frac{\text{progress seconds}}{\text{duration seconds}}
\times 100
\]

7. Exclude entries whose calculated completion percentage was 95% or greater.

The historical card data included:

- `content_id`
- `content_title`
- `thumbnail_path`
- `progress_seconds`
- `duration_seconds`
- `last_watched`

---

## Historical card behavior

A Continue Watching card was designed to include:

- A video thumbnail when one exists.
- A fallback video placeholder when no thumbnail exists.
- The content category.
- The content subcategory.
- The video title.
- A visual watch-progress bar.
- A direct link to the appropriate `watch.php` route.

The card was intended to preserve the existing encrypted navigation parameter pattern used elsewhere in EduTek.

When restoring this feature, ensure that the generated watch link uses the same current URL and content-path conventions as the active video browsing and playback code. Do not copy old encrypted-link logic without verifying it against the current implementation.

---

## Historical accessibility requirements

If Continue Watching is restored, each card should:

- Use a meaningful accessible name, such as:

  ```text
  Continue: Introduction to Fractions — 45% watched
  ```

- Show a visible keyboard focus indicator.
- Be reachable and usable with a keyboard.
- Preserve a minimum interactive target size of 44 by 44 CSS pixels where practical.
- Use meaningful thumbnail alternative text.
- Not depend only on color to communicate watch progress.
- Avoid making the progress bar the only representation of completion state.

---

## Historical responsive behavior

The original styling used a horizontal scrolling card row.

The intended responsive behavior was:

| Screen type | Intended behavior |
|---|---|
| Small mobile screens | Horizontal swipeable list of compact cards |
| Tablet screens | Wider horizontal cards with touch scrolling |
| Desktop screens | Wider cards with visible hover and keyboard-focus feedback |
| No thumbnail available | A consistent visual placeholder |

If restored, test on:

- Narrow mobile viewport.
- Tablet viewport.
- Typical laptop viewport.
- Large desktop viewport.
- Keyboard-only navigation.
- Touch scrolling.
- Screen reader navigation.

---

## Requirements before restoration

Do not restore Continue Watching by only uncommenting or copying historical code. Before making it current again, complete all of the following:

1. Confirm that `watch_history` is present in the current database schema.
2. Confirm that watch-progress updates are still written correctly during video playback.
3. Confirm that `content_id`, file paths, and encrypted watch links match current content-indexing conventions.
4. Confirm that thumbnails resolve correctly for Docker and XAMPP deployments.
5. Confirm that guest accounts do not receive personalized viewing-history content.
6. Confirm that completed videos are excluded according to the chosen completion threshold.
7. Add or update automated tests for history selection, resume-link generation, and progress calculation.
8. Test the restored section in the current Home-page layout.
9. Update the README and user-facing documentation only after the feature is visible and verified.
10. Change this document’s status from **Historical feature specification** to **Current feature specification**.

---

## Suggested future implementation approach

If the feature returns, prefer a separated implementation rather than placing a large database query and rendering block directly inside `htdocs/index.php`.

A maintainable structure would be:

```text
htdocs/
├── includes/
│   ├── continue-watching.php
│   └── tiles.php
├── api/
│   └── watch-history.php
├── js/
│   └── continue-watching.js
└── css/
    └── continue-watching.css
```

Recommended responsibilities:

| Component | Responsibility |
|---|---|
| `includes/continue-watching.php` | Server-side query and safe initial rendering, if needed |
| `api/watch-history.php` | Authenticated JSON endpoint for history data |
| `js/continue-watching.js` | Progressive enhancement, loading state, and client interaction |
| `css/continue-watching.css` | Isolated responsive styles |
| PHPUnit tests | Query, eligibility, progress, and link-generation tests |
| Playwright tests | Signed-in user flow and Home-page rendering verification |

This separation reduces the chance that Home-page changes accidentally remove or break the feature again.

---

## Documentation update rule

Use the following rule when updating project documentation:

> A feature is documented as active only when it is available in the current application, reachable through the documented UI or URL, and verified in the supported deployment environment.

Until Continue Watching meets that rule again, documentation must describe it as historical or planned—not as a current Home-page capability.