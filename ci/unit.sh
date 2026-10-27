#!/usr/bin/env bash
set -euo pipefail
image="${SCHUIT_TEST_IMAGE:-schuit-ci:local}"
python3 -m unittest discover -s tests/python -v
for test in gedcom-normalize gedcom-workflow login-throttle runtime-guard analytics; do
  docker run --rm --network none --entrypoint php -v "$PWD:/opt/schuit-repo:ro" "$image" "/opt/schuit-repo/tests/$test.php" /opt/schuit-security/login-throttle.php
done
