# EduTek

EduTek is an offline-first educational media platform for local learning environments. It provides locally hosted videos, audiobooks, books and PDFs, comic books, music, library search, and configurable learning tools.

The application is designed for devices and local networks where internet access may be limited, unreliable, or unavailable.

> **Documentation status:** Current implementation overview
> **Last aligned with implementation:** 2026-09-06

---

## What EduTek provides

EduTek helps learners and teachers find and use locally available educational resources.

Current primary content paths:

| Content type | Main route | Purpose |
|---|---|---|
| Videos | `directory.php` | Browse locally available video lessons and topics |
| Library search | `result.php` | Search indexed learning resources |
| Audiobooks | `audiobooks.php` | Browse and search audiobook folders |
| Books & PDFs | `books.php` | Browse and search book/PDF categories |
| Comic Books | `Comic_books.php` | Browse comic, manga, superhero, and Marvel-related PDFs |
| Music | `music.php` | Browse local music collections |
| Learning Tools | `Tools.php` | Open locally configured learning applications and reference tools |

---

## Current Home page

The Home page is implemented by:

```text
htdocs/index.php
```

It is designed as a simple starting point for offline learning.

### Home-page features

- A library-wide search box.
- A **Watch Videos** card linking to the topic directory.
- An **Audiobooks** card.
- A **Books & PDFs** card.
- A **Music** card.
- A **Learning Tools** card.
- A **Browse All Topics** action.
- A **Search the Library** action.

### Current Home-page destinations

| Home-page action | Destination |
|---|---|
| Search form | `result.php?q=<search-term>` |
| Watch Videos | `directory.php` |
| Audiobooks | `audiobooks.php` |
| Books & PDFs | `books.php` |
| Music | `music.php` |
| Learning Tools | `tools.php` |
| Browse All Topics | `directory.php` |
| Search the Library | `result.php` |

### Features not currently on Home

The Home page does not currently display:

- Continue Watching cards.
- Personalized watch-progress cards.
- My Assignments.
- Choose Your Path segment tiles.
- A generic all-content grid.

The historical Continue Watching specification is retained in:

```text
docs/02-continue-watching.md
```

It is not a current Home-page feature.

---

## Requirements

### Required for Docker development

| Requirement | Recommended version or type | Verify |
|---|---|---|
| Windows | Windows 10 or Windows 11 | `winver` |
| Docker Desktop | Current stable version | `docker version` |
| Docker Compose | Compose v2 | `docker compose version` |
| Git for Windows | Current version | `git --version` |
| Visual Studio Code | Current version | `code --version` |
| Web browser | Current Edge, Chrome, Firefox, or equivalent | Open local application URL |

### Required host folders

The Docker development configuration expects these Windows folders:

```text
D:\xampp\htdocs\Edutek\videos
D:\edutek-system\index-status
```

The content library is mounted into containers as:

```text
/content
```

The index-status and report directory is mounted into containers as:

```text
/system/index-status
```

Before first Docker startup, verify that both folders exist:

```powershell
Test-Path D:\xampp\htdocs\Edutek\videos
Test-Path D:\edutek-system\index-status
```

Both commands should return:

```text
True
```

If the index-status directory is missing, create it:

```powershell
New-Item -ItemType Directory -Force D:\edutek-system\index-status
```

---

## Repository layout

```text
edutek/
├── htdocs/                     PHP application document root
│   ├── api/                    HTTP endpoints and maintenance APIs
│   ├── assets/                 Local CSS, JavaScript, fonts, and images
│   ├── css/                    Application and page styles
│   ├── includes/               Shared auth, config, database, and indexer logic
│   ├── js/                     Browser-side application behavior
│   ├── index.php               Home page
│   ├── directory.php           Video/topic browsing
│   ├── result.php              Search results
│   ├── books.php               Books/PDF categories and search
│   ├── audiobooks.php          Audiobook browsing and search
│   ├── Comic_books.php         Comic Books catalog
│   ├── music.php               Music browsing
│   └── Tools.php               Locally configured learning tools
├── db/                         Database schema and migrations
├── docker/                     Docker image configuration
├── config/                     Apache, OpenSSL, and application configuration
├── docs/                       Architecture, feature, deployment, and planning docs
├── scripts/                    Maintenance and setup scripts
├── tests/                      Automated tests
├── docker-compose.yml          Docker development services
├── composer.json               PHP dependencies and scripts
├── package.json                JavaScript dependencies and scripts
├── Makefile                    Development command shortcuts
└── .env.example                Environment-variable template
```

---

## Docker development setup

Use Docker for a repeatable local development environment.

### 1. Clone the repository

Use a normal Git clone outside the XAMPP deployment folder.

Recommended location:

```text
D:\edutek
```

Clone:

```powershell
cd D:\
git clone [https://github.com/aburgess32/edutek.git](https://github.com/aburgess32/edutek.git) edutek
cd D:\edutek
```

Verify:

```powershell
git status
git branch --show-current
```

The working tree should be clean.

Do not assume this directory is a Git repository:

```text
D:\xampp\htdocs\Edutek
```

That location may be an XAMPP runtime/deployment copy without a `.git` directory.

### 2. Confirm Docker is available

From the repository root:

```powershell
docker version
docker compose version
```

Both commands must complete without errors.

If Docker Desktop is not running, start it and wait until its status reports that the Docker engine is running.

### 3. Confirm local host directories

Verify the mounted content and index-status directories:

```powershell
Test-Path D:\xampp\htdocs\Edutek\videos
Test-Path D:\edutek-system\index-status
```

Create the index-status directory if needed:

```powershell
New-Item -ItemType Directory -Force D:\edutek-system\index-status
```

The media-library directory must contain your local EduTek content. Do not delete or replace it when working with the Git clone.

### 4. Configure environment values

Create a local `.env` file from the example:

```powershell
Copy-Item .\.env.example .\.env
```

Open it:

```powershell
code .\.env
```

Review each value before starting the application.

Do not commit `.env`. It can contain device-specific settings and secrets.

### 5. Validate the Compose configuration

From the repository root:

```powershell
docker compose config
```

Expected result:

- Docker prints the resolved Compose configuration.
- No required-variable error appears.
- The application, database, phpMyAdmin, and indexer services resolve successfully.

### 6. Start the development stack

Run:

```powershell
docker compose up --build -d
```

Verify service status:

```powershell
docker compose ps
```

Expected services:

| Service | Expected state |
|---|---|
| `app` | Running |
| `db` | Running or healthy |
| `phpmyadmin` | Running |
| `indexer` | One-shot service; behavior depends on its configured command |

If a service fails, inspect logs:

```powershell
docker compose logs --tail 150
```

To inspect one service:

```powershell
docker compose logs --tail 150 app
docker compose logs --tail 150 db
docker compose logs --tail 150 indexer
```

### 7. Open EduTek

Open the application in your browser:

```text
http://localhost:8080
```

Open phpMyAdmin, if needed:

```text
http://localhost:8081
```

The database is reachable from the Windows host on:

```text
localhost:3307
```

The database remains on port `3306` inside the Docker network.

### 8. Stop the development stack

When you are finished:

```powershell
docker compose down
```

To also remove the database volume, use this only when you intentionally want to erase Docker database data:

```powershell
docker compose down -v
```

Warning: `docker compose down -v` removes named volumes, including the Docker database volume. Do not use it casually.

---

## XAMPP-oriented deployment

EduTek can also run through an Apache/PHP and MySQL/MariaDB stack outside Docker, including XAMPP-style local deployments.

Typical runtime location:

```text
D:\xampp\htdocs\Edutek
```

This directory can be used by Apache as the web application root. It may not be a Git repository.

### Deployment rules

- Use a separate Git clone for development, branches, commits, and pull requests.
- Deploy tested application changes from the Git clone to the XAMPP runtime directory using a deliberate deployment process.
- Keep local media libraries, `.env` files, caches, logs, generated reports, and database data outside Git.
- Confirm that Apache/PHP has read access to local content folders.
- Confirm writable runtime locations for features that generate files.
- Do not copy or overwrite the media library while deploying ordinary code/documentation changes.

For device-specific deployment information, see:

```text
docs/device-matrix.md
```

---

## Content library

Docker development mounts this Windows library into containers:

```text
D:\xampp\htdocs\Edutek\videos
```

Container path:

```text
/content
```

The content library may contain:

```text
Videos
Books
Audiobooks
Music
```

The exact internal organization must remain consistent with the current content indexer and browsing pages.

### Videos

Use the topic directory and library search to find indexed video learning resources:

```text
directory.php
result.php
```

After adding, moving, or replacing video files, run the appropriate indexing workflow before expecting the files to appear in search and browsing results.

### Books and PDFs

The Books page is:

```text
books.php
```

Current behavior:

- Displays book-category folders containing PDF files.
- Uses the Docker Books root:

  ```text
  /content/Books
  ```

- May fall back to `htdocs/videos/Books` for legacy/local layouts.
- Hides folders beginning with `_`.
- Filters category names through the `q` query parameter.
- Does not search inside PDF text.

For user-visible content, avoid using category folder names that begin with `_`.

### Audiobooks

The Audiobooks page is:

```text
audiobooks.php
```

Current behavior:

- Browses local audiobook folders.
- Filters folder names using the `q` query parameter.
- Supports title, author, and series discovery when those details are represented in folder names.
- Requires at least two entered characters before automatic filtering runs.
- Applies filtering after a short typing delay.
- Provides a **Clear search** link when a query is active.
- Does not search audio transcripts or embedded metadata unless separately implemented.

### Comic Books

The Comic Books page is:

```text
Comic_books.php
```

Current behavior:

- Recursively scans the Books library for PDF files.
- Includes PDFs whose filenames contain recognized comic-related terms.
- Recognized case-insensitive terms include:

  ```text
  comic
  manga
  superhero
  superheroes
  supervillain
  supervillains
  marvel
  ```

- Displays catalog cards with **Read** and **Download** actions.
- Uses a same-name image beside the PDF as a cover when available.
- Uses a local placeholder cover if no image exists.
- Caches discovered entries for approximately 10 minutes.

Comic PDFs do not need to be stored in one dedicated `Comic Books` folder.

Example:

```text
/content/Books/Graphic Novels/Example Comic.pdf
/content/Books/Graphic Novels/Example Comic.jpg
```

Supported cover-image extensions:

```text
.jpg
.jpeg
.png
.webp
```

The generated Comic Books cache is local runtime data:

```text
htdocs/storage/comic-books-cache.json
```

It is ignored by Git and must not be committed.

### Music

The Music page is:

```text
music.php
```

Use the Home-page Music card to browse available local music collections.

### Learning Tools

The Learning Tools page is:

```text
Tools.php
```

It renders locally configured learning applications and reference tools.

Current behavior:

- Most configured tools open in a new browser tab.
- Khan Interactive uses the local launcher:

  ```text
  launch-khan.php
  ```

- Tool destinations can use local ports, relative paths, or configured service URLs.
- Local service URLs use the current host name when another port is required.

Before adding a tool, verify that it works without internet access when offline operation is required.

---

## Content indexing

EduTek browsing and search depend on indexed content metadata.

Run indexing after:

- Adding videos, books/PDFs, audiobooks, or music.
- Moving or reorganizing content.
- Replacing a file.
- Updating file names, categories, subcategories, covers, or thumbnails.
- Investigating content missing from search or browsing.

### Teacher-protected reindexing

EduTek retains a teacher-protected reindex endpoint:

```text
htdocs/api/reindex.php
```

This workflow is intended for authorized teacher or administrator use and remains protected by authorization and CSRF validation.

Do not expose it as an unauthenticated public action or weaken its security checks.

### Local maintenance index

EduTek also includes a local-host-only maintenance workflow for the person using the host computer.

It uses these components:

```text
htdocs/api/local-index-start.php
htdocs/api/local-index-report.php
htdocs/js/local-index-hotkey.js
```

The local maintenance action is started with this keyboard shortcut:

```text
Ctrl + Alt + Shift + I
```

Use it only from a browser on the computer hosting the local EduTek application.

### Run the local maintenance index

1. Open EduTek locally in a browser.
2. Click outside all search inputs, form fields, and editable text areas.
3. Press:

   ```text
   Ctrl + Alt + Shift + I
   ```

4. Review the confirmation dialog.
5. Confirm that it states it checks Videos, Books, and Audiobooks.
6. Select **Start Index**.
7. Keep the browser page open while the scan runs.
8. Review the completion message.

The local maintenance index:

- Uses the shared application indexer.
- Checks Videos, Books, and Audiobooks for new or updated content.
- Prevents simultaneous runs through an exclusive lock.
- Writes status and report files into the local index-status directory.
- Can provide a downloadable verification CSV when new or updated content is found.
- Is restricted to local requests and is not a remote administration feature.

### Index status and reports

Windows host directory:

```text
D:\edutek-system\index-status
```

Container directory:

```text
/system/index-status
```

The directory can contain existing indexer output and local maintenance output, including:

```text
README.txt
index-run-YYYY-MM-DD_HH-MM-SS.csv
index-up-to-date.json
scan-YYYY-MM-DD_HH-MM-SS.json
local-hotkey-index.lock
local-hotkey-index.json
local-index-verification-YYYYMMDD_HHMMSS_<identifier>.csv
```

The newer verification CSV contains only `new` and `updated` records. It excludes unchanged content and skipped/error entries.

Do not commit index reports, lock files, status files, or generated caches.

---

## Verification checklist

Run this checklist after deployment or a significant application/content update.

### Application checks

1. Open:

   ```text
   http://localhost:8080
   ```

2. Confirm the Home page shows:
   - Search.
   - Watch Videos.
   - Audiobooks.
   - Books & PDFs.
   - Music.
   - Learning Tools.

3. Submit a known library search term.
4. Open the video/topic directory.
5. Open a known Book/PDF category.
6. Search for a known audiobook title, author, or series folder.
7. Open a Comic Books item, if available.
8. Open the Music page.
9. Open configured Learning Tools.

### Indexing checks

1. Confirm the status folder exists:

   ```powershell
   Test-Path D:\edutek-system\index-status
   ```

2. On the host browser, press:

   ```text
   Ctrl + Alt + Shift + I
   ```

3. Start an index run.
4. Wait for completion.
5. If content changed, download the verification CSV.
6. Confirm expected new or updated content appears in the report.
7. Use the report’s `open_url` column as a starting point for UI verification.

### Docker checks

```powershell
docker compose ps
docker compose logs --tail 150
docker compose config
```

Do not mark a deployment as verified until the relevant user paths and local content behavior have been tested.

---

## Development workflow

### Create a feature or documentation branch

From the Git clone:

```powershell
cd D:\edutek
git status
git switch master
git pull --ff-only origin master
git switch -c <type>/<short-description>
```

Examples:

```text
docs/update-content-indexing-guide
fix/audiobook-search-layout
feat/add-learning-tool-card
```

### Review changes before committing

```powershell
git status
git diff --check
git diff --stat
git diff
```

Stage only intended files:

```powershell
git add path/to/file1 path/to/file2
```

Review staged changes:

```powershell
git diff --cached --check
git diff --cached --stat
git diff --cached
```

Commit:

```powershell
git commit -m "docs: describe the change clearly"
```

Push:

```powershell
git push -u origin <branch-name>
```

Create a pull request against:

```text
master
```

### Line endings on Windows

This repository uses `.gitattributes` to normalize source and Markdown files to LF line endings.

For a Windows clone, use:

```powershell
git config core.autocrlf false
```

Verify:

```powershell
git config --get core.autocrlf
```

Expected result:

```text
false
```

If Git reports only CRLF/LF differences in a fresh clone, confirm there are no meaningful differences:

```powershell
git diff --ignore-space-at-eol --stat
git diff --ignore-all-space --stat
```

Do not commit line-ending-only changes.

---

## Testing and quality checks

The repository includes test and quality configuration in:

```text
phpunit.xml
phpcs.xml
playwright.config.js
lighthouserc.js
package.json
composer.json
Makefile
.github/workflows/ci.yml
```

These files are the authoritative source for commands and CI requirements.

List available JavaScript scripts:

```powershell
npm run
```

List available Composer scripts:

```powershell
composer run-script --list
```

List Make targets, if GNU Make is installed:

```powershell
make help
```

On standard Windows installations, GNU Make may not be installed. Use the underlying `npm`, Composer, and Docker commands when Make is unavailable.

Before opening a pull request:

1. Run the relevant local tests and quality checks defined by the repository scripts.
2. Run `git diff --check`.
3. Confirm no secrets, local reports, caches, media files, or `.env` files are staged.
4. Verify user-facing changes in the local application.
5. Confirm that documentation matches the current implementation.

---

## Documentation

| Document | Purpose |
|---|---|
| `docs/01-visual-home-tiles.md` | Current Home-page structure and navigation |
| `docs/02-continue-watching.md` | Historical Continue Watching feature specification |
| `docs/05-teacher-content-finder.md` | Teacher content finding and indexing workflows |
| `docs/architecture.md` | Current technical architecture |
| `docs/device-matrix.md` | Device roles, deployment models, and validation expectations |
| `CONTRIBUTING.md` | Contribution workflow and repository standards |
| `CLAUDE.md` | Repository-specific development guidance |

Planning documents under `docs/` may describe historical or proposed work. Verify their status before treating them as current implementation instructions.

---

## Security and offline-first rules

- Do not commit `.env` files, passwords, API keys, or other secrets.
- Do not commit local media libraries, generated caches, logs, database data, index reports, status JSON, verification CSVs, or lock files.
- Do not expose local maintenance APIs publicly.
- Do not weaken teacher authorization or CSRF protection for reindexing.
- Do not add mandatory CDN dependencies to core application behavior.
- Keep CSS, JavaScript, fonts, icons, and essential learning resources available locally.
- Test the application with no internet connection before claiming offline support.
- Use a separate Git clone for development if the XAMPP runtime directory is not a Git repository.

---

## Troubleshooting

| Problem | Check | Typical next step |
|---|---|---|
| `fatal: not a git repository` | Current folder has no `.git` directory | Move to the actual Git clone, such as `D:\edutek` |
| Docker command fails | Docker Desktop may not be running | Start Docker Desktop and rerun `docker version` |
| Port `3307` is unavailable | Another local service uses the port | Identify the conflicting service or update Compose configuration deliberately |
| App does not load at `localhost:8080` | Container may not be running | Run `docker compose ps` and inspect `docker compose logs app` |
| Database is unhealthy | Database startup or schema issue | Run `docker compose logs db` |
| New content is missing | Content has not been indexed or is in the wrong folder | Verify path, naming, then run the appropriate index workflow |
| Books category is missing | Folder has no PDFs or begins with `_` | Add PDFs or rename the user-visible category |
| Audiobook search finds nothing | Query does not match a folder name | Check title, author, or series folder naming |
| Comic PDF is missing | Filename does not contain a recognized comic-related term | Rename appropriately, confirm it is below the Books root, and allow/clear cache |
| Comic cover is a placeholder | No same-name image is available | Add `.jpg`, `.jpeg`, `.png`, or `.webp` beside the PDF |
| Local index shortcut does nothing | Focus is inside a form field or JavaScript did not load | Click outside editable fields, reload, then retry |
| Local index reports access denied | Request did not originate locally | Run it from a browser on the EduTek host device |
| Local index cannot write reports | Host folder or Docker access issue | Confirm `D:\edutek-system\index-status` exists and Docker can mount it |
| Git reports CRLF/LF-only changes | Windows line ending conversion | Verify with whitespace-ignore diff commands; do not commit formatting-only changes |

---

## License and project ownership

Add the applicable project license and ownership terms here if they are not already defined elsewhere in the repository.