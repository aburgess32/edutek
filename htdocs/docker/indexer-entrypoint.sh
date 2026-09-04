#!/bin/sh
set -eu

STATUS_DIR="${INDEX_STATUS_DIR:-/system/index-status}"
DELAY_SECONDS="${INDEX_DELAY_SECONDS:-300}"

mkdir -p "$STATUS_DIR"

echo "EduPak indexer container started."
echo "Waiting ${DELAY_SECONDS} seconds before content assessment."

sleep "$DELAY_SECONDS"

echo "Starting indexer."
php /var/www/html/api/index-content-cli.php

echo "Indexer container finished."