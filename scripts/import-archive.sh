#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 2 ]]; then
  echo "Usage: $0 {wordpress|webtrees} path/to/archive.zip" >&2
  exit 1
fi

app="$1"
archive="$2"

if [[ ! -f "$archive" ]]; then
  echo "Archive not found: $archive" >&2
  exit 1
fi

if [[ -f .env ]]; then
  set -a
  # shellcheck disable=SC1091
  source .env
  set +a
fi

case "$app" in
  wordpress)
    service="wordpress-db"
    database="${WORDPRESS_DB_NAME:-wordpress}"
    root_password="${WORDPRESS_DB_ROOT_PASSWORD:-wordpress-root-pass}"
    source_database="schuit"
    ;;
  webtrees)
    service="webtrees-db"
    database="${WEBTREES_DB_NAME:-webtrees}"
    root_password="${WEBTREES_DB_ROOT_PASSWORD:-webtrees-root-pass}"
    source_database="schuit_1"
    ;;
  *)
    echo "Unsupported app: $app" >&2
    exit 1
    ;;
esac

tmp_dir="$(mktemp -d)"
trap 'rm -rf "$tmp_dir"' EXIT

unzip -q "$archive" -d "$tmp_dir"

docker compose exec -T "$service" sh -lc "mysql --init-command=\"SET SESSION sql_mode='';\" -u root -p'$root_password' -e 'DROP DATABASE IF EXISTS \`$database\`; CREATE DATABASE \`$database\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'"

database_file="$(find "$tmp_dir" -name '*_database.sql' | sort | head -n 1)"
extra_file="$(find "$tmp_dir" -name '*_extra.sql' | sort | head -n 1)"
table_files="$(find "$tmp_dir" -name '*_table_*.sql' | sort)"

if [[ -z "$database_file" && -z "$table_files" && -z "$extra_file" ]]; then
  echo "No SQL files found in $archive" >&2
  exit 1
fi

import_file() {
  local sql_file="$1"
  echo "Importing $(basename "$sql_file") into $database"
  sed "s/\`${source_database}\`/\`${database}\`/g" "$sql_file" \
    | docker compose exec -T "$service" sh -lc "mysql --init-command=\"SET SESSION sql_mode='';\" -u root -p'$root_password' '$database'"
}

if [[ -n "$database_file" ]]; then
  import_file "$database_file"
fi

if [[ -n "$table_files" ]]; then
  while IFS= read -r sql_file; do
    [[ -n "$sql_file" ]] || continue
    import_file "$sql_file"
  done <<< "$table_files"
fi

if [[ -n "$extra_file" ]]; then
  import_file "$extra_file"
fi

echo "Done: $app imported from $archive"
