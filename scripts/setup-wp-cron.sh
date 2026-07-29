#!/usr/bin/env bash
set -euo pipefail

# WordPress Cron Setup
# This script sets up a system cron job to trigger WordPress cron
# since DISABLE_WP_CRON is set to true in the WordPress configuration.

CRON_SCRIPT="/etc/cron.d/wordpress-cron"

cat > "$CRON_SCRIPT" <<'EOF'
# WordPress cron - triggers WP-CRON.php every 5 minutes
# This replaces the disabled WP-CRON in docker-compose.yml
*/5 * * * * www-data wget -q -O - http://localhost/wp-cron.php?doing_wp_cron >/dev/null 2>&1
EOF

chmod 0644 "$CRON_SCRIPT"

echo "WordPress cron configured to run every 5 minutes"
echo "Cron script: $CRON_SCRIPT"
