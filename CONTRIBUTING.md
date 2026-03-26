# Contributing to EduPak

EduPak is an offline-first educational content platform deployed on XAMPP. These guidelines exist to keep the codebase consistent, safe, and deployable on low-spec hardware without an internet connection.

---

## Table of Contents

1. [Branching Strategy](#branching-strategy)
2. [Commit Format](#commit-format)
3. [Pull Request Process](#pull-request-process)
4. [Local Development Setup](#local-development-setup)
5. [Testing Requirements](#testing-requirements)
6. [Code Style](#code-style)
7. [Deployment](#deployment)

---

## Branching Strategy

| Branch       | Purpose                                  | Direct push? |
|--------------|------------------------------------------|--------------|
| `master`     | Production-ready. Deployed via deploy.sh | No           |
| `dev`        | Integration branch. All PRs target here  | No           |
| `feature/*`  | New functionality                        | Yes (own)    |
| `fix/*`      | Bug corrections                          | Yes (own)    |

**Rules:**
- Branch from `dev`, not from `master`.
- `master` only receives merges from `dev` after QA sign-off.
- Delete branches after merge.

```
# Start a new feature
git checkout dev
git pull origin dev
git checkout -b feature/my-feature

# Start a bug fix
git checkout dev
git pull origin dev
git checkout -b fix/issue-42-login-crash
```

---

## Commit Format

Commits must include a priority prefix and a short scope description:

```
[P1] Visual Home Tiles: add CSS grid layout
[P2] Continue Watching: fix progress percentage rounding
[P3] Simple Login: sanitize display name input
[P4] Breadcrumb: handle missing parent category
[P5] Teacher Finder: add subject filter index
[Chore] Makefile: add lint-fix target
[Fix] Logger: prevent crash when logs/ directory missing
[Docs] CONTRIBUTING: clarify rollback procedure
```

**Priority levels** map to the project feature roadmap:
- `[P1]` — Visual Home Tiles
- `[P2]` — Continue Watching
- `[P3]` — Simple Name Login
- `[P4]` — Breadcrumb Path
- `[P5]` — Teacher Content Finder
- `[Chore]`, `[Fix]`, `[Docs]`, `[Refactor]` — non-feature work

**Rules:**
- Keep the subject line under 72 characters.
- Use imperative mood ("add", "fix", "update", not "added", "fixed").
- Reference issue numbers in the body: `Closes #42`.

---

## Pull Request Process

1. **Branch** from `dev` (never from `master`).
2. **Implement** your changes with appropriate tests.
3. **Run** `make test-all` locally — all checks must pass before opening a PR.
4. **Open** a PR targeting `dev` using the PR template.
5. **Request review** from at least one other contributor.
6. **Squash merge** — one clean commit per PR on `dev`.
7. **Delete** your branch after merge.

PRs that fail the checklist in the PR template will not be merged.

---

## Local Development Setup

### Prerequisites

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) (or Docker Engine + Compose)
- PHP 8.1+ (for running migrate.php outside Docker)
- Node.js 18+ and npm (for JS tooling and Playwright)
- `make` (pre-installed on macOS/Linux; use Git Bash on Windows)

### First-Time Setup

```bash
# 1. Clone the repo
git clone https://github.com/your-org/edupak.git
cd edupak

# 2. Run the automated setup (copies .env.example, builds containers,
#    installs deps, and runs migrations)
make setup
```

`make setup` performs these steps in order:
1. Copies `.env.example` → `.env` (if .env doesn't exist)
2. Runs `composer install` inside the PHP container
3. Runs `npm install` for JS/Playwright tooling
4. Builds Docker images: `docker compose build`
5. Starts containers: `docker compose up -d`
6. Runs pending DB migrations: `make migrate`

### Daily Development

```bash
make dev          # Start containers in background
make logs         # Tail all container logs
make shell        # Open bash shell in PHP container
make db           # Open MySQL CLI in DB container
make down         # Stop and remove containers
```

### Environment Configuration

Copy `.env.example` to `.env` and adjust as needed:

```bash
cp .env.example .env
```

Key variables:

| Variable       | Default               | Notes                                   |
|----------------|-----------------------|-----------------------------------------|
| `DB_HOST`      | `localhost`           | Use `db` inside Docker Compose          |
| `DB_NAME`      | `edupak`              |                                         |
| `DB_USER`      | `root`                |                                         |
| `DB_PASS`      | _(empty)_             | Set a password for non-dev environments |
| `APP_ENV`      | `dev`                 | `dev` \| `staging` \| `prod`            |
| `APP_DEBUG`    | `true`                | Set `false` in prod                     |
| `LOG_LEVEL`    | `debug`               | `debug` \| `info` \| `warn` \| `error` |
| `CONTENT_PATH` | `/content/`           | Path to the offline content library     |

---

## Testing Requirements

All of the following must pass before a PR can be merged:

### PHP Unit Tests (PHPUnit)

```bash
make test
# or directly:
docker compose exec php ./vendor/bin/phpunit --colors=always
```

- Tests live in `tests/Unit/` and `tests/Integration/`.
- Write tests for all new PHP functions and classes.
- Minimum coverage target: 70% on new code.

### End-to-End Tests (Playwright)

```bash
make test-e2e
# or directly:
npx playwright test
```

- Playwright config: `playwright.config.js`
- Tests live in `tests/e2e/`.
- Must cover the primary user flows for any feature touched.
- Run against `http://localhost:8080` (Docker Compose default).

### Linting

```bash
make lint      # Check all linters
make lint-fix  # Auto-fix what's fixable
```

Linting covers:
- **PHP**: PHP_CodeSniffer (PSR-12) via `phpcs`
- **JS**: ESLint
- **CSS**: Stylelint

### Full Suite

```bash
make test-all   # lint + test + test-e2e
```

---

## Code Style

### PHP — PSR-12

- Follow the [PSR-12 Extended Coding Style](https://www.php-fig.org/psr/psr-12/).
- Use `declare(strict_types=1);` at the top of every PHP file.
- All functions and classes must have PHPDoc blocks.
- Use typed parameters and return types on all functions.

Key rules:
```php
// Correct
function sanitize_name(string $name): string
{
    return trim($name);
}

// Wrong — no types, wrong brace position
function sanitize_name($name) {
    return trim($name);
}
```

### JavaScript — ESLint Defaults

- `eslint --init` defaults (no custom config required).
- Prefer `const`/`let` over `var`.
- No `console.log` in committed code.

### SQL

- UPPER CASE for SQL keywords.
- Snake_case for table and column names.
- All tables must define `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`.
- Schema changes must be added as a new migration file (do not modify existing migration files).

---

## Deployment

Deployments run only from the `master` branch via `deploy.sh`:

```bash
# Never deploy manually — always use the script
make deploy
```

`deploy.sh` handles:
1. Running `make test-all` as a pre-deploy gate.
2. Pulling latest `master`.
3. Running any pending DB migrations.
4. Restarting Apache/PHP via XAMPP controls.
5. Clearing the opcode cache.

**Deployment checklist:**
- [ ] `dev` has been tested and reviewed.
- [ ] `dev` has been merged to `master` via a PR.
- [ ] `make test-all` passes on `master`.
- [ ] A git tag has been created: `git tag v1.x.x`.
- [ ] Deployment is announced in team chat before execution.

---

## Questions?

Open a GitHub Discussion or ping the team in the project channel.
