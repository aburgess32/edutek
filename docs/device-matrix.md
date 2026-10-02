# EduTek Device and Deployment Matrix

> **Status: Current deployment reference**
>
> This document describes the currently supported and expected EduTek usage environments, with particular attention to offline learning, local media access, Docker development, XAMPP-oriented deployment, and local-only content indexing.
>
> **Last aligned with implementation:** 2026-09-06

---

## Purpose

EduTek is an offline-first learning platform. It is intended to run on a local computer or local-network device that hosts the application, its database, and educational media.

Not every device has the same role.

Some devices are appropriate for:

- Hosting the EduTek application.
- Managing the local content library.
- Running content indexing.
- Using teacher maintenance features.
- Accessing the application as a learner or teacher through a browser.

This matrix distinguishes between those roles so that deployment instructions do not incorrectly promise host-only maintenance features to remote client devices.

---

## Device roles

| Role | Description | Typical examples |
|---|---|---|
| Host device | Runs Apache/PHP, database services, local content storage, and optionally Docker | Windows desktop, mini PC, local server |
| Maintenance device | Has direct local access to the host environment and can manage files, Docker, XAMPP, and indexing | Same computer as the host, or an administrator workstation with direct host access |
| Local browser client | Opens EduTek through a browser on the host device itself | Browser on the Windows host computer |
| Network browser client | Opens EduTek from another device on the same local network | Chromebook, laptop, tablet, classroom desktop |
| Development workstation | Uses a Git clone, editor, tests, Docker tooling, and GitHub workflow | Windows developer computer, macOS developer computer |
| Content-management source | Holds media before it is copied into the EduTek library | External drive, staging computer, media workstation |

A device can fulfill more than one role. For example, a Windows mini PC can be the host, maintenance device, local browser client, and development workstation.

---

## Current deployment models

EduTek currently supports two practical local deployment models.

| Deployment model | Best use | Application runtime | Content library | Database | Git workflow |
|---|---|---|---|---|---|
| Docker development environment | Development, testing, repeatable local setup | Apache/PHP container | Windows folder mounted into `/content` | MySQL container | Separate Git clone used for branches and commits |
| XAMPP-oriented local deployment | Local/production-style device deployment | Apache/PHP through XAMPP or equivalent | Local filesystem available to Apache/PHP | Local MySQL/MariaDB | Separate Git clone recommended; deployment folder may not be a Git repository |

The Docker development and XAMPP-oriented deployments can use the same application source code, but their paths, service management, dependency installation, and operational details are not identical.

Do not assume that a Docker-specific path, port, or command works unchanged in an XAMPP deployment.

---

## Windows Docker development host

The current Docker Compose development configuration is designed around a Windows host environment.

### Required software

| Component | Purpose | Verification command |
|---|---|---|
| Windows 10 or Windows 11 | Host operating system | `winver` |
| Docker Desktop | Runs application, database, and supporting containers | `docker version` |
| Docker Compose v2 | Starts and manages Compose services | `docker compose version` |
| Git for Windows | Clones repositories and manages branches/commits | `git --version` |
| Visual Studio Code or equivalent editor | Edits source and documentation | `code --version` |
| Web browser | Uses and verifies the local application | Open the local application URL |

### Development repository location

Use a normal Git clone outside the XAMPP document root.

Recommended example:

```text
D:\edutek
```

or:

```text
D:\Projects\edutek
```

Do not assume this deployment folder is a Git clone:

```text
D:\xampp\htdocs\Edutek
```

The XAMPP directory may be a deployed copy with no `.git` directory. Git commands such as `git status`, `git switch`, `git commit`, and `git push` must be run from the actual clone.

### Docker service ports

| Service | Host address | Purpose |
|---|---|---|
| EduTek application | `http://localhost:8080` | Main PHP/Apache application |
| Database | `localhost:3307` | MySQL access from the Windows host |
| phpMyAdmin | `http://localhost:8081` | Local database administration interface |

The database container listens on port `3306` inside Docker, but the Windows host uses port `3307` to avoid conflicts with a locally installed MySQL service.

### Current important mounts

| Windows or repository source | Container destination | Purpose |
|---|---|---|
| `.\htdocs` | `/var/www/html` | EduTek application document root |
| `.\vendor` | `/var/www/vendor` | PHP dependencies |
| `.\composer.json` | `/var/www/composer.json` | Composer project definition |
| `.\config\apache` | `/etc/apache2/sites-enabled` | Apache site configuration |
| `.\config\openssl\openssl.cnf` | `/etc/ssl/openssl.cnf` | OpenSSL configuration |
| `D:\xampp\htdocs\Edutek\videos` | `/content` | Local media/content library |
| `D:\edutek-system\index-status` | `/system/index-status` | Persistent index status and reports |
| `.\db\schema.sql` | `/docker-entrypoint-initdb.d/01-schema.sql` | First-run database schema initialization |

The paths above are current development configuration. If a host uses a different media-library location, update the Compose file and documentation together.

---

## Local index maintenance

The local index-maintenance workflow is intentionally restricted to the local host environment.

### Who can run it

| Device type | Can use `Ctrl + Alt + Shift + I` to start local indexing? | Reason |
|---|---|---|
| Browser on the EduTek host computer | Yes | The request is treated as local/loopback |
| Browser accessing `localhost:8080` on the Windows host | Yes | Docker Desktop bridge access is allowed by the current local configuration |
| Remote device on the same LAN | No | The endpoint is local-only and is not a remote administration API |
| Public internet client | No | EduTek local maintenance APIs must not be exposed publicly |
| Teacher account from a remote device | Not through the local shortcut | Use the protected teacher workflow where available and authorized |

The local shortcut is:

```text
Ctrl + Alt + Shift + I
```

It should only be used from a browser running on the host device.

### What the local index does

The local maintenance index:

1. Checks Videos, Books, and Audiobooks for new or updated files.
2. Reuses the shared content indexing logic.
3. Prevents duplicate concurrent runs with a lock file.
4. Writes status information to the persistent local index-status folder.
5. Can provide a verification CSV for new and updated content.
6. Does not expose arbitrary file paths through browser requests.

It is not a replacement for the teacher-protected reindex workflow.

### Persistent status directory

The current Windows host path is:

```text
D:\edutek-system\index-status
```

Docker exposes it in application and indexer containers as:

```text
/system/index-status
```

Verify the Windows directory exists:

```powershell
Test-Path D:\edutek-system\index-status
```

Expected result:

```text
True
```

Create it if it is missing:

```powershell
New-Item -ItemType Directory -Force D:\edutek-system\index-status
```

The directory can contain existing and newer maintenance outputs, including:

```text
README.txt
index-run-YYYY-MM-DD_HH-MM-SS.csv
index-up-to-date.json
scan-YYYY-MM-DD_HH-MM-SS.json
local-hotkey-index.lock
local-hotkey-index.json
local-index-verification-YYYYMMDD_HHMMSS_<identifier>.csv
```

Do not commit this directory or its contents to Git.

---

## XAMPP-oriented host

An XAMPP-style deployment is appropriate when a Windows device runs Apache/PHP and MySQL/MariaDB directly without Docker.

### Expected host capabilities

| Capability | Required for XAMPP host | Notes |
|---|---|---|
| Apache/PHP service | Yes | Serves EduTek pages and APIs |
| MySQL/MariaDB service | Yes | Stores application and content metadata |
| Local content library access | Yes | PHP must be able to read the configured media folders |
| Write access for generated assets | Depends on enabled features | Needed for caches, thumbnails, reports, or other generated local data |
| Browser on the host | Recommended | Required for testing host-only maintenance workflows |
| Git repository | Optional | Prefer using a separate development clone |

### XAMPP deployment caution

A directory such as:

```text
D:\xampp\htdocs\Edutek
```

may be a deployment/runtime copy rather than a Git clone.

Before running Git commands, verify:

```powershell
cd D:\xampp\htdocs\Edutek
git rev-parse --show-toplevel
```

If Git reports:

```text
fatal: not a git repository
```

do not run `git init` or clone into that existing deployment folder without a planned migration.

Use a separate clone for source control work, such as:

```text
D:\edutek
```

Then deploy changes deliberately to the XAMPP runtime directory.

---

## Network browser clients

EduTek can serve learners and teachers through browsers on the same local network, depending on host networking and Apache configuration.

### Appropriate uses

Network browser clients can generally:

- Open the EduTek Home page.
- Search the local library.
- Browse videos, books, audiobooks, music, and configured tools.
- Use standard learner or teacher features for which they are authorized.
- Play local content if the host’s content paths and Apache access rules are configured correctly.

### Restricted uses

Network browser clients must not be told to use the local indexing shortcut:

```text
Ctrl + Alt + Shift + I
```

The shortcut calls a localhost-only maintenance API. A remote LAN client should be denied access.

If remote teachers require indexing capability, provide it through the authorized teacher reindex workflow rather than weakening the local-only endpoint.

---

## Supported browser expectations

EduTek is a browser-based application. The target browser must support modern HTML, CSS, JavaScript, media playback, and PDF behavior appropriate for local content.

| Device category | Recommended browser | Primary verification |
|---|---|---|
| Windows host | Current Microsoft Edge or Google Chrome | Home page, search, media playback, local indexing |
| Windows/LAN laptop | Current Edge, Chrome, or Firefox | Search, browsing, playback |
| macOS client | Current Safari, Chrome, or Firefox | Search, browsing, playback |
| Chromebook | Current Chrome | Search, browsing, playback |
| iPad/tablet | Current Safari or Chrome | Responsive navigation, PDFs, media controls |
| Android tablet/phone | Current Chrome | Responsive navigation, PDFs, media controls |

Before declaring a device supported, test:

1. Open the Home page.
2. Run a library search.
3. Browse at least one video topic.
4. Open a Book/PDF resource.
5. Search and open an audiobook collection.
6. Test a Comic Books item, if available.
7. Confirm that configured Learning Tools open as expected.
8. Confirm responsive layout and keyboard/touch behavior appropriate to the device.

---

## Content-management workstation

The content-management workstation is where an administrator prepares and copies media before it becomes available to EduTek users.

### Recommended responsibilities

- Organize Videos, Books/PDFs, Audiobooks, and Music consistently.
- Use clear, stable, user-facing folder names.
- Avoid placing normal user-visible Book categories in folders beginning with `_`.
- Place comic-related PDFs below the Books content root.
- Add same-name image files beside comic PDFs when custom cover art is desired.
- Copy content into the host’s configured media library.
- Run or request the appropriate indexing workflow.
- Verify newly added content in the EduTek interface.

### Comic cover naming example

```text
D:\xampp\htdocs\Edutek\videos\Books\Graphic Novels\Sample Comic.pdf
D:\xampp\htdocs\Edutek\videos\Books\Graphic Novels\Sample Comic.jpg
```

The Comic Books catalog can use `.jpg`, `.jpeg`, `.png`, or `.webp` images that share the PDF’s base filename.

---

## Verification matrix

Use this table after deployment or a significant application update.

| Capability | Windows Docker host | Windows XAMPP host | LAN browser client | Notes |
|---|---|---|---|---|
| Home page loads | Required | Required | Required | Verify current content-type cards |
| Library search works | Required | Required | Required | Uses `result.php?q=<query>` |
| Video directory works | Required | Required | Required | Verify local media paths |
| Books/PDF browsing works | Required | Required | Required | Verify Books root and PDF folders |
| Audiobooks browsing/search works | Required | Required | Required | Folder-name search only |
| Comic Books catalog works | Required | Required | Required | Verify recursive Books scan and cover behavior |
| Music browsing works | Required | Required | Required | Verify local music paths |
| Learning Tools open | Required | Required | As configured | Some tools may depend on local device services |
| Teacher-protected reindexing | Authorized test required | Authorized test required | Only if the authorized workflow permits it | Do not bypass permissions |
| Local indexing shortcut | Required | Test only if configured locally | Not supported | Host-local browser only |
| Index reports persist | Required | If configured | Not applicable | Verify `D:\edutek-system\index-status` for Docker |
| Verification CSV download | Required when changes exist | If configured | Not supported through local shortcut | New/updated records only |
| Offline page navigation | Required | Required | Required after local network connection | No core CDN dependency |

---

## Deployment checklist

### Docker development host

1. Confirm Docker Desktop is running.
2. Confirm `docker compose version` works.
3. Confirm the content library path exists:

   ```powershell
   Test-Path D:\xampp\htdocs\Edutek\videos
   ```

4. Confirm the index-status folder exists:

   ```powershell
   Test-Path D:\edutek-system\index-status
   ```

5. Validate Compose configuration:

   ```powershell
   cd D:\edutek
   docker compose config
   ```

6. Start the stack:

   ```powershell
   docker compose up --build -d
   ```

7. Confirm services:

   ```powershell
   docker compose ps
   ```

8. Open:

   ```text
   http://localhost:8080
   ```

9. Test the current Home-page cards, search, and one example from each available content type.
10. If testing local indexing, use the browser on the host and press `Ctrl + Alt + Shift + I`.

### XAMPP-oriented host

1. Confirm Apache and MySQL/MariaDB are running.
2. Confirm the EduTek application path is available to Apache.
3. Confirm the content library can be read by the Apache/PHP process.
4. Confirm required writable runtime directories are writable.
5. Open the local EduTek URL.
6. Test Home page, search, videos, Books/PDFs, Audiobooks, Comics, Music, and Learning Tools.
7. Test teacher-protected functions using an authorized account.
8. Do not assume Docker-specific host ports or paths apply.

### Network client

1. Connect to the same local network as the EduTek host.
2. Open the configured EduTek network address.
3. Verify the Home page loads.
4. Verify library search and browsing.
5. Test representative media playback.
6. Do not attempt host-only local indexing through `Ctrl + Alt + Shift + I`.

---

## Security and operational rules

- Keep secrets, local databases, media libraries, cache files, logs, and generated index reports out of Git.
- Do not expose local maintenance endpoints publicly.
- Do not weaken teacher authorization or CSRF protections to allow easier reindexing.
- Use a separate Git clone for code/documentation work when the deployed XAMPP directory is not a repository.
- Verify local content paths after moving the host or changing drive letters.
- Back up the media library and database before major content reorganization.
- Confirm offline operation after changes that affect assets, services, or content paths.
- Treat all status/report files as operational evidence, not as source-controlled application files.

---

## Documentation maintenance rules

Update this matrix whenever any of the following changes:

- Supported host operating systems or browsers.
- Docker services, host ports, container mounts, or environment variables.
- XAMPP deployment layout or requirements.
- Local content-library path conventions.
- Local-only indexing access restrictions or keyboard shortcut.
- Index-status/report host path or report file patterns.
- Content discovery rules for Books, Audiobooks, Comic Books, Music, or Learning Tools.
- Network-client permissions or teacher maintenance behavior.

A device or deployment environment should be marked supported only after its documented verification checklist has been completed successfully.