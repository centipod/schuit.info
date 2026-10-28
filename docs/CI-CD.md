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
Classic Docker reports the config digest as its image ID; the containerd image
store reports the OCI manifest digest. The receiver hashes the archive's manifest
and config, checks their linkage and matching classic/OCI layer lists, and accepts
only those two immutable identities. It never selects the image by a mutable tag.
The CI and host IDs are retained together in the release evidence.
The host scanner and severity policy are root-owned control files, independently
installed from published source; candidate application code cannot relax them.
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

## Quality remediation controls (28 October 2026)

`Static quality` runs PHPStan level 5 against WordPress stubs and the actual webtrees/JWT
runtime, and Ruff correctness checks. No PHPStan baseline or blanket suppression is used.
All release jobs and the independent host gate must succeed. The policy still blocks
MEDIUM/HIGH/CRITICAL/UNKNOWN vulnerabilities, including unfixed findings.

Build and runtime APK versions are locked, including transitives. Refresh deliberately using
`scripts/refresh-apk-locks.sh`; review the diff and pass the complete pipeline. Package
availability is still dependent on upstream repositories: a removed version fails the build.
This is version-locked input resolution, not a claim of bit-for-bit reproducible builds.

Each release has a CycloneDX SBOM and image/source provenance. Main-branch candidate
archives additionally receive GitHub build attestations. Host release and daily scan evidence
is included in encrypted S3 snapshots retained 35 days, independently of GitHub artifacts.
Daily GitHub dependency scans and weekly Dependabot PRs supplement a daily scan of the
**exact running image and deployed source**. A failed, mismatched or >36-hour-old scan
makes the named SCHUIT vulnerability status check fail and enters existing host alerting.
Christian Schuit owns triage. Scans never deploy changes automatically.

Run `bash ci/install-controls.sh` as root from the published source before the first release
using these controls. It pauses only the SCHUIT queue timer, refuses to interrupt an active
worker, installs the root-owned fixed-operation broker and an unprivileged queue worker,
then resumes the timer. Enable `schuit-security.timer` after the matching app has deployed.
The worker cannot run arbitrary privileged commands and has no Docker socket access.

Backups retain the v1 authenticated format while encryption, decryption and S3 I/O stream
in bounded chunks. Single backup objects are limited to 5 GiB and fail explicitly above that;
implement multipart upload before approaching that limit. Restore authenticates the full
archive before extraction, verifies files and SQL, and boots recovered WordPress/webtrees
and the identity broker on an internal Docker network without public ports. Live Cognito
CAPTCHA, email delivery and MFA acceptance remain separate authorised-user tests.
