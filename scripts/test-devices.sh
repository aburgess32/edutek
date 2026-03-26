#!/usr/bin/env bash
# ============================================================
#  EduPak Device Testing Runner
#
#  Usage:
#    ./scripts/test-devices.sh              # Run ALL tiers
#    ./scripts/test-devices.sh tier1        # Ultra-budget only
#    ./scripts/test-devices.sh tier2        # Budget only
#    ./scripts/test-devices.sh tier1 tier2  # Multiple tiers
#    ./scripts/test-devices.sh baseline     # Just Itel A60 (THE baseline)
#    ./scripts/test-devices.sh mobile       # All mobile (T1-T3)
#    ./scripts/test-devices.sh screens      # All screens (T4-T5)
#    ./scripts/test-devices.sh --headed     # Visual inspection mode
#    ./scripts/test-devices.sh --update     # Update snapshots
# ============================================================

set -euo pipefail

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

# Project-to-tier mapping
declare -A TIER_PROJECTS
TIER_PROJECTS[tier1]="t1-itel-a60 t1-itel-a70 t1-tecno-pop8"
TIER_PROJECTS[tier2]="t2-samsung-a06 t2-infinix-smart9 t2-xiaomi-redmi-a5 t2-tecno-spark10"
TIER_PROJECTS[tier3]="t3-samsung-a16-5g t3-tecno-spark20 t3-samsung-a05"
TIER_PROJECTS[tier4]="t4-tablet-7inch t4-tablet-10inch"
TIER_PROJECTS[tier5]="t5-projector-720p t5-projector-1080p t5-tv-768p t5-rpi-kiosk"
TIER_PROJECTS[tier6]="t6-kaios-feature-phone"
TIER_PROJECTS[baseline]="t1-itel-a60"
TIER_PROJECTS[mobile]="${TIER_PROJECTS[tier1]} ${TIER_PROJECTS[tier2]} ${TIER_PROJECTS[tier3]}"
TIER_PROJECTS[screens]="${TIER_PROJECTS[tier4]} ${TIER_PROJECTS[tier5]}"

# Parse arguments
HEADED=""
UPDATE=""
TIERS=()

for arg in "$@"; do
  case "$arg" in
    --headed) HEADED="--headed" ;;
    --update) UPDATE="--update-snapshots" ;;
    tier[1-6]|baseline|mobile|screens|all) TIERS+=("$arg") ;;
    *) echo -e "${RED}Unknown argument: $arg${NC}"; exit 1 ;;
  esac
done

# Default to all
if [ ${#TIERS[@]} -eq 0 ]; then
  TIERS=("all")
fi

# Collect projects to run
PROJECTS=""
for tier in "${TIERS[@]}"; do
  if [ "$tier" = "all" ]; then
    PROJECTS=""  # Empty = run all projects
    break
  fi
  PROJECTS="$PROJECTS ${TIER_PROJECTS[$tier]:-}"
done

# Header
echo -e "${CYAN}╔══════════════════════════════════════════════╗${NC}"
echo -e "${CYAN}║   EduPak Device Testing Suite                ║${NC}"
echo -e "${CYAN}║   17 African market device profiles          ║${NC}"
echo -e "${CYAN}╚══════════════════════════════════════════════╝${NC}"
echo ""

# Show what we're testing
if [ -z "$PROJECTS" ]; then
  echo -e "${BLUE}Tiers:${NC} ALL (17 device profiles)"
else
  echo -e "${BLUE}Tiers:${NC} ${TIERS[*]}"
  echo -e "${BLUE}Devices:${NC} $PROJECTS"
fi
echo ""

# Build project flags
PROJECT_FLAGS=""
if [ -n "$PROJECTS" ]; then
  for proj in $PROJECTS; do
    PROJECT_FLAGS="$PROJECT_FLAGS --project=$proj"
  done
fi

# Results tracking
PASS=0
FAIL=0
RESULTS=""
REPORT_DIR="reports/device-test-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$REPORT_DIR"

# Run tests
echo -e "${YELLOW}Running tests...${NC}"
echo ""

if [ -n "$PROJECT_FLAGS" ]; then
  CMD="npx playwright test $PROJECT_FLAGS $HEADED $UPDATE --reporter=html,list"
else
  CMD="npx playwright test $HEADED $UPDATE --reporter=html,list"
fi

echo -e "${BLUE}Command:${NC} $CMD"
echo ""

if eval "$CMD"; then
  echo ""
  echo -e "${GREEN}═══════════════════════════════════════${NC}"
  echo -e "${GREEN}  ALL TESTS PASSED ✓                    ${NC}"
  echo -e "${GREEN}═══════════════════════════════════════${NC}"
else
  EXIT_CODE=$?
  echo ""
  echo -e "${RED}═══════════════════════════════════════${NC}"
  echo -e "${RED}  SOME TESTS FAILED (exit code $EXIT_CODE)   ${NC}"
  echo -e "${RED}═══════════════════════════════════════${NC}"
fi

# Point to report
echo ""
echo -e "${CYAN}HTML Report:${NC} reports/playwright/index.html"
echo -e "${CYAN}Open with:${NC}  npx playwright show-report reports/playwright"
echo ""

# Quick reference
echo -e "${YELLOW}Quick commands:${NC}"
echo "  ./scripts/test-devices.sh baseline     # Itel A60 only (fastest)"
echo "  ./scripts/test-devices.sh tier1         # All ultra-budget"
echo "  ./scripts/test-devices.sh mobile        # All phones (T1-T3)"
echo "  ./scripts/test-devices.sh --headed      # Visual inspection"
