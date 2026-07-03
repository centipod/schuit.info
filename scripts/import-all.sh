#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

"$script_dir/import-archive.sh" wordpress archive/schuit.sql.zip
"$script_dir/import-archive.sh" webtrees archive/schuit_1.sql.zip

echo "Imported both WordPress and webtrees archives."
