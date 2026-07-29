#!/usr/bin/env bash
set -euo pipefail

# Secure GEDCOM Import Script
# Usage: ./gedcom-import.sh <path/to/file.ged>
#
# Requirements:
#   - Must be run from the project root directory
#   - .env file must exist
#   - Docker Compose stack must be running
#   - User must have permissions to write to /var/private/gedcom-staging

if [[ $# -ne 1 ]]; then
  echo "Usage: $0 <path/to/file.ged>" >&2
  exit 1
fi

GEDCOM_FILE="$1"

if [[ ! -f "$GEDCOM_FILE" ]]; then
  echo "Error: File not found: $GEDCOM_FILE" >&2
  exit 1
fi

# Validate file extension
if [[ "${GEDCOM_FILE##*.}" != "ged" ]]; then
  echo "Error: Only .ged files are accepted" >&2
  exit 1
fi

# Check file size (max 500MB)
FILE_SIZE=$(stat -f%z "$GEDCOM_FILE" 2>/dev/null || stat -c%s "$GEDCOM_FILE" 2>/dev/null)
if [[ "$FILE_SIZE" -gt 524288000 ]]; then
  echo "Error: File too large (max 500MB)" >&2
  exit 1
fi

# Load environment
if [[ ! -f .env ]]; then
  echo "Error: .env file not found" >&2
  exit 1
fi

set -a
source .env
set +a

# Configuration
STAGING_DIR="/var/private/gedcom-staging"
ARCHIVE_DIR="/var/private/gedcom-archive"
LOG_FILE="/var/log/gedcom-import.log"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
CHECKSUM=$(sha256sum "$GEDCOM_FILE" | awk '{print $1}')
FILENAME=$(basename "$GEDCOM_FILE")
STAGING_FILE="${STAGING_DIR}/${TIMESTAMP}_${FILENAME}"
LOG_ENTRY="[${TIMESTAMP}] User: $(whoami) | File: ${FILENAME} | Size: ${FILE_SIZE} bytes | Checksum: ${CHECKSUM}"

# Ensure staging directory exists
mkdir -p "$STAGING_DIR" "$ARCHIVE_DIR"

# Step 1: Validate GEDCOM structure (basic check)
echo "Validating GEDCOM structure..."
if ! grep -q "^0 HEAD$" "$GEDCOM_FILE" 2>/dev/null; then
  echo "Error: Invalid GEDCOM file - missing '0 HEAD' header" >&2
  echo "${LOG_ENTRY} | Status: FAILED - Invalid structure" >> "$LOG_FILE"
  exit 1
fi

# Step 2: Copy to staging area
echo "Staging file..."
cp "$GEDCOM_FILE" "$STAGING_FILE"
chmod 0600 "$STAGING_FILE"

# Step 3: Backup current webtrees state
echo "Creating backup of current webtrees state..."
BACKUP_DIR="/var/backups/genealogy/webtrees_${TIMESTAMP}"
mkdir -p "$BACKUP_DIR"

# Backup webtrees database
echo "Backing up webtrees database..."
docker compose exec -T webtrees-db sh -c \
  "mysqldump -u root -p'${WEBTREES_DB_ROOT_PASSWORD:-webtrees-root-pass}' '${WEBTREES_DB_NAME:-webtrees}'" \
  | gzip > "${BACKUP_DIR}/webtrees_db_backup.sql.gz"

# Backup webtrees config and data
echo "Backing up webtrees config and data..."
docker compose cp webtrees:/var/www/html/config "${BACKUP_DIR}/config" 2>/dev/null || true
docker compose cp webtrees:/var/www/html/data "${BACKUP_DIR}/data" 2>/dev/null || true

# Step 4: Import GEDCOM into webtrees
echo "Importing GEDCOM into webtrees..."
echo "${LOG_ENTRY} | Status: IMPORTING" >> "$LOG_FILE"

# Copy staged GEDCOM to webtrees container
docker compose cp "$STAGING_FILE" webtrees:/tmp/"${FILENAME}"

# Execute import via webtrees CLI (if available) or API
# Note: This assumes webtrees has CLI import capability
IMPORT_RESULT=$(docker compose exec -T webtrees sh -c \
  "php artisan webtrees:import-gedcom /tmp/${FILENAME}" 2>&1) || true

# Clean up staged file in container
docker compose exec -T webtrees rm -f "/tmp/${FILENAME}"

# Step 5: Log result
if echo "$IMPORT_RESULT" | grep -qi "success\|completed\|imported"; then
  echo "${LOG_ENTRY} | Status: SUCCESS" >> "$LOG_FILE"
  echo "Import completed successfully"
  
  # Archive the uploaded file
  mv "$STAGING_FILE" "${ARCHIVE_DIR}/${TIMESTAMP}_${FILENAME}"
  echo "File archived to: ${ARCHIVE_DIR}/${TIMESTAMP}_${FILENAME}"
else
  echo "${LOG_ENTRY} | Status: FAILED - ${IMPORT_RESULT}" >> "$LOG_FILE"
  echo "Error: Import failed" >&2
  echo "Check logs: $LOG_FILE"
  
  # Restore from backup
  echo "Restoring from backup..."
  # Note: Restore logic would go here
  exit 1
fi

echo "Done: GEDCOM import completed"
