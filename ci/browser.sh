#!/usr/bin/env bash
set -euo pipefail
export PLAYWRIGHT_BROWSERS_PATH="$PWD/.ci/browsers"
compose=(docker compose --env-file .ci/env -f tests/ci/compose.yaml -p schuit-ci)
cleanup() {
  "${compose[@]}" logs --no-color > .ci/container.log 2>&1 || true
  "${compose[@]}" down -v --remove-orphans
}
python3 tests/ci/setup.py
trap cleanup EXIT
"${compose[@]}" up -d --wait --wait-timeout 180
"${compose[@]}" exec -T web php /opt/schuit-repo/tests/ci/init.php
"${compose[@]}" exec -T web php /opt/schuit-repo/tests/ci/init-tree.php
"${compose[@]}" exec -T web php /opt/schuit-scripts/update-metrics.php
for test in gedcom-import-integration gedcom-transaction webtrees-privacy; do
  "${compose[@]}" exec -T web php "/opt/schuit-repo/tests/$test.php"
done
npm run test:e2e
