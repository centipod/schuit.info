#!/bin/bash
# backup-to-s3.sh
#
# Nightly backup of both MySQL/MariaDB databases (webtrees, wordpress) and the
# durable file state (webtrees media, WordPress uploads) to the S3 backup
# bucket provisioned by infra/terraform/modules/backup-bucket.
#
# Runs ON THE PRODUCTION SERVER (not in Terraform user_data - see the comment
# on aws_lightsail_instance.this in infra/terraform/modules/lightsail-web/main.tf
# for why bootstrap-time provisioning and ongoing backups must stay separate).
#
# Installation on the server (one-time):
#   sudo install -m 0750 -o root -g root backup-to-s3.sh /opt/genealogy/backup/backup-to-s3.sh
#   sudo install -m 0600 -o root -g root /path/to/backup.env /etc/genealogy-backup.env
#   sudo crontab -l 2>/dev/null | { cat; echo "15 2 * * * /opt/genealogy/backup/backup-to-s3.sh >> /var/log/genealogy-backup.log 2>&1"; } | sudo crontab -
#
# Must run as root: relies on unix-socket auth for mysqldump (root@localhost
# with mysql_native_password, no password needed when invoked via sudo/root -
# see /etc/mysql/debian.cnf) and needs read access to www-data-owned files
# under /var/www/shared/.
#
# /etc/genealogy-backup.env must define:
#   AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, AWS_DEFAULT_REGION, BACKUP_BUCKET

set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
  echo "This script must run as root (mysqldump uses unix-socket auth as root@localhost)." >&2
  exit 1
fi

ENV_FILE="${GENEALOGY_BACKUP_ENV:-/etc/genealogy-backup.env}"
if [[ -f "$ENV_FILE" ]]; then
  # shellcheck disable=SC1090
  source "$ENV_FILE"
fi

: "${BACKUP_BUCKET:?BACKUP_BUCKET must be set (env file or environment)}"
: "${AWS_ACCESS_KEY_ID:?AWS_ACCESS_KEY_ID must be set (env file or environment)}"
: "${AWS_SECRET_ACCESS_KEY:?AWS_SECRET_ACCESS_KEY must be set (env file or environment)}"
export AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY
export AWS_DEFAULT_REGION="${AWS_DEFAULT_REGION:-eu-west-1}"

TIMESTAMP="$(date -u +%Y%m%d-%H%M%S)"
WORKDIR="$(mktemp -d)"
trap 'rm -rf "$WORKDIR"' EXIT

echo "[$(date -u +%FT%TZ)] Starting backup run ${TIMESTAMP}"

# ------------------------------------------------------------------
# 1. Database dumps
# ------------------------------------------------------------------
for db in webtrees wordpress; do
  dump_file="${WORKDIR}/${db}-${TIMESTAMP}.sql.gz"
  echo "Dumping database: ${db}"
  mysqldump \
    --single-transaction \
    --quick \
    --routines \
    --triggers \
    --default-character-set=utf8mb4 \
    "${db}" | gzip -9 > "${dump_file}"

  aws s3 cp "${dump_file}" "s3://${BACKUP_BUCKET}/databases/${db}/${db}-${TIMESTAMP}.sql.gz" \
    --only-show-errors
done

# ------------------------------------------------------------------
# 2. Durable file state (media, uploads) - incremental sync, not dated dumps
# ------------------------------------------------------------------
if [[ -d /var/www/shared/webtrees/media ]]; then
  echo "Syncing webtrees media"
  aws s3 sync /var/www/shared/webtrees/media "s3://${BACKUP_BUCKET}/files/webtrees-media" \
    --only-show-errors --delete
fi

if [[ -d /var/www/shared/webtrees/data ]]; then
  echo "Syncing webtrees data (config.ini.php, gedcom files)"
  aws s3 sync /var/www/shared/webtrees/data "s3://${BACKUP_BUCKET}/files/webtrees-data" \
    --only-show-errors --delete --exclude "cache/*" --exclude "*.log"
fi

if [[ -d /var/www/shared/wordpress/wp-content/uploads ]]; then
  echo "Syncing WordPress uploads"
  aws s3 sync /var/www/shared/wordpress/wp-content/uploads "s3://${BACKUP_BUCKET}/files/wordpress-uploads" \
    --only-show-errors --delete
fi

echo "[$(date -u +%FT%TZ)] Backup run ${TIMESTAMP} complete"
