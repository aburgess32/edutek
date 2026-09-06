# Teacher Content Finder and Content Indexing

> **Status: Current feature specification**
>
> This document describes the current EduTek content-discovery experience for teachers and the related content-index maintenance workflows.
>
> It is aligned with the current application structure, including the topic directory, library search, Books and Audiobooks browsing, Comic Books catalog, teacher-protected reindexing, and the device-local indexing shortcut.
>
> **Last aligned with implementation:** 2026-09-06

---

## Purpose

EduTek is designed for an offline or low-connectivity learning environment. Teachers need reliable ways to:

1. Find educational content already available on the device.
2. Browse content by subject, category, and collection.
3. Search the indexed library for a topic, skill, title, or keyword.
4. Confirm that newly added local media has been indexed.
5. Verify which files were newly indexed or updated after maintenance.

These goals are supported by separate browsing, searching, and indexing workflows. They should not be treated as the same operation.

---

## Current content-finding paths

Teachers can find existing learning resources through the following application pages.

| Path | Page | Primary purpose |
|---|---|---|
| Home search | `index.php` → `result.php?q=<query>` | Search the broader learning library |
| Video/topic directory | `directory.php` | Browse available video resources and topics |
| Search results | `result.php` | View library-wide search matches and navigate to matching content |
| Audiobooks | `audiobooks.php` | Browse and search audiobook folders |
| Books & PDFs | `books.php` | Browse and search book/PDF category folders |
| Comic Books | `Comic_books.php` | Browse comic, manga, superhero, supervillain, and Marvel-related PDFs |
| Music | `music.php` | Browse local music collections |
| Learning Tools | `tools.php` | Open locally configured interactive and reference tools |

The current Home page provides quick links to Videos, Audiobooks, Books & PDFs, Music, and Learning Tools. It also contains the main library search form.

---

## Search the learning library

Use the Home-page search field when you know all or part of a topic, subject, title, or skill.

### Steps

1. Open the EduTek Home page.
2. Select the search field.
3. Enter a search term.
4. Select **Search** or press `Enter`.
5. Review results on `result.php`.

The Home-page search uses a standard HTTP `GET` request:

```text
result.php?q=<search-term>
```

Because it uses a normal HTML form, the basic search submission should work even if JavaScript is unavailable.

### Search guidance

Use short, specific words first:

```text
fractions
geography
reading
science
algebra
history
```

If the results are too broad, add a second distinguishing term:

```text
solar system
civil rights
basic fractions
world geography
```

Search results depend on content that has already been indexed. If newly added material is missing, use the appropriate indexing workflow described later in this document.

---

## Browse video topics

Use the topic directory when you want to explore rather than search for a known term.

### Steps

1. Open the EduTek Home page.
2. Select **Watch Videos** or **Browse All Topics**.
3. Browse the topic directory at:

   ```text
   directory.php
   ```

4. Select a category, subcategory, or content item.
5. Open the selected learning resource.

The directory is the current primary browsing destination for video learning content. Do not document the removed Home-page segment tiles or directory anchor-navigation bar as active navigation features unless they are intentionally restored.

---

## Find books and PDFs

The Books page shows book-category folders that contain PDF files.

### Steps

1. Open the EduTek Home page.
2. Select **Books & PDFs**.
3. Browse the displayed categories.
4. Enter at least two characters into the category search field to filter the list.
5. Select a category to open its available PDF resources.

Current Books page:

```text
books.php
```

### Current discovery rules

- EduTek reads book categories from the configured Books content location.
- In Docker, the primary Books filesystem location is:

  ```text
  /content/Books
  ```

- A legacy fallback may use:

  ```text
  htdocs/videos/Books
  ```

- Only folders containing one or more `.pdf` files are displayed.
- Folders whose names begin with `_` are treated as maintenance folders and are hidden from the normal Books page.
- The Books search filters **category folder names**.
- Books search does not search inside PDF document text.

### Cover images

The Books page can request category thumbnail images through the local application. Missing images must not prevent a book category from opening.

Keep book category names clear and stable. The category folder name is visible to users and is used by the search/filter experience.

---

## Find audiobooks

The Audiobooks page is intended for browsing and quickly filtering audiobook folders.

### Steps

1. Open the EduTek Home page.
2. Select **Audiobooks**.
3. Browse available audiobook folders.
4. Type at least two characters into the search field.
5. Wait briefly for the search to apply, or press `Enter`.
6. Select the desired audiobook collection.

Current Audiobooks page:

```text
audiobooks.php
```

### Current search behavior

- The search uses the query parameter:

  ```text
  q
  ```

- The search matches audiobook folder names without case sensitivity.
- It is intended to help locate titles, authors, and series.
- A one-character query does not run; the interface prompts the user to type one more character.
- After typing stops, the page applies the search after a short delay.
- A search clears the current pagination position so results begin at page one.
- The **Clear search** link returns to the full audiobook listing.
- The search does not claim to search spoken audio, transcript content, or embedded audio metadata unless such behavior is separately implemented and documented.

---

## Find comic books

Comic Books are presented as a catalog of qualifying PDFs found in the Books library.

### Steps

1. Open the EduTek Home page or navigate directly to:

   ```text
   Comic_books.php
   ```

2. Browse the catalog cards.
3. Select **Read** to open a PDF in the reader.
4. Select **Download** to download the original PDF when local browser settings permit it.

### Current discovery rules

The Comic Books page recursively scans the Books content root for PDF files. A PDF is included when its filename contains one of these recognized terms:

```text
comic
manga
superhero
superheroes
supervillain
supervillains
marvel
```

The matching behavior is case-insensitive.

Comic Books do **not** need to be stored in one dedicated folder named `Comic Books`. The current discovery behavior is filename-based and searches beneath the Books library.

### Cover images and caching

For each qualifying PDF, EduTek looks beside the PDF for a same-name image file using one of these extensions:

```text
.jpg
.jpeg
.png
.webp
```

For example:

```text
/content/Books/Graphic Novels/Example Comic.pdf
/content/Books/Graphic Novels/Example Comic.jpg
```

If no matching cover image exists, EduTek displays a local placeholder cover.

The Comic Books catalog may cache scan results for approximately 10 minutes to avoid recursively scanning a large Books library on every page request. The generated cache file is local runtime data and must not be committed to Git:

```text
htdocs/storage/comic-books-cache.json
```

When adding or reorganizing comic PDFs, allow the cache to expire or clear the local cache in the runtime environment before verifying the new catalog results.

---

## Learning Tools

The Learning Tools page displays locally configured educational applications, interactive resources, and reference tools.

Current page:

```text
tools.php
```

### Steps

1. Open the EduTek Home page.
2. Select **Learning Tools**.
3. Select a tool card.
4. Use the selected local application in the new tab or launcher window that opens.

Most configured tools open in a new browser tab. Khan Interactive uses its dedicated local launcher:

```text
launch-khan.php
```

Tool links are generated from the configured service definitions. Do not hard-code a device hostname, host-only IP address, or external internet URL in user instructions unless the local configuration has been verified on the target device.

---

## Indexing overview

Browsing and search show information that has already been discovered by the EduTek content indexer.

Use indexing after adding, replacing, moving, or reorganizing local content files.

Examples include:

- New videos.
- New Books/PDF folders.
- New audiobook folders.
- New thumbnails or updated media files.
- Content moved to a different category or subcategory.
- Files whose metadata must be refreshed.

There are two distinct indexing paths:

| Workflow | Intended user | Access model | Purpose |
|---|---|---|---|
| Teacher-protected reindexing | Authorized teacher/administrator | Teacher authentication and CSRF protection | Existing protected application reindex workflow |
| Local maintenance index | Person physically using the host device | Localhost-only keyboard shortcut | Device-local rescan and verification of new or updated content |

The local maintenance index does not replace the protected teacher reindex workflow.

---

## Teacher-protected reindexing

The application retains a protected reindex endpoint:

```text
api/reindex.php
```

This endpoint is intended for an authorized teacher or administrator workflow and remains protected by teacher authorization and CSRF validation.

### Documentation rules

- Do not expose this endpoint as a public, unauthenticated URL.
- Do not provide instructions that bypass teacher authorization.
- Do not remove CSRF protection to make reindexing easier.
- Do not represent the local maintenance shortcut as a substitute for this protected workflow.
- If a future UI adds a teacher-facing reindex button, document its permission requirement and CSRF behavior.

Before changing the protected workflow, verify the current authorization logic in the code and test it with an authorized account and an unauthorized/guest account.

---

## Local maintenance index

The local maintenance index is intended for the person maintaining the computer that runs the local EduTek application.

It is available only from a local browser request. It is not a remote administration feature.

### What it does

When started, the local maintenance index:

1. Checks Videos, Books, and Audiobooks for new or updated files.
2. Uses the existing shared content-indexing logic.
3. Prevents simultaneous indexing runs with an exclusive lock.
4. Saves index status information to a persistent local directory.
5. Produces a verification CSV when new or updated content is found.
6. Does not include unchanged entries or skipped/error entries in that verification CSV.

### Security and access restrictions

The local maintenance API endpoints are:

```text
api/local-index-start.php
api/local-index-report.php
```

They are restricted to local requests, including normal loopback addresses and the current Docker Desktop development bridge address used by the project.

Do not document these endpoints as remotely accessible. A request from a non-local client should be rejected.

### Start the local maintenance index

Use these steps on the same computer that hosts the local EduTek application.

1. Open EduTek through its local browser address.
2. Click outside any text field, search field, select list, or editable area.
3. Press:

   ```text
   Ctrl + Alt + Shift + I
   ```

4. A confirmation dialog appears with the title:

   ```text
   Start a full content index now?
   ```

5. Confirm that the dialog states it checks Videos, Books, and Audiobooks.
6. Select **Start Index**.
7. Keep the browser page open while the scan runs.
8. Wait for the completion or failure message.

The shortcut is intentionally hidden from normal learner navigation. It is a maintenance action, not a visible Home-page control.

### Completion results

A successful completion dialog reports:

- Number of scanned items.
- Number of new items.
- Number of updated items.
- Number of skipped items.
- Number of thumbnails generated, when applicable.

If no changes are found, the dialog states that the content index is already up to date.

If new or updated content is found, the dialog can provide:

```text
Download verification CSV
```

The verification CSV contains only content records whose indexing action was:

```text
new
updated
```

It excludes unchanged content and skipped/error records.

### Duplicate-run behavior

Only one local maintenance index can run at a time.

If another index is already running, a second start attempt is rejected and the application reports that an index is already running.

Wait for the active job to finish before trying again. Do not work around the lock by deleting status files while an index is running.

---

## Local index status and reports

The Docker development configuration persists index status and verification-report files outside the repository.

### Windows host directory

The current Docker Compose configuration uses:

```text
D:\edutek-system\index-status
```

### Container directory

Docker maps that host directory into the application and indexer containers as:

```text
/system/index-status
```

The application uses this environment variable:

```text
INDEX_STATUS_DIR=/system/index-status
```

### Create the host directory

Before using the local maintenance index in the Windows Docker environment, create the directory:

```powershell
New-Item -ItemType Directory -Force D:\edutek-system\index-status
```

Verify that it exists:

```powershell
Test-Path D:\edutek-system\index-status
```

Expected result:

```text
True
```

### Generated local files

The directory may contain files such as:

```text
local-hotkey-index.lock
local-hotkey-index.json
local-index-verification-YYYYMMDD_HHMMSS_<identifier>.csv
```

These are local runtime records. They may be useful for troubleshooting and verification, but they must not be committed to Git.

The application’s ignored local runtime paths include:

```text
htdocs/storage/index-status/
htdocs/storage/comic-books-cache.json
```

---

## Verify newly added content

Use this checklist after adding content.

### Videos

1. Place the video in the expected content-library location.
2. Run the appropriate indexing workflow.
3. Open the video topic directory.
4. Search for the category, subcategory, or title.
5. Open the video.
6. Confirm playback begins and any thumbnail behavior is correct.

### Books and PDFs

1. Place PDFs beneath the Books content root.
2. Put user-visible PDFs in category folders that do not begin with `_`.
3. Run indexing when the relevant workflow requires it.
4. Open **Books & PDFs**.
5. Search for the category folder name if needed.
6. Open the category and verify the expected PDFs are visible.

### Audiobooks

1. Place audio content in the expected audiobook folder structure.
2. Run indexing when appropriate.
3. Open **Audiobooks**.
4. Search using part of a title, author, or series folder name.
5. Open the matching audiobook collection.
6. Confirm playback works.

### Comic books

1. Place a qualifying PDF anywhere below the Books content root.
2. Use a filename containing a recognized comic-related term when it should appear in the Comic Books catalog.
3. Optionally place a matching image beside the PDF for a cover.
4. Clear or wait for the Comic Books cache if necessary.
5. Open `Comic_books.php`.
6. Confirm the item appears.
7. Confirm **Read** opens the expected PDF.
8. Confirm **Download** targets the expected file.

### Verification CSV

When the local maintenance index reports new or updated content:

1. Select **Download verification CSV**.
2. Open the CSV in a spreadsheet editor or text editor.
3. Confirm that each expected changed item appears.
4. Review the `content_type`, `category`, `subcategory`, `title`, `file_path`, and `open_url` columns.
5. Use `open_url` as a starting point to verify the item in the local EduTek interface.
6. Investigate missing expected files through the indexer audit/status output and the file/folder structure.

---

## Troubleshooting

| Symptom | Likely cause | What to check |
|---|---|---|
| A known file does not appear in search | Content was not indexed or does not meet discovery rules | Run the appropriate index workflow and confirm folder/file naming |
| Books page shows no category | The folder has no PDF files, is outside the Books root, or begins with `_` | Confirm the physical folder path and PDF extension |
| Audiobook search has no results | Search text does not match a folder name | Check title, author, or series folder spelling |
| Comic book does not appear | Filename does not match a recognized term, cache has not expired, or PDF is outside Books root | Check filename, path, cache, and PDF extension |
| Comic cover is a placeholder | No same-name local image file exists beside the PDF | Add a `.jpg`, `.jpeg`, `.png`, or `.webp` image with the same base filename |
| Local index shortcut does nothing | Browser focus is in an editable field, JavaScript did not load, or host is not local | Click outside editable fields, reload, and confirm you are using the local host device |
| Local index returns access denied | Request is not considered local | Use a browser directly on the host computer; do not use a remote client |
| Index says another job is running | A previous run is active or did not release the lock after an unexpected failure | Wait for completion, inspect the status JSON, and investigate the prior run before removing files |
| Index cannot create or write status files | Windows host directory is missing or Docker cannot access it | Create `D:\edutek-system\index-status` and verify Docker/Desktop file access |
| Verification CSV is unavailable | The index completed but report creation failed or no changed records were available | Review index completion details and the local status/report directory |

---

## Documentation maintenance rules

Update this document whenever any of the following changes:

- Home-page search endpoint, behavior, or visible navigation cards.
- Directory, Books, Audiobooks, Comic Books, Music, or Learning Tools routes.
- Books, audiobook, or comic discovery rules.
- Comic cover image naming rules or cache behavior.
- Teacher authorization or CSRF requirements for protected reindexing.
- Local maintenance shortcut keys, access restrictions, status locations, or verification CSV fields.
- Docker host-path or container-path mapping for index-status files.
- Content types scanned by the indexer.
- User-visible completion, error, or verification behavior.

Do not describe a feature as current until it has been verified in the supported local deployment environment.