#!/usr/bin/env bash
set -euo pipefail
image="${SCHUIT_TEST_IMAGE:-schuit-ci:local}"
python3 -m unittest discover -s tests/python -v
python3 tests/ci/restore-network.py
for test in gedcom-normalize gedcom-workflow gedcom-supersession gedcom-consistency gedcom-scale gedcom-report login-throttle runtime-guard cognito privacy; do
  php_args=()
  # This isolated queue test starts competing PHP processes; production keeps
  # process execution disabled. The container has no network or writable mounts.
  if [[ "$test" == gedcom-supersession ]]; then php_args=(-d disable_functions=); fi
  docker run --rm --network none --entrypoint php -v "$PWD:/opt/schuit-repo:ro" -v "$PWD/tests/ci/privacy:/run/schuit-privacy:ro" "$image" "${php_args[@]}" "/opt/schuit-repo/tests/$test.php" /opt/schuit-security/login-throttle.php
done
