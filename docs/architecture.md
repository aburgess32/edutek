# EduTek Architecture

> **Status: Current architecture reference**
>
> This document describes the current high-level architecture of the EduTek offline learning platform.
>
> It is aligned with the current Home-page navigation, local media-library browsing, service tools, content indexing, Docker development setup, and XAMPP-oriented deployment model.
>
> **Last aligned with implementation:** 2026-09-06

---

## Purpose

EduTek is an offline-first educational media platform. It is designed to provide locally hosted learning materials and learning applications on a device or local network where dependable internet access may not be available.

The platform provides:

- Video learning resources organized by topic.
- Audiobook collections.
- Books and PDF resources.
- Comic book and manga discovery from the Books library.
- Music collections.
- Locally configured learning and reference tools.
- Library-wide search.
- Teacher and maintenance workflows for content indexing.
- Local persistence for media metadata, user activity, and index-maintenance reports.

EduTek is primarily a PHP application served by Apache, with JavaScript and CSS used to provide browser interactions and responsive interfaces.

---

## Architecture overview

```text
┌─────────────────────────────────────────────────────────────────────┐
│                         Learner / Teacher Browser                   │
│                                                                     │
│  Home -  Search -  Directory -  Videos -  Books -  Audiobooks -  Tools   │
└──────────────────────────────────┬──────────────────────────────────┘
                                   │ HTTP
                                   ▼
┌─────────────────────────────────────────────────────────────────────┐
│                         Apache + PHP Application                    │
│                                                                     │
│  htdocs/index.php                 Home page and content-type links  │
│  htdocs/result.php                Search results                    │
│  htdocs/directory.php             Video/topic browsing              │
│  htdocs/books.php                 Books/PDF category browsing       │
│  htdocs/audiobooks.php            Audiobook browsing and filtering  │
│  htdocs/Comic_books.php           Comic PDF catalog                 │
│  htdocs/music.php                 Music browsing                    │
│  htdocs/Tools.php                 Local learning tools directory    │
│                                                                     │
│  htdocs/api/                      Application and maintenance APIs  │
│  htdocs/includes/                 Shared auth, config, tiles, DB    │
│  htdocs/js/                       Browser-side behavior             │
│  htdocs/css/                      Page and shared styles            │
└──────────────┬───────────────────────────┬──────────────────────────┘
               │                           │
               │ SQL                       │ Filesystem access
               ▼                           ▼
┌──────────────────────────┐   ┌──────────────────────────────────────┐
│      MySQL / MariaDB     │   │           Local Content Library       │
│                          │   │                                      │
│  Content metadata        │   │  Videos                              │
│  Search activity         │   │  Books and PDFs                     │
│  User and role data      │   │  Audiobooks                         │
│  Watch history           │   │  Music                              │
│  Downloads/activity      │   │  Covers, thumbnails, local assets   │
└──────────────────────────┘   └──────────────────────────────────────┘
               │
               │ index results/status
               ▼
┌─────────────────────────────────────────────────────────────────────┐
│                Persistent Local Index Status and Reports             │
│                                                                     │
│  Windows host: D:\edutek-system\index-status                        │
│  Container path: /system/index-status                               │
│                                                                     │
│  Status JSON -  lock file -  index audit data -  verification CSV      │
└─────────────────────────────────────────────────────────────────────┘
```

---

## Application layers

| Layer | Primary responsibility | Main locations |
|---|---|---|
| Presentation | Page markup, navigation, accessible text, user-facing structure | `htdocs/*.php` |
| Styling | Responsive layout, colors, typography, interaction states | `htdocs/css/`, `htdocs/assets/css/` |
| Browser behavior | Search interactions, media controls, maintenance shortcut behavior | `htdocs/js/`, `htdocs/assets/js/` |
| Shared application logic | Authentication, configuration, database access, tile/content helpers, indexing logic | `htdocs/includes/` |
| HTTP APIs | Search, protected actions, local indexing, downloads, content maintenance | `htdocs/api/` |
| Database | User, content, search, watch-history, activity, and related application data | `db/`, MySQL/MariaDB |
| Content library | Locally mounted media, PDFs, covers, thumbnails, and other learning files | `/content` in Docker; local deployment paths in XAMPP |
| Deployment | Docker development services, Apache/PHP image, database setup, local mounts | `docker-compose.yml`, `docker/`, `config/` |
| Automation | Tests, linting, validation, and CI workflows | `tests/`, `package.json`, `composer.json`, `Makefile`, `.github/workflows/` |

---

## Primary user experience

### Home page

The Home page is implemented by:

```text
htdocs/index.php
```

It is styled primarily by:

```text
htdocs/css/index.css
```

The current Home page is a simple library entry point. It includes:

- EduTek Global identity and offline-learning message.
- Library-wide search form.
- Five primary content-type cards:
  - Watch Videos.
  - Audiobooks.
  - Books & PDFs.
  - Music.
  - Learning Tools.
- A final section linking to Browse All Topics and Search the Library.

The Home page sends library searches to:

```text
result.php?q=<search-term>
```

The Home page does not currently render:

- Continue Watching.
- Personalized watch-progress cards.
- Student assignment cards.
- Choose Your Path segment tiles.
- Generic all-content grids.

Historical Continue Watching design information is retained in:

```text
docs/02-continue-watching.md
```

but that feature is not currently displayed on Home.

---

## Content browsing

### Video and topic browsing

The primary video-browsing route is:

```text
htdocs/directory.php
```

Users reach it from the Home-page **Watch Videos** card or **Browse All Topics** action.

The directory provides browsing of local video content by the content organization represented in the application and media library.

The older directory anchor-navigation bar is not part of the current directory page.

### Search results

Library search results are rendered by:

```text
htdocs/result.php
```

Search is entered from the Home page or accessed directly through the search-results route.

The search system depends on content that has been indexed into the application’s content metadata.

### Books and PDFs

Books are browsed through:

```text
htdocs/books.php
```

The current Books page:

- Reads category folders from the Books library.
- Uses `/content/Books` as the Docker filesystem location.
- Can fall back to `htdocs/videos/Books` for legacy/local layouts.
- Shows only folders containing PDF files.
- Excludes folders whose names begin with `_`.
- Supports case-insensitive filtering by category folder name through `q`.
- Uses local thumbnail handling for category cards.

The Books search is folder/category-name filtering. It does not claim to search inside PDF text.

### Audiobooks

Audiobooks are browsed through:

```text
htdocs/audiobooks.php
```

The current Audiobooks page:

- Reads audiobook folders from the configured local content library.
- Supports case-insensitive filtering using the `q` query parameter.
- Is intended to help users find audiobook titles, authors, and series based on folder names.
- Applies search after a short delay when users enter two or more characters.
- Resets pagination when a search is applied.
- Provides a clear-search path back to the full listing.

The Audiobooks search is folder-name filtering. It does not claim to search audio transcripts or embedded media metadata.

### Comic Books

Comic Books are cataloged through:

```text
htdocs/Comic_books.php
```

The Comic Books page:

- Recursively scans the Books filesystem location.
- Includes qualifying PDF filenames that contain comic-related terms.
- Recognizes case-insensitive terms such as:
  - `comic`
  - `manga`
  - `superhero`
  - `superheroes`
  - `supervillain`
  - `supervillains`
  - `marvel`
- Shows a catalog card with Read and Download actions.
- Uses a same-name local image as a cover when available.
- Uses a local placeholder cover when no matching image exists.
- Caches discovered comic entries locally for approximately 10 minutes.

The local Comic Books cache is runtime data:

```text
htdocs/storage/comic-books-cache.json
```

It is ignored by Git and must not be committed.

### Music

Music is available through:

```text
htdocs/music.php
```

The Home page links directly to this route through the Music card.

### Learning Tools

Learning Tools are available through:

```text
htdocs/Tools.php
```

This page renders locally configured services as cards. Its service definitions are obtained from the shared tile/service configuration.

Tool behavior:

- Configured non-Khan services generally open in a new browser tab.
- Khan Interactive uses:

  ```text
  htdocs/launch-khan.php
  ```

- Tool URLs may use local ports, relative application paths, or configured URLs.
- The current hostname is used when a configured local service must open on a different port.

Learning Tools must remain compatible with offline deployment. Avoid adding internet-only services as required platform functionality.

---

## Shared application components

### Authentication and authorization

Shared authentication code is loaded from:

```text
htdocs/includes/auth.php
```

Authorization behavior is used to distinguish guests, signed-in users, teachers, and other permitted roles.

Sensitive maintenance operations must retain their authorization and request-validation requirements.

### Configuration

Application configuration and content-security constants are maintained through shared configuration files under:

```text
htdocs/includes/
config/
```

Secret values and deployment-specific settings must be supplied through the intended configuration or environment mechanisms rather than added as hard-coded values in application pages.

### Content and tile helpers

Shared UI/content helpers include:

```text
htdocs/includes/tiles.php
```

These helpers support content organization, service configuration, paths, and navigation behavior used by multiple pages.

---

## Content indexing architecture

EduTek uses indexing to discover local content files and record their metadata for search, browsing, thumbnails, and related features.

Indexing should be run after adding, moving, replacing, or reorganizing local content.

### Shared indexer

The shared content-indexing logic is loaded from:

```text
htdocs/includes/content-indexer.php
```

The local maintenance endpoint reuses this shared logic. It must not duplicate scanning rules in a separate, inconsistent implementation.

### Teacher-protected reindexing

The existing protected reindex path is:

```text
htdocs/api/reindex.php
```

This path is intended for authorized teacher/administrator activity and remains protected by teacher authorization and CSRF validation.

Do not weaken this protection or document it as a public endpoint.

### Local maintenance index

The local maintenance-index path consists of:

```text
htdocs/api/local-index-start.php
htdocs/api/local-index-report.php
htdocs/js/local-index-hotkey.js
```

The browser-side shortcut is loaded through:

```text
htdocs/navhome.php
```

The local maintenance workflow is intentionally separate from teacher-protected reindexing.

Its current behavior:

1. It is triggered only from a local browser session using:

   ```text
   Ctrl + Alt + Shift + I
   ```

2. It opens a confirmation dialog.
3. It indexes Videos, Books, and Audiobooks.
4. It reuses the shared content indexer.
5. It uses an exclusive lock to prevent duplicate simultaneous runs.
6. It saves run status as local JSON.
7. It creates a derived verification CSV for newly indexed and updated records.
8. It exposes that report only through a local-only report endpoint.
9. It does not expose arbitrary filesystem paths through the browser request.

The local maintenance endpoints accept only local/loopback requests, including the configured Docker Desktop development bridge address used by this project. They are not a remote management API.

### Index status and verification reports

The Docker development setup persists content-indexing status and report files outside the repository so they survive container recreation and remain available on the Windows host.

Windows host directory:

```text
D:\edutek-system\index-status
```

Container directory:

```text
/system/index-status
```

Application environment value:

```text
INDEX_STATUS_DIR=/system/index-status
```

The directory is already used by local indexing and maintenance workflows. It can contain both established indexer outputs and newer local-maintenance files.

Examples of established local indexer files include:

```text
README.txt
index-run-YYYY-MM-DD_HH-MM-SS.csv
index-up-to-date.json
scan-YYYY-MM-DD_HH-MM-SS.json
```

The newer localhost-only maintenance workflow can also create files such as:

```text
local-hotkey-index.lock
local-hotkey-index.json
local-index-verification-YYYYMMDD_HHMMSS_<identifier>.csv
```

### Report purpose

| File type | Purpose |
|---|---|
| `index-run-*.csv` | Records an existing content-index run and its results |
| `scan-*.json` | Stores scan status or summary data for a completed scan |
| `index-up-to-date.json` | Records a state where no new or updated content required indexing |
| `local-hotkey-index.lock` | Prevents more than one local maintenance index from running simultaneously |
| `local-hotkey-index.json` | Stores the most recent local maintenance-index run status |
| `local-index-verification-*.csv` | Lists only newly indexed and updated records from a local maintenance-index run |

The newer verification CSV is intentionally narrower than a complete scan report. It includes only records whose indexing action is `new` or `updated`; unchanged content and skipped/error records are excluded.

All files in this directory are device-local runtime and audit artifacts. They are useful for validation and troubleshooting, but they must not be committed to Git.

---

## Data storage

### Database

The Docker development stack includes a database service initialized from:

```text
db/schema.sql
```

Database data persists through the Docker Compose named volume:

```text
mysql-data
```

The application database stores metadata and application state, including data related to:

- Content metadata.
- Users and user roles.
- Search activity.
- Download activity.
- Watch history.
- Assignment and teacher-related features where implemented.

The database schema is the authoritative source for table and column availability. Documentation must not assume a migration has executed unless the deployment workflow explicitly runs it.

### Local media library

Docker uses a mounted local content library at:

```text
/content
```

The current development mount uses the Windows host path:

```text
D:\xampp\htdocs\Edutek\videos
```

The content library provides files such as:

- Videos.
- Books and PDF documents.
- Audiobooks.
- Music.
- Covers and thumbnails.
- Related local learning media.

The indexer and browsing pages must normalize content paths consistently so the same content works in Docker and XAMPP-oriented local deployments.

---

## Docker development architecture

Docker Compose configuration is defined in:

```text
docker-compose.yml
```

The major services are:

| Service | Role | Primary local port |
|---|---|---|
| `app` | Apache/PHP EduTek application | `8080` mapped to container port `80` |
| `indexer` | One-shot CLI content-indexing service | No public HTTP port |
| `db` | MySQL database | `3307` mapped to container port `3306` |
| `phpmyadmin` | Local database administration UI | `8081` mapped to container port `80` |

Important current development mounts include:

| Host path or repository path | Container path | Purpose |
|---|---|---|
| `./htdocs` | `/var/www/html` | Application document root |
| `./vendor` | `/var/www/vendor` | PHP dependencies |
| `./composer.json` | `/var/www/composer.json` | Composer project definition |
| `./config/apache` | `/etc/apache2/sites-enabled` | Apache configuration |
| `./config/openssl/openssl.cnf` | `/etc/ssl/openssl.cnf` | OpenSSL configuration |
| `D:\xampp\htdocs\Edutek\videos` | `/content` | Local content library |
| `D:\edutek-system\index-status` | `/system/index-status` | Persistent local index status and reports |
| `./db/schema.sql` | `/docker-entrypoint-initdb.d/01-schema.sql` | First-run database schema initialization |

Before using Docker indexing features on Windows, ensure this directory exists:

```powershell
New-Item -ItemType Directory -Force D:\edutek-system\index-status
```

The Docker development configuration is not identical to every XAMPP production deployment. Treat Docker paths, host ports, and bind mounts as development configuration unless the deployment documentation explicitly states otherwise.

---

## XAMPP-oriented deployment model

EduTek can also be deployed through an Apache/PHP stack outside Docker, including XAMPP-style local deployments.

In this mode:

- Apache serves the application from the configured document root.
- PHP executes application pages and APIs.
- A local MySQL/MariaDB instance provides database storage.
- The local content library must be available to Apache/PHP under the paths expected by the configuration.
- Application folders, local media, logs, cache files, secrets, and database data may be managed outside Git.

The XAMPP deployment directory is not automatically a Git repository. Use a separate Git clone for development, branches, commits, and pull requests. Deploy code deliberately to the XAMPP directory using the documented deployment procedure.

---

## Offline-first design rules

EduTek must preserve core functionality without internet access.

The architecture therefore requires:

- Local copies of required CSS, JavaScript, fonts, icons, and application assets.
- No mandatory CDN dependency for page rendering or core controls.
- Local content paths and local service URLs.
- Graceful failure when an optional external service is unavailable.
- No reliance on cloud-only APIs for essential browsing, search, or playback behavior.
- Local persistence of metadata and maintenance results.
- Documentation that distinguishes locally available features from optional external integrations.

---

## Testing and validation

Repository testing and quality configuration includes:

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

These files are the operational source of truth for automated checks.

Before documenting a feature as current:

1. Test it in the supported local deployment environment.
2. Confirm its route and visible UI match this document.
3. Confirm content discovery works with the expected local file structure.
4. Confirm authorization boundaries for protected actions.
5. Confirm local-only maintenance functions reject non-local access.
6. Run relevant automated checks defined by project scripts and CI.
7. Update related user, deployment, and contributor documentation in the same change.

---

## Documentation ownership

Keep architecture documentation synchronized when changes affect:

- Application routes or navigation.
- Home-page layout or primary content types.
- Content-library folder rules.
- Search behavior.
- Authentication and authorization boundaries.
- Indexing mechanisms or maintenance shortcuts.
- Local-only versus remote access assumptions.
- Docker services, ports, mounts, or environment variables.
- Database initialization behavior.
- Offline dependencies.
- Generated files, caches, and Git ignore rules.

A code change that adds or removes a user-facing page, content type, maintenance workflow, or deployment dependency should include a corresponding documentation review.