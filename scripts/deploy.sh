#!/usr/bin/env bash
# =============================================================================
# EduPak Deploy Script
# =============================================================================
# Packages the EduPak application into a timestamped tar.gz for deployment
# onto EduPak devices.
#
# Usage:
#   ./scripts/deploy.sh [--skip-lint] [--skip-tests]
#
# Output:
#   dist/edupak-YYYYMMDD-HHMMSS.tar.gz
#
# Requirements:
#   - PHP 8.1+ in PATH
#   - Composer (for linting / testing)
#   - Node 18+ and npm (for JS/CSS linting)
#   - ImageMagick or mozjpeg/pngquant (for image optimisation)
# =============================================================================

set -euo pipefail

# ── Configuration ─────────────────────────────────────────────────────────────
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
TIMESTAMP="$(date +%Y%m%d-%H%M%S)"
ARCHIVE_NAME="edupak-${TIMESTAMP}.tar.gz"
DIST_DIR="${PROJECT_ROOT}/dist"
ARCHIVE_PATH="${DIST_DIR}/${ARCHIVE_NAME}"
STAGING_DIR="$(mktemp -d /tmp/edupak-staging-XXXXXX)"

# Flags
SKIP_LINT=false
SKIP_TESTS=false

# Colour helpers
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Colour

log()  { echo -e "${BLUE}[deploy]${NC} $*"; }
ok()   { echo -e "${GREEN}[ok]${NC}    $*"; }
warn() { echo -e "${YELLOW}[warn]${NC}  $*"; }
fail() { echo -e "${RED}[fail]${NC}  $*" >&2; exit 1; }

# ── Parse arguments ───────────────────────────────────────────────────────────
for arg in "$@"; do
  case $arg in
    --skip-lint)  SKIP_LINT=true ;;
    --skip-tests) SKIP_TESTS=true ;;
    --help|-h)
      echo "Usage: $0 [--skip-lint] [--skip-tests]"
      exit 0
      ;;
    *) fail "Unknown argument: $arg" ;;
  esac
done

# ── Step 0: Preflight ─────────────────────────────────────────────────────────
log "EduPak build starting — ${TIMESTAMP}"
log "Project root: ${PROJECT_ROOT}"

command -v php  >/dev/null 2>&1 || fail "php not found in PATH"
command -v tar  >/dev/null 2>&1 || fail "tar not found in PATH"

mkdir -p "${DIST_DIR}"

# ── Step 1: Lint ──────────────────────────────────────────────────────────────
if [ "${SKIP_LINT}" = false ]; then
  log "Running lints..."

  cd "${PROJECT_ROOT}"

  # PHP_CodeSniffer
  if [ -f vendor/bin/phpcs ]; then
    log "  PHP_CodeSniffer..."
    vendor/bin/phpcs --standard=PSR12 --extensions=php \
      --ignore=vendor,node_modules,dist,tests \
      htdocs/ \
      || fail "PHP_CodeSniffer found violations. Fix them or use --skip-lint."
    ok "  phpcs passed"
  else
    warn "  phpcs not found (run: composer require --dev squizlabs/php_codesniffer)"
  fi

  # ESLint
  if command -v npx >/dev/null 2>&1; then
    if [ -f .eslintrc.js ] || [ -f .eslintrc.json ] || [ -f .eslintrc.yml ] || [ -f eslint.config.js ]; then
      log "  ESLint..."
      npx eslint "htdocs/js/**/*.js" --max-warnings=0 \
        || fail "ESLint found errors. Fix them or use --skip-lint."
      ok "  eslint passed"
    else
      warn "  No ESLint config found — skipping JS lint"
    fi

    # Stylelint
    if [ -f .stylelintrc.json ] || [ -f .stylelintrc.js ] || [ -f stylelint.config.js ]; then
      log "  Stylelint..."
      npx stylelint "htdocs/css/**/*.css" --max-warnings=0 \
        || fail "Stylelint found errors. Fix them or use --skip-lint."
      ok "  stylelint passed"
    else
      warn "  No Stylelint config found — skipping CSS lint"
    fi
  else
    warn "  npx not found — skipping JS/CSS lints"
  fi
else
  warn "Linting skipped (--skip-lint)"
fi

# ── Step 2: Tests ─────────────────────────────────────────────────────────────
if [ "${SKIP_TESTS}" = false ]; then
  log "Running PHPUnit tests..."
  cd "${PROJECT_ROOT}"

  if [ -f vendor/bin/phpunit ]; then
    vendor/bin/phpunit --configuration phpunit.xml --testdox \
      || fail "PHPUnit tests failed. Fix them or use --skip-tests."
    ok "Tests passed"
  elif command -v phpunit >/dev/null 2>&1; then
    phpunit --configuration phpunit.xml --testdox \
      || fail "PHPUnit tests failed. Fix them or use --skip-tests."
    ok "Tests passed"
  else
    warn "PHPUnit not found — skipping tests"
  fi
else
  warn "Tests skipped (--skip-tests)"
fi

# ── Step 3: Stage files ───────────────────────────────────────────────────────
log "Staging files to ${STAGING_DIR}..."

# Copy only the directories/files needed on the device
rsync -av --progress \
  "${PROJECT_ROOT}/htdocs/" \
  "${STAGING_DIR}/htdocs/"

rsync -av --progress \
  "${PROJECT_ROOT}/db/schema.sql" \
  "${STAGING_DIR}/db/schema.sql"

rsync -av --progress \
  "${PROJECT_ROOT}/config/" \
  "${STAGING_DIR}/config/"

# Copy .htaccess if it exists at project root
if [ -f "${PROJECT_ROOT}/.htaccess" ]; then
  cp "${PROJECT_ROOT}/.htaccess" "${STAGING_DIR}/.htaccess"
fi

ok "Files staged"

# ── Step 4: Strip dev files from staging ─────────────────────────────────────
log "Stripping development files..."

# Remove test stubs/fixtures that landed under htdocs
find "${STAGING_DIR}" -type d -name "__tests__"   -exec rm -rf {} + 2>/dev/null || true
find "${STAGING_DIR}" -type f -name "*.test.php"  -delete 2>/dev/null || true
find "${STAGING_DIR}" -type f -name "*.spec.js"   -delete 2>/dev/null || true
find "${STAGING_DIR}" -type f -name ".DS_Store"   -delete 2>/dev/null || true
find "${STAGING_DIR}" -type f -name "Thumbs.db"   -delete 2>/dev/null || true

# Remove any accidental composer/npm dev artefacts
rm -rf "${STAGING_DIR}/htdocs/vendor" 2>/dev/null || true
rm -rf "${STAGING_DIR}/htdocs/node_modules" 2>/dev/null || true

ok "Dev files stripped"

# ── Step 5: Optimise images ───────────────────────────────────────────────────
log "Optimising images..."

OPTIMISED=0
SKIPPED_IMG=0

# JPEG optimisation via mozjpeg / jpegoptim
if command -v jpegoptim >/dev/null 2>&1; then
  while IFS= read -r -d '' jpg; do
    jpegoptim --max=85 --strip-all --quiet "$jpg" && ((OPTIMISED++)) || true
  done < <(find "${STAGING_DIR}" -type f \( -iname "*.jpg" -o -iname "*.jpeg" \) -print0)
else
  warn "  jpegoptim not found — JPEG optimisation skipped"
  ((SKIPPED_IMG++)) || true
fi

# PNG optimisation via pngquant
if command -v pngquant >/dev/null 2>&1; then
  while IFS= read -r -d '' png; do
    pngquant --force --quality=65-90 --skip-if-larger --output "$png" "$png" 2>/dev/null && ((OPTIMISED++)) || true
  done < <(find "${STAGING_DIR}" -type f -iname "*.png" -print0)
else
  warn "  pngquant not found — PNG optimisation skipped"
fi

ok "Image optimisation done (optimised: ${OPTIMISED}, tools missing: ${SKIPPED_IMG})"

# ── Step 6: Create archive ────────────────────────────────────────────────────
log "Creating archive ${ARCHIVE_NAME}..."

# Write a build-info file into the archive
cat > "${STAGING_DIR}/BUILD_INFO" <<EOF
EduPak Build
============
Archive    : ${ARCHIVE_NAME}
Built at   : $(date -u +"%Y-%m-%dT%H:%M:%SZ")
Git commit : $(cd "${PROJECT_ROOT}" && git rev-parse --short HEAD 2>/dev/null || echo "unknown")
Git branch : $(cd "${PROJECT_ROOT}" && git rev-parse --abbrev-ref HEAD 2>/dev/null || echo "unknown")
Builder    : $(whoami)@$(hostname)
EOF

tar -czf "${ARCHIVE_PATH}" \
  --exclude=".git" \
  --exclude="node_modules" \
  --exclude="tests" \
  --exclude="docker" \
  --exclude="docs" \
  --exclude=".github" \
  -C "${STAGING_DIR}" \
  .

ARCHIVE_SIZE="$(du -sh "${ARCHIVE_PATH}" | cut -f1)"
ok "Archive created: ${ARCHIVE_PATH} (${ARCHIVE_SIZE})"

# ── Step 7: Cleanup ───────────────────────────────────────────────────────────
rm -rf "${STAGING_DIR}"
log "Staging directory cleaned up"

# ── Done ──────────────────────────────────────────────────────────────────────
echo ""
echo -e "${GREEN}════════════════════════════════════════════════════════${NC}"
echo -e "${GREEN}  EduPak build complete!${NC}"
echo -e "${GREEN}  Archive: dist/${ARCHIVE_NAME}${NC}"
echo -e "${GREEN}  Size:    ${ARCHIVE_SIZE}${NC}"
echo -e "${GREEN}════════════════════════════════════════════════════════${NC}"
echo ""
echo "Next step: run ./scripts/deploy-to-device.sh to copy to EduPak"
