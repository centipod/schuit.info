#!/usr/bin/env bash
set -euo pipefail
python3 -m ruff check .
# Analyse against the actual runtime's webtrees and JWT classes, not missing-symbol guesses.
docker run --rm --platform linux/amd64 --network none --entrypoint php \
  -v "$PWD:/src:ro" -w /src "${SCHUIT_TEST_IMAGE:-schuit-ci:local}" \
  ci/tools/vendor/phpstan/phpstan/phpstan.phar analyse -c phpstan.neon --no-progress --memory-limit=2G
