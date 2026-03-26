#!/usr/bin/env bash
# =============================================================================
# EduPak — Deploy to Device (Manual Steps Reference)
# =============================================================================
# This script documents the process for pushing a built EduPak archive onto
# a physical EduPak device. Steps differ depending on whether you have SSH
# network access or are performing a USB / SD-card transfer.
#
# Run deploy.sh first to produce the archive in dist/.
#
# Usage:
#   ./scripts/deploy-to-device.sh [ARCHIVE] [DEVICE_IP]
#
# Examples:
#   ./scripts/deploy-to-device.sh dist/edupak-20260326-154500.tar.gz 192.168.1.100
#   ./scripts/deploy-to-device.sh                  # walks you through each step manually
#
# Assumptions:
#   - EduPak runs Raspberry Pi OS (Debian-based) or similar Linux
#   - Apache document root:  /var/www/html/
#   - MySQL credentials stored in:  /var/www/html/includes/config.php
#   - SSH user:  pi  (or edupak — update DEVICE_USER below)
#   - XAMPP / system Apache managed via systemctl or /opt/lampp/lampp
#
# =============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

# ── Configuration — edit these for your EduPak ────────────────────────────────
DEVICE_USER="${DEVICE_USER:-pi}"
DEVICE_DOCROOT="${DEVICE_DOCROOT:-/var/www/html}"
DEVICE_BACKUP_DIR="${DEVICE_BACKUP_DIR:-/home/pi/edupak-backups}"
MYSQL_USER="${MYSQL_USER:-root}"
MYSQL_DB="${MYSQL_DB:-edupak}"
APACHE_SERVICE="${APACHE_SERVICE:-apache2}"  # or 'httpd' / '/opt/lampp/lampp'

# Colour helpers
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

log()  { echo -e "${BLUE}[deploy-to-device]${NC} $*"; }
ok()   { echo -e "${GREEN}[ok]${NC}    $*"; }
warn() { echo -e "${YELLOW}[warn]${NC}  $*"; }
fail() { echo -e "${RED}[fail]${NC}  $*" >&2; exit 1; }
step() { echo ""; echo -e "${YELLOW}── Step $1: $2 ──${NC}"; }

# ── Parse arguments ───────────────────────────────────────────────────────────
ARCHIVE="${1:-}"
DEVICE_IP="${2:-}"

# ── Preflight ─────────────────────────────────────────────────────────────────
if [ -z "${ARCHIVE}" ]; then
  # Find the latest archive if none specified
  ARCHIVE="$(ls -t "${PROJECT_ROOT}/dist"/edupak-*.tar.gz 2>/dev/null | head -1 || true)"
  if [ -z "${ARCHIVE}" ]; then
    fail "No archive found in dist/. Run ./scripts/deploy.sh first."
  fi
  warn "No archive specified — using latest: $(basename "${ARCHIVE}")"
fi

[ -f "${ARCHIVE}" ] || fail "Archive not found: ${ARCHIVE}"

if [ -z "${DEVICE_IP}" ]; then
  echo ""
  echo "No DEVICE_IP provided. Enter the EduPak device IP address:"
  read -r DEVICE_IP
  [ -n "${DEVICE_IP}" ] || fail "Device IP is required."
fi

ARCHIVE_NAME="$(basename "${ARCHIVE}")"
log "Archive:   ${ARCHIVE_NAME}"
log "Device:    ${DEVICE_USER}@${DEVICE_IP}"
log "Docroot:   ${DEVICE_DOCROOT}"

# =============================================================================
# MANUAL STEPS — each section explains what the script does and why.
# You can run the whole script (SSH method) or follow each section by hand
# (USB method) using the comments as a guide.
# =============================================================================

# ─────────────────────────────────────────────────────────────────────────────
# STEP 1 — Build the archive (if not already done)
# ─────────────────────────────────────────────────────────────────────────────
step 1 "Verify archive exists"
# Manual equivalent:
#   cd /path/to/edutek
#   ./scripts/deploy.sh
log "Using archive: ${ARCHIVE}"

# ─────────────────────────────────────────────────────────────────────────────
# STEP 2 — Transfer archive to device
# ─────────────────────────────────────────────────────────────────────────────
step 2 "Transfer archive to EduPak"
log "Copying ${ARCHIVE_NAME} to ${DEVICE_USER}@${DEVICE_IP}:/tmp/ ..."

# SSH/SCP transfer (requires network access to device):
#   scp dist/edupak-TIMESTAMP.tar.gz pi@DEVICE_IP:/tmp/
#
# USB transfer (no network required):
#   1. Copy the .tar.gz to a USB drive
#   2. Insert USB into EduPak device
#   3. Mount: sudo mount /dev/sda1 /mnt/usb
#   4. Copy:  sudo cp /mnt/usb/edupak-TIMESTAMP.tar.gz /tmp/
#   5. Unmount: sudo umount /mnt/usb
#
# SD-card transfer (advanced):
#   1. Remove SD card from EduPak
#   2. Mount on your machine
#   3. Copy archive to /tmp on the SD card partition
#   4. Reinsert SD card

if command -v scp >/dev/null 2>&1; then
  scp "${ARCHIVE}" "${DEVICE_USER}@${DEVICE_IP}:/tmp/${ARCHIVE_NAME}" \
    || fail "SCP transfer failed. Check SSH access, IP address, and device connectivity."
  ok "Archive transferred via SCP"
else
  warn "scp not found. Transfer the archive manually:"
  warn "  scp ${ARCHIVE} ${DEVICE_USER}@${DEVICE_IP}:/tmp/"
  echo "Press Enter once the file is on the device..."
  read -r
fi

# ─────────────────────────────────────────────────────────────────────────────
# STEP 3 — Back up current app on device
# ─────────────────────────────────────────────────────────────────────────────
step 3 "Back up current installation on device"
# This creates a timestamped backup of the live htdocs before overwriting.
# If the deployment goes wrong, restore with:
#   sudo cp -a /home/pi/edupak-backups/htdocs-TIMESTAMP /var/www/html

BACKUP_CMD="sudo mkdir -p ${DEVICE_BACKUP_DIR} && \
  sudo cp -a ${DEVICE_DOCROOT} ${DEVICE_BACKUP_DIR}/htdocs-\$(date +%Y%m%d-%H%M%S)"

log "Creating backup on device..."
ssh "${DEVICE_USER}@${DEVICE_IP}" "${BACKUP_CMD}" \
  || warn "Backup step failed — proceeding anyway. Create backups manually before continuing on production."
ok "Backup created at ${DEVICE_BACKUP_DIR}/ on device"

# ─────────────────────────────────────────────────────────────────────────────
# STEP 4 — Extract archive onto device
# ─────────────────────────────────────────────────────────────────────────────
step 4 "Extract archive to document root"
# The archive contains: htdocs/, db/schema.sql, config/, BUILD_INFO
# We extract htdocs/ into the Apache document root.

EXTRACT_CMD="cd /tmp && \
  tar -xzf /tmp/${ARCHIVE_NAME} && \
  sudo rsync -av --delete \
    --exclude='config.php' \
    /tmp/htdocs/ ${DEVICE_DOCROOT}/ && \
  sudo chown -R www-data:www-data ${DEVICE_DOCROOT}/ && \
  sudo chmod -R 755 ${DEVICE_DOCROOT}/"

log "Extracting and syncing files..."
ssh "${DEVICE_USER}@${DEVICE_IP}" "${EXTRACT_CMD}" \
  || fail "Extraction failed. Check disk space and permissions on the device."
ok "Files synced to ${DEVICE_DOCROOT}"

# ─────────────────────────────────────────────────────────────────────────────
# STEP 5 — Run database migrations
# ─────────────────────────────────────────────────────────────────────────────
step 5 "Run database migrations"
# schema.sql uses CREATE TABLE IF NOT EXISTS, so re-running it is safe
# for adding new tables. For ALTER TABLE migrations, place numbered migration
# files in db/migrations/ and run them in order here.

MIGRATE_CMD="cd /tmp && \
  mysql -u ${MYSQL_USER} -p ${MYSQL_DB} < /tmp/db/schema.sql"

log "Importing db/schema.sql on device..."
log "(You will be prompted for the MySQL ${MYSQL_USER} password)"
ssh -t "${DEVICE_USER}@${DEVICE_IP}" "${MIGRATE_CMD}" \
  || warn "Migration step failed — check MySQL credentials and db/schema.sql syntax."
ok "Database schema applied"

# ─────────────────────────────────────────────────────────────────────────────
# STEP 6 — Restart Apache
# ─────────────────────────────────────────────────────────────────────────────
step 6 "Restart Apache on device"
# Choose the restart command appropriate for the EduPak OS:
#
#   XAMPP:         sudo /opt/lampp/lampp restartapache
#   System Apache: sudo systemctl restart apache2
#   Manual XAMPP:  sudo /opt/lampp/bin/apachectl graceful

RESTART_CMD="sudo systemctl restart ${APACHE_SERVICE} 2>/dev/null || \
  sudo /opt/lampp/lampp restartapache 2>/dev/null || \
  { echo 'Could not restart Apache — restart manually'; exit 1; }"

log "Restarting Apache (${APACHE_SERVICE})..."
ssh "${DEVICE_USER}@${DEVICE_IP}" "${RESTART_CMD}" \
  || warn "Apache restart failed. SSH to device and restart manually."
ok "Apache restarted"

# ─────────────────────────────────────────────────────────────────────────────
# STEP 7 — Verify deployment
# ─────────────────────────────────────────────────────────────────────────────
step 7 "Smoke test"
log "Checking HTTP response from device..."

sleep 3  # allow Apache to fully restart

HTTP_STATUS="$(curl -s -o /dev/null -w "%{http_code}" "http://${DEVICE_IP}/" 2>/dev/null || echo "000")"

if [ "${HTTP_STATUS}" = "200" ] || [ "${HTTP_STATUS}" = "302" ]; then
  ok "Device returned HTTP ${HTTP_STATUS} — deployment successful!"
else
  warn "Device returned HTTP ${HTTP_STATUS}. Check Apache error logs:"
  warn "  ssh ${DEVICE_USER}@${DEVICE_IP} 'sudo tail -50 /var/log/apache2/error.log'"
fi

# ─────────────────────────────────────────────────────────────────────────────
# STEP 8 — Cleanup temp files on device
# ─────────────────────────────────────────────────────────────────────────────
step 8 "Clean up temp files on device"
ssh "${DEVICE_USER}@${DEVICE_IP}" "rm -f /tmp/${ARCHIVE_NAME} && rm -rf /tmp/htdocs /tmp/db /tmp/config /tmp/BUILD_INFO" \
  || warn "Cleanup failed — remove /tmp/${ARCHIVE_NAME} manually from device"
ok "Temp files removed from device"

# ── Done ──────────────────────────────────────────────────────────────────────
echo ""
echo -e "${GREEN}════════════════════════════════════════════════════════${NC}"
echo -e "${GREEN}  Deployment complete!${NC}"
echo -e "${GREEN}  EduPak:  http://${DEVICE_IP}/${NC}"
echo -e "${GREEN}  Archive: ${ARCHIVE_NAME}${NC}"
echo -e "${GREEN}════════════════════════════════════════════════════════${NC}"
echo ""
echo "Rollback (if needed):"
echo "  ssh ${DEVICE_USER}@${DEVICE_IP}"
echo "  sudo rsync -av --delete ${DEVICE_BACKUP_DIR}/htdocs-TIMESTAMP/ ${DEVICE_DOCROOT}/"
echo "  sudo systemctl restart ${APACHE_SERVICE}"
