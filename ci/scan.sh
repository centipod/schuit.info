#!/usr/bin/env bash
set -euo pipefail
policy_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
image="${SCHUIT_TEST_IMAGE:-schuit-ci:local}"
revision="${SOURCE_REVISION:-$(git rev-parse HEAD)}"
scanner='aquasec/trivy:0.72.0@sha256:cffe3f5161a47a6823fbd23d985795b3ed72a4c806da4c4df16266c02accdd6f'
mkdir -p .ci/scans .ci/trivy-cache
# Force full findings and empty ignore configuration. Never use --ignore-unfixed.
common=(--config /dev/null --scanners vuln --ignorefile /dev/null --ignore-unfixed=false --format json --timeout 15m)
docker run --rm -v /var/run/docker.sock:/var/run/docker.sock:ro -v "$PWD/.ci/scans:/reports" -v "$PWD/.ci/trivy-cache:/root/.cache/trivy" "$scanner" image "${common[@]}" --pkg-types os,library --output /reports/image.json "$image"
docker run --rm -v "$PWD:/src:ro" -v "$PWD/.ci/scans:/reports" -v "$PWD/.ci/trivy-cache:/root/.cache/trivy" "$scanner" fs "${common[@]}" --include-dev-deps --pkg-types library --skip-dirs /src/.git,/src/.ci,/src/working,/src/node_modules --output /reports/dependencies.json /src
image_id="$(docker image inspect --format '{{.Id}}' "$image")"
python3 "$policy_dir/security_policy.py" --image-report .ci/scans/image.json --dependency-report .ci/scans/dependencies.json --revision "$revision" --image-id "$image_id" --output .ci/scans/eligibility.json

# Standard software inventory for this exact image, retained with scan evidence.
docker run --rm -v /var/run/docker.sock:/var/run/docker.sock:ro -v "$PWD/.ci/scans:/reports" -v "$PWD/.ci/trivy-cache:/root/.cache/trivy" "$scanner" image --config /dev/null --scanners vuln --format cyclonedx --output /reports/sbom.cdx.json "$image"
python3 - "$revision" "$image_id" <<'PY2'
import json,sys,datetime
from pathlib import Path
Path('.ci/scans/provenance.json').write_text(json.dumps({'source':'https://github.com/centipod/schuit.info','revision':sys.argv[1],'image_id':sys.argv[2],'created_at':datetime.datetime.now(datetime.timezone.utc).isoformat(),'policy':'deny-medium-and-above-v1'},indent=2)+'\n')
PY2
