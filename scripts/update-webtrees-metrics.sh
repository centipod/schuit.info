#!/bin/bash
# update-webtrees-metrics.sh
# Updates metrics.json in webtrees data directory after GEDCOM import
# Usage: ./update-webtrees-metrics.sh [config_path] [metrics_path]

set -euo pipefail

CONFIG_PATH="${1:-/var/www/shared/webtrees/data/config.ini.php}"
METRICS_PATH="${2:-/var/www/shared/webtrees/data/metrics.json}"

if [[ ! -f "$CONFIG_PATH" ]]; then
    echo "Error: Config file not found: $CONFIG_PATH"
    exit 1
fi

# Parse config
DB_HOST=""
DB_PORT="3306"
DB_USER=""
DB_PASS=""
DB_NAME=""
TABLE_PREFIX="wt_"

while IFS=' = ' read -r key value; do
    # Remove comments and whitespace
    key=$(echo "$key" | xargs)
    value=$(echo "$value" | xargs | sed 's/^"//;s/"$//')
    
    case "$key" in
        dbhost) DB_HOST="$value" ;;
        dbport) DB_PORT="$value" ;;
        dbuser) DB_USER="$value" ;;
        dbpass) DB_PASS="$value" ;;
        dbname) DB_NAME="$value" ;;
        tblpfx) TABLE_PREFIX="$value" ;;
    esac
done < <(grep -E '^(dbhost|dbport|dbuser|dbpass|dbname|tblpfx)' "$CONFIG_PATH")

if [[ -z "$DB_HOST" || -z "$DB_USER" || -z "$DB_NAME" ]]; then
    echo "Error: Missing database configuration"
    exit 1
fi

echo "Connecting to webtrees database..."
echo "  Host: $DB_HOST"
echo "  Database: $DB_NAME"
echo "  Table prefix: $TABLE_PREFIX"

# Query metrics
INDIVIDUALS=$(mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -N -e \
    "SELECT COUNT(*) FROM \`${TABLE_PREFIX}individuals\`" 2>/dev/null)

FAMILIES=$(mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -N -e \
    "SELECT COUNT(*) FROM \`${TABLE_PREFIX}families\`" 2>/dev/null)

PLACES=$(mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -N -e \
    "SELECT COUNT(DISTINCT p_place) FROM \`${TABLE_PREFIX}places\`" 2>/dev/null)

TREES=$(mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -N -e \
    "SELECT COUNT(DISTINCT i_file) FROM \`${TABLE_PREFIX}individuals\`" 2>/dev/null)

# Validate results
if [[ -z "$INDIVIDUALS" || -z "$FAMILIES" || -z "$PLACES" || -z "$TREES" ]]; then
    echo "Error: Failed to query metrics"
    exit 1
fi

echo "Metrics:"
echo "  Individuals: $INDIVIDUALS"
echo "  Families: $FAMILIES"
echo "  Places: $PLACES"
echo "  Trees: $TREES"

# Write metrics.json
cat > "$METRICS_PATH" << EOF
{
  "individuals": $INDIVIDUALS,
  "families": $FAMILIES,
  "places": $PLACES,
  "trees": $TREES,
  "updated": "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
}
EOF

# Set correct permissions
sudo chown www-data:www-data "$METRICS_PATH"
sudo chmod 0644 "$METRICS_PATH"

echo "Metrics written to $METRICS_PATH"
echo "Updated: $(date -u +%Y-%m-%dT%H:%M:%SZ)"
