# SCHUIT tests and release policy

Every push and pull request builds a fresh Linux image from pinned PHP/Composer
images, checksum-verified WordPress/webtrees releases, and the committed Composer
lockfile. All jobs use the same candidate image artifact. Production data and
credentials are never copied into CI.

## Required checks

* **Unit tests:** GEDCOM encodings/normalisation, malformed input and workflow
  validation, login throttling, unsafe upload/XML protections, and fail-closed
  vulnerability/deployment policies.
* **Browser E2E:** disposable MariaDB, WordPress and webtrees behind local TLS;
  unauthorised access and living-person privacy, login/logout, role restrictions,
  unsafe and invalid file rejection, review/cancellation, job ownership, explicit
  confirmation, real GEDCOM import, and database-derived website/tree statistics.
  Integration checks also exercise transactional rollback and tamper rejection.
* **CVE scan:** Trivy scans image OS packages and installed libraries, plus
  Composer and npm lockfiles including test dependencies. Every MEDIUM, HIGH or
  CRITICAL finding blocks deployment, including findings with no fix available.
  UNKNOWN severity, malformed reports, missing scan coverage and scanner failure
  also block. There are no vulnerability exclusions or ignore-unfixed options.
* **Release gate:** requires every preceding job to finish successfully. Skipped,
  cancelled, failed or missing checks are not accepted.

Scanner results depend on upstream advisory/package coverage; this does not
replace application security review or ongoing WordPress/PHP/webtrees release
monitoring. A clean scan is not a guarantee that software has no vulnerabilities.

## Deployments

Only a successful main-branch push/manual workflow may deploy to the
`schuit-review` GitHub environment. Branch protection requires unit and browser
tests for merges. CVEs block **deployment**, not remediation commits: the fixes
and CI policy must remain mergeable while other vulnerabilities are unresolved.

The `schuit-deploy` SSH account has a forced command, no forwarding or interactive
shell access through its key, no Docker group membership, and only one sudo
command. Its private key and verified host key are GitHub environment secrets.
The workflow sends its short-lived read-only Actions token to the receiver; no
long-lived GitHub credential is stored on Hetzner.

The root-owned receiver independently checks the repository, workflow, current
main commit and completed job outcomes. It downloads the immutable candidate
artifact directly from that run, verifies GitHub's artifact digest and image
revision/run labels, and repeats the CVE scan before changing any running service.
Application code comes from the tested image; only data, language files and
restricted configuration are mounted from the server.

Activation requires a recent verified S3 backup (triggered if stale), no active
GEDCOM work, the existing import lock, healthy container/HTTPS checks and unchanged
user/genealogy/security-setting inventories. Failure restores the previous release.
No database schema migration is performed by this release path; future schema
changes need a separately designed backup/migration/rollback procedure.

Legacy deployment scripts now exit with an error. Do not run historical versions
or manually replace containers to bypass a blocked release. Root administrators
retain host control; this is an enforced deployment path, not a restriction on root.

## Local verification

```sh
docker build -f docker/release.Dockerfile \
  --build-arg SOURCE_REVISION="$(git rev-parse HEAD)" \
  --build-arg CI_RUN_ID=local -t schuit-ci:local .
bash ci/unit.sh
npm ci --ignore-scripts
PLAYWRIGHT_BROWSERS_PATH="$PWD/.ci/browsers" npx playwright install chromium
bash ci/browser.sh
bash ci/scan.sh
```

The fixture listens only on loopback port 19443 and is destroyed after the browser
run. All people are fictional. Generated credentials, database dumps, traces and
reports are ignored by Git. GitHub retains candidate images for 7 days, browser
failure diagnostics for 14 days and vulnerability reports for 30 days.

## Receiver provisioning

Publish the intended source revision first, then install `ci/install-receiver.sh`
from that verified revision as root with a dedicated Ed25519 public key file.
Set environment secrets `SCHUIT_DEPLOY_KEY` and `SCHUIT_KNOWN_HOSTS` in
`schuit-review`, restricted to main. Installing the receiver does not restart or
replace the application. The app remains at its previous revision when the CVE
gate blocks a candidate. Keep infrastructure deployment evidence in
`centipod/hetzner-server`.
