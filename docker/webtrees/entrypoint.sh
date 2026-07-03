#!/bin/sh
set -eu

mkdir -p /var/www/html/data /var/www/html/media /var/www/html/config
chown -R www-data:www-data /var/www/html/data /var/www/html/media /var/www/html/config

if [ -f /var/www/html/data/config.ini.php ] && command -v mysql >/dev/null 2>&1; then
	table_prefix="$(php -r '
$config = parse_ini_file("/var/www/html/data/config.ini.php") ?: [];
echo $config["tblpfx"] ?? "";
')"

	db_host="${WEBTREES_DB_HOST:-}"
	db_user="${WEBTREES_DB_USER:-}"
	db_password="${WEBTREES_DB_PASSWORD:-}"
	db_name="${WEBTREES_DB_NAME:-}"

	if [ -n "$db_host" ] && [ -n "$db_user" ] && [ -n "$db_password" ] && [ -n "$db_name" ]; then
		mysql_host="$db_host"
		mysql_port="3306"

		case "$db_host" in
			*:*)
				mysql_host="${db_host%:*}"
				mysql_port="${db_host##*:}"
				;;
		esac

		mysql_table="${table_prefix}site_setting"
		mysql_sql="INSERT INTO \`${mysql_table}\` (\`setting_name\`, \`setting_value\`) VALUES ('THEME_DIR', 'xenea') ON DUPLICATE KEY UPDATE \`setting_value\` = VALUES(\`setting_value\`);"

		attempt=0
		while [ "$attempt" -lt 30 ]; do
			if mysql --protocol=tcp --ssl-mode=DISABLED -h "$mysql_host" -P "$mysql_port" -u "$db_user" -p"$db_password" "$db_name" -e "$mysql_sql" >/dev/null 2>&1; then
				break
			fi

			attempt=$((attempt + 1))
			sleep 2
		done
	fi
fi

exec "$@"
