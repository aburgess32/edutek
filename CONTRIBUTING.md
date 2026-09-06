# Contributing to EduTek

EduTek is an offline-first educational media platform. It provides locally hosted learning resources, including videos, audiobooks, books and PDFs, comic books, music, library search, and locally configured learning tools.

This guide explains how to make safe, reviewable contributions without accidentally changing local media libraries, deployment configuration, secrets, generated reports, or production data.

> **Status:** Current contributor workflow
> **Last aligned with repository configuration:** 2026-09-06

---

## Core rules

1. Work from a separate Git clone, not from an XAMPP runtime/deployment folder unless that folder has been intentionally configured as a Git repository.
2. Start every change from the current `master` branch.
3. Create one focused branch for each feature, fix, documentation update, or maintenance task.
4. Do not commit `.env` files, passwords, API keys, local media, database files, caches, logs, generated reports, or Docker volumes.
5. Run the relevant checks before opening a pull request.
6. Review the exact staged diff before committing.
7. Keep documentation synchronized with user-facing, deployment, indexing, and navigation changes.
8. Do not weaken authorization, CSRF protection, localhost-only restrictions, or offline-first behavior without explicit security review.

---

## Repository and runtime locations

Use a real Git clone for source-control work.

Recommended Git clone location:

```text
D:\edutek
```

or:

```text
D:\Projects\edutek
```

Typical XAMPP runtime/deployment location:

```text
D:\xampp\htdocs\Edutek
```

Typical Windows content library location used by Docker development:

```text
D:\xampp\htdocs\Edutek\videos
```

Persistent Windows index-status/report location:

```text
D:\edutek-system\index-status
```

The XAMPP deployment directory may not contain a `.git` folder. Before using Git commands, confirm your current directory is a real clone:

```powershell
git rev-parse --show-toplevel
git status
```

If Git reports:

```text
fatal: not a git repository
```

move to your Git clone. Do not run `git init` inside the XAMPP deployment folder without a planned migration.

---

## First-time setup

### 1. Clone the repository

Open PowerShell and run:

```powershell
cd D:\
git clone [https://github.com/aburgess32/edutek.git](https://github.com/aburgess32/edutek.git) edutek
cd D:\edutek
```

Verify the clone:

```powershell
git status
git remote -v
git branch --show-current
```

Expected remote:

```text
origin  [https://github.com/aburgess32/edutek.git](https://github.com/aburgess32/edutek.git)
```

The default branch is currently:

```text
master
```

### 2. Configure line endings on Windows

The repository uses `.gitattributes` to keep source and Markdown files in LF format.

In the Git clone, run:

```powershell
git config core.autocrlf false
```

Verify:

```powershell
git config --get core.autocrlf
```

Expected output:

```text
false
```

If a fresh Windows clone appears to contain only CRLF/LF changes, verify before changing anything:

```powershell
git diff --ignore-space-at-eol --stat
git diff --ignore-all-space --stat
```

Do not commit line-ending-only changes.

### 3. Check required tools

For Docker development, verify the following commands:

```powershell
git --version
docker version
docker compose version
node --version
npm --version
```

PHP and Composer are required when running relevant local PHP tooling outside Docker:

```powershell
php --version
composer --version
```

The repository requires PHP 8.1 or newer and Node.js 18 or newer according to `composer.json` and `package.json`.

### 4. Confirm required host directories

The current Docker development configuration expects:

```text
D:\xampp\htdocs\Edutek\videos
D:\edutek-system\index-status
```

Verify both paths:

```powershell
Test-Path D:\xampp\htdocs\Edutek\videos
Test-Path D:\edutek-system\index-status
```

Create the index-status folder if it is missing:

```powershell
New-Item -ItemType Directory -Force D:\edutek-system\index-status
```

Do not delete the content-library folder as part of ordinary development setup.

### 5. Create local environment configuration

Create a local `.env` file from the template:

```powershell
Copy-Item .\.env.example .\.env
```

Open it:

```powershell
code .\.env
```

Review the values for your local environment.

Never commit `.env`.

### 6. Validate and start Docker Compose

From the repository root:

```powershell
docker compose config
docker compose up --build -d
docker compose ps
```

The main local services are expected to use:

| Service | Purpose | Local address |
|---|---|---|
| `app` | EduTek Apache/PHP application | `http://localhost:8080` |
| `db` | MySQL database | `localhost:3307` |
| `phpmyadmin` | Local database administration | `http://localhost:8081` |
| `indexer` | One-shot content-indexing service | No public HTTP port |

If a service fails, inspect logs:

```powershell
docker compose logs --tail 150
docker compose logs --tail 150 app
docker compose logs --tail 150 db
docker compose logs --tail 150 indexer
```

Stop the environment when finished:

```powershell
docker compose down
```

Warning: do not run `docker compose down -v` unless you intentionally want to remove Docker named volumes, including local Docker database data.

---

## Branch workflow

Use a focused branch for every change.

### 1. Start from current `master`

From the Git clone:

```powershell
git status
git switch master
git pull --ff-only origin master
```

Your working tree must be clean before creating a new branch.

Expected result:

```text
nothing to commit, working tree clean
```

### 2. Create a branch

Use a lowercase branch prefix that describes the type of work.

```powershell
git switch -c <type>/<short-description>
```

Examples:

```text
docs/update-indexing-guide
docs/align-ui-indexing-september-2026
fix/audiobook-search-layout
fix/local-index-report-download
feat/add-learning-tool-card
chore/refresh-local-assets
```

### 3. Verify the active branch

```powershell
git branch --show-current
git status
```

Do not make ordinary work directly on `master`.

---

## Development commands

The repository contains three command interfaces:

| Source | Purpose |
|---|---|
| `docker-compose.yml` | Runs the local application services |
| `composer.json` | PHP test and PHP_CodeSniffer commands |
| `package.json` | JavaScript, CSS, Playwright, and Lighthouse commands |
| `Makefile` | Optional convenience commands, primarily for GNU Make/Git Bash/macOS/Linux workflows |

On Windows PowerShell, prefer direct `docker compose`, `npm`, and Composer commands unless you have GNU Make installed and are intentionally working in Git Bash or another compatible shell.

### Docker lifecycle

From the repository root:

```powershell
docker compose up -d
docker compose ps
docker compose logs -f
docker compose down
```

Build after Dockerfile, dependency, or image changes:

```powershell
docker compose up --build -d
```

Open a shell in the application container:

```powershell
docker compose exec app bash
```

Open a MySQL shell in the database container:

```powershell
docker compose exec db bash -c "mysql -u`$MYSQL_USER -p`$MYSQL_PASSWORD `$MYSQL_DATABASE"
```

### JavaScript and CSS tooling

Install locked Node dependencies:

```powershell
npm ci
```

List scripts:

```powershell
npm run
```

Run JavaScript linting:

```powershell
npm run lint:js
```

Run CSS linting:

```powershell
npm run lint:css
```

Run all JavaScript and CSS linting:

```powershell
npm run lint
```

Auto-fix JavaScript and CSS issues where supported:

```powershell
npm run lint:fix
```

Run end-to-end tests:

```powershell
npm run test:e2e
```

Run Playwright with its UI:

```powershell
npm run test:e2e:ui
```

Open the most recent Playwright report:

```powershell
npm run test:e2e:report
```

Run the configured Lighthouse performance command:

```powershell
npm run test:perf
```

### PHP tooling

Install PHP dependencies:

```powershell
composer install
```

List Composer scripts:

```powershell
composer run-script --list
```

Run PHP unit tests:

```powershell
composer test
```

Run PHP code-style checks:

```powershell
composer lint
```

Auto-fix PHP code-style violations where supported:

```powershell
composer lint-fix
```

Run the PHP coverage command:

```powershell
composer test-coverage
```

When using Docker-managed PHP dependencies, the Makefile runs PHP tools inside the `app` container. The equivalent direct commands are:

```powershell
docker compose exec -w /var/www app /var/www/vendor/bin/phpunit --colors=always
docker compose exec -w /var/www app /var/www/vendor/bin/phpcs -d memory_limit=512M --standard=/var/www/phpcs.xml /var/www/html/
```

Use the environment that contains the required dependencies. Do not assume host-installed Composer dependencies and container-installed dependencies are interchangeable.

### Optional Makefile commands

The Makefile provides convenience commands:

```text
make help
make vendor
make dev
make down
make logs
make shell
make db
make lint
make lint-fix
make test
make test-e2e
make test-all
make migrate
make migrate-status
make migrate-rollback
make perf
make optimize-images
```

On Windows, `make` and the shell utilities used by the Makefile are not available by default. If you use Make, run it from Git Bash, WSL, or another compatible environment with GNU Make and Bash installed.

Be especially careful with:

```text
make clean
```

The current Make target removes build artifacts, deletes log files, and runs:

```text
docker compose down -v
```

That command removes Docker volumes and can erase local Docker database data.

---

## Testing requirements

Run checks that are relevant to the files you changed.

### Documentation-only changes

At minimum:

```powershell
git diff --check
git diff --cached --check
```

Also confirm:

- Links, paths, commands, route names, and filenames match the current repository.
- No stale user-facing claims remain after a related UI or workflow change.
- No `.env`, local report, cache, log, media, or generated artifact was staged.

### PHP changes

Run appropriate PHP checks:

```powershell
composer lint
composer test
```

Or use the Docker container equivalents when that is where dependencies are installed.

Add or update PHPUnit tests for new behavior when applicable.

### JavaScript and CSS changes

Run:

```powershell
npm run lint
```

Use auto-fix only after reviewing the scope of files it may change:

```powershell
npm run lint:fix
```

Review all resulting changes before staging.

### Browser/UI changes

Run relevant Playwright tests:

```powershell
npm run test:e2e
```

Also test the changed behavior manually in the local application:

```text
http://localhost:8080
```

For UI changes, verify:

- Keyboard navigation.
- Visible focus states.
- Narrow mobile layout.
- Browser zoom.
- Offline behavior when applicable.
- Local media paths and expected playback/reading behavior.

### Indexing changes

For changes affecting content indexing, test:

- New or updated content discovery.
- Books/PDF category visibility.
- Audiobook folder discovery and search.
- Comic Books discovery and cover fallback behavior.
- Protected teacher reindex authorization/CSRF behavior.
- Local maintenance indexing from the host browser only.
- Verification CSV behavior when changed content exists.
- Persistence of status/report files in:

  ```text
  D:\edutek-system\index-status
  ```

The local maintenance shortcut is:

```text
Ctrl + Alt + Shift + I
```

It is host-local only. Do not document or implement it as a remote network-client action.

---

## Code and documentation standards

### PHP

The repository uses PHP 8.1 or newer and PHP_CodeSniffer configuration in:

```text
phpcs.xml
```

Use the configured linter rather than assuming a generic style rule is sufficient.

For new PHP code:

- Use strict types where compatible with the file and application conventions.
- Use parameter and return types where practical and compatible.
- Validate request input.
- Escape output intended for HTML.
- Preserve authorization and CSRF protections.
- Avoid hard-coded secrets, machine-specific paths, and production-only assumptions.
- Use shared configuration and helper functions rather than duplicating sensitive logic.

### JavaScript

The repository ESLint configuration is:

```text
.eslintrc.json
```

Use the project linter:

```powershell
npm run lint:js
```

For new JavaScript:

- Prefer `const` and `let` for new code unless existing surrounding code requires compatibility with older syntax.
- Avoid committing debugging output.
- Handle failure states for browser requests.
- Preserve keyboard and accessible interaction behavior.
- Do not require an external CDN for essential application functionality.

### CSS

The repository Stylelint configuration is:

```text
.stylelintrc.json
```

Use:

```powershell
npm run lint:css
```

For new CSS:

- Preserve responsive behavior.
- Preserve visible focus states.
- Avoid breaking existing shared page styles.
- Test small, medium, and desktop viewport widths.
- Avoid unreviewed global selector changes.

### Documentation

Documentation is part of the product.

When a change affects a page, route, visible label, workflow, deployment dependency, media-discovery rule, keyboard shortcut, security boundary, Docker mount, or environment variable:

1. Update the related documentation in the same branch.
2. Use exact paths, commands, and user-visible wording.
3. Mark historical plans and removed features clearly.
4. Do not present an untested feature as current.
5. Verify instructions in the environment they describe.

Use LF line endings for Markdown files, as enforced by `.gitattributes`.

---

## Git workflow

### Inspect before staging

Before staging any changes:

```powershell
git status
git diff --check
git diff --stat
git diff
```

### Stage only intended files

Do not use `git add .` unless you have carefully reviewed every changed file.

Prefer explicit staging:

```powershell
git add path/to/file1 path/to/file2
```

For a Markdown file needing line-ending normalization:

```powershell
git add --renormalize docs/example.md
```

### Review the staged commit

Before committing:

```powershell
git status
git diff --cached --check
git diff --cached --stat
git diff --cached
```

Verify that the staged set does not contain:

```text
.env
*.log
local media files
database files
generated reports
cache files
Docker volumes
local backups
temporary files
```

### Commit messages

Use a concise, imperative Conventional Commit-style subject:

```text
docs: align Home page documentation with current UI
fix: correct audiobook search pagination
feat: add local learning tool card
chore: refresh local vendor assets
```

Keep the subject clear and focused. Add a body when context, deployment impact, migration steps, or testing details need to be preserved.

### Push and pull request

Push the branch:

```powershell
git push -u origin <branch-name>
```

Create a pull request with:

- Base branch: `master`
- A clear title using the same intent as the commit message.
- A description of user-facing, deployment, indexing, or documentation effects.
- Tests/checks performed.
- Any known limitations or required manual verification.

Do not merge your own change until the final diff, checks, and relevant local behavior have been reviewed.

---

## Pull request checklist

Use this checklist before requesting review.

```markdown
## Summary

- What changed:
- Why it changed:
- User-facing impact:
- Deployment or content-library impact:

## Verification

- [ ] `git diff --check` passes
- [ ] `git diff --cached --check` passes
- [ ] Relevant PHP checks pass
- [ ] Relevant JavaScript/CSS checks pass
- [ ] Relevant Playwright checks pass
- [ ] Changed UI behavior tested locally
- [ ] Documentation updated where needed
- [ ] No secrets, `.env`, reports, caches, logs, media, or database data staged

## Indexing or deployment impact

- [ ] Not applicable
- [ ] Docker Compose/configuration reviewed
- [ ] Content-library path behavior verified
- [ ] Indexing behavior verified
- [ ] Local-only restrictions preserved
- [ ] Migration requirements documented
```

---

## Deployment safety

The repository includes a `make deploy` target that calls:

```text
scripts/deploy.sh
```

Do not use it until you have reviewed the current deployment script and confirmed:

- The target machine and destination paths.
- Required backups.
- Excluded local media, secrets, databases, logs, caches, and report folders.
- Rollback behavior.
- Whether the script is safe for your current deployment environment.

For ordinary source and documentation work, use a pull request into `master` rather than treating a local deployment script as a substitute for code review.

---

## Security and offline-first requirements

EduTek is intended to work in offline or low-connectivity environments.

All contributors must preserve these principles:

- Keep required assets local whenever possible.
- Do not introduce mandatory CDN dependencies for core features.
- Do not hard-code credentials, encryption keys, database passwords, or machine-specific secrets.
- Keep local maintenance APIs restricted to their intended access model.
- Preserve teacher authorization and CSRF validation for protected operations.
- Do not make local-only indexing endpoints remotely accessible without a deliberate security design.
- Keep local media, index reports, cache files, logs, generated files, and database data out of Git.
- Test important user paths without relying on internet access.

---

## Related documentation

| Document | Purpose |
|---|---|
| `README.md` | Project overview, current features, setup, and local verification |
| `docs/01-visual-home-tiles.md` | Current Home-page navigation and layout |
| `docs/02-continue-watching.md` | Historical Continue Watching specification |
| `docs/05-teacher-content-finder.md` | Content finding and indexing workflows |
| `docs/architecture.md` | Technical architecture |
| `docs/device-matrix.md` | Device roles, deployment models, and validation |
| `Makefile` | Optional development command shortcuts |
| `package.json` | JavaScript, CSS, Playwright, and Lighthouse scripts |
| `composer.json` | PHP test and lint scripts |
| `.github/workflows/ci.yml` | Automated CI requirements |

Planning documents under `docs/` may describe proposed or historical work. Read their status labels before treating them as current requirements.