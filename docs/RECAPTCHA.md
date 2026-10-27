# SCHUIT sign-in protection

WordPress `/wp-login.php` and webtrees `/tree/login` use Google's reCAPTCHA v2 checkbox in Dutch. The widget appears only in the sign-in forms, with Google privacy/terms links. It is separate from optional Analytics consent; no analytics tag is enabled by completing a challenge. Password reset, logout, existing sessions and public browsing retain their native behavior.

A shared PHP guard runs before either application's credential processing, after the existing rate limiter. Direct POSTs need a token too. Missing/rejected/expired challenges return 403 with a Dutch retry link; unavailable/malformed verification returns 503. There is no fail-open mode, testing bypass, administrator exception or client-side-only verification. Tokens are verified once by Google and expire after two minutes. No passwords, usernames or optional client IP are sent by our server to Google; Google's widget itself contacts Google from the browser.

## Configuration and deployment

The dedicated `SCHUIT login` key belongs to the Centipod IO Google Cloud project. Google domain settings cover `schuit.info` (including `www`) and `schuit.centipod.ai`. Application verification allows exactly those three hosts and requires Google's returned hostname to match the requesting host. Keep the preview entry until cutover is accepted.

Private runtime files:

- `/etc/centipod/schuit/recaptcha-public.json`: `site_key`, `hosts`; mounted read-only into the web container.
- `/etc/centipod/schuit/recaptcha.json`: same plus `secret_key`; mounted only into the verifier.

Provision them using the published `scripts/install-recaptcha-config.py` as root, with private JSON on stdin. Both files are root-owned, group 33, mode 0640; never put values in Git, logs or command arguments. Escrow the configuration in AWS Secrets Manager. The existing encrypted SCHUIT backup includes `/etc/centipod/schuit`.

`deployment/release-compose.yaml` adds an unprivileged verifier using the **same tested/scanned immutable image**, limited to 64 MiB and 0.25 CPU. It publishes no host port. The web application retains its private networks; only the verifier joins a separate egress network. The verifier accepts a bounded token and host at one fixed endpoint and calls only Google's fixed HTTPS SiteVerify URL, with certificate checking, no redirects/retries, bounded response size and connection/request deadlines. It is not a general forward proxy. It never logs request bodies or secret values.

The web container's healthcheck now includes verifier configuration/readiness, so the existing SCHUIT status entries also detect verifier failures. This readiness probe does not claim to validate Google's availability or a real user's challenge; successful end-to-end sign-in must be checked after deployment.

Deploy **only** through the existing build/unit/E2E/CVE/release gates. Provision configuration before deployment so missing keys cannot lock users out on an otherwise eligible rollout. The legacy `deployment/compose.yaml` remains historical; do not use it to deploy this change. Roll back the whole previously verified application/Compose revision if needed; do not disable verification on the new release. Existing sessions are unaffected.

## Validation

- `php tests/recaptcha.php`: token bounds/types, host allowlist, strict Google success, timestamps, quota/error/replay responses, transport errors, route coverage and config bounds.
- CI E2E: actual WordPress/webtrees pages and pre-auth guard, missing/rejected challenge, verifier outage, and existing successful/unsuccessful credential flows. Google UI and verification are mocked only in the isolated CI Compose fixture; test-only files are not copied into the production image.
- Real Google challenge and a legitimate sign-in on each form are still required after an eligible deployment. Automated mocks cannot establish that the live Google key/challenge works.

Google references: [widget integration](https://developers.google.com/recaptcha/docs/display), [server verification and single-use expiry](https://developers.google.com/recaptcha/docs/verify), [domain validation](https://developers.google.com/recaptcha/docs/domain_validation).
