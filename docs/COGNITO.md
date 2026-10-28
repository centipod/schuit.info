# SCHUIT AWS sign-in

Status: AWS deployment requested; runtime remediation and release checks in progress. Replaces the unmerged Google reCAPTCHA proposal. The mandatory MEDIUM+ CVE gate remains unchanged. Do not deploy until the exact candidate's build, unit, browser and vulnerability checks all pass.

## Architecture

An isolated SCHUIT Cognito Essentials pool in eu-west-1 provides managed login, required authenticator MFA, password recovery and Dutch HTML notification templates. AWS WAF CAPTCHA protects interactive sign-in/recovery and direct authentication APIs; token exchange and TOTP setup operations are excluded. Self-registration and app-client password/SRP authentication are disabled. The initial callback allowlist contains only the SCHUIT preview, not the old public site. Infrastructure is in the private `centipod/hetzner-server` repository under `apps/schuit/cognito-pool.json` and the canonical `infra/hetzner-data/schuit-auth.tf` root.

WordPress and webtrees continue to own their accounts and permissions. A restricted configuration maps the immutable Cognito subject to explicit local numeric IDs and expected email. No login-time account creation, email-only linking, role assignment or role escalation is supported. Local account deletion, email changes or missing webtrees approval block login. Use the same Cognito subject for both applications only when the approved mapping establishes that they belong to the same person.

Authorization code + S256 PKCE, single-use server-side state, a browser-bound Secure/HttpOnly/SameSite=Lax cookie and nonce protect callbacks. The internal identity broker verifies RS256 with Cognito JWKS and checks issuer, audience, token use, nonce, time and verified email. It has outbound access; the web container keeps its private networks. There are no public broker ports. Tokens are not stored in either application or returned to the browser. Code/state and referrers are omitted from Apache access logs; also verify the shared reverse proxy's logging policy before activation.

Local sessions expire after one hour; native logout clears the current application's session. Interactive authentication is forced on every new login, so a lingering Cognito session cannot silently undo local logout. This is not global logout: signing out of WordPress does not terminate a webtrees session, and disabling an identity in Cognito does not instantly terminate an already-issued local session. Revoke local sessions as well for emergency access removal.

## Prepare users and cutover

1. Pass all existing release checks first. Publish application and infrastructure commits before running them. Apply only the reviewed SCHUIT infrastructure plan from the canonical Terraform root; do not roll out unrelated drift. Pool deletion protection/retention are enabled.
2. Run `php /opt/schuit-scripts/export-auth-accounts.php` inside the deployed web container. Until the new image is deployed, run the reviewed script via stdin. Save its output privately, never in Git or public CI artifacts. Reconcile duplicate/invalid emails and unapproved webtrees accounts. Check Jan's GEDCOM manager role and the administrators explicitly.
3. Run `scripts/prepare-cognito-users.py` against the reviewed private inventory and ready stack. It creates approved identities with `MessageAction=SUPPRESS` and generates an undisclosed unique random password only for new identities. Users choose “Forgot your password?” on first sign-in, verify the code delivered to their existing email address, choose their password and enroll TOTP. Existing Cognito passwords are never reset on rerun; native hashes are never transferred. No unsolicited invitation is sent. The script writes a private configuration with `enabled:false`. Install it with `scripts/install-cognito-config.py` as root via stdin.
4. After retrieving each new subject, install `/etc/centipod/schuit/cognito.json`, root:www-data, mode 0640. Structure:

```json
{"enabled":false,"origin":"https://schuit.centipod.ai","domain":"https://<stack-domain>.auth.eu-west-1.amazoncognito.com","issuer":"https://cognito-idp.eu-west-1.amazonaws.com/<pool-id>","client_id":"<client-id>","users":{"<immutable-sub>":{"email":"<approved-email>","wordpress":123,"webtrees":456}}}
```

Only include application IDs that the person already owns. Config contains personal data and must stay out of Git, CI and support logs. Escrow it in Secrets Manager and include it in the existing encrypted `/etc/centipod/schuit` backup. Missing/bad enabled configuration fails closed; the deployed Compose mount is required. The explicit `enabled:false` setting supports staged installation and controlled rollback.
5. Deploy the eligible immutable image with the configuration disabled. Verify the private broker health and unchanged public access. Confirm the existing status.centipod.io SCHUIT database/container health probe reaches `scripts/health.php`, which now includes broker readiness. No separate public broker probe is exposed.
6. Validate the AWS WAF association and challenge, first-login password change, TOTP enrollment, recovery email styling and both application callbacks with a controlled test identity. AWS CAPTCHA must be solved by a person. Then enable the mapping for the reviewed users, invalidate pre-cutover WordPress sessions (`WP_Session_Tokens::destroy_all()` for each user) and webtrees sessions, and verify Jan's upload rights plus ordinary-user denial. Keep a root-access recovery path during the change.
7. Confirm monitoring and backup restoration, then retire the unused Google reCAPTCHA registration/secret and configs. Leave DNS and the old public website unchanged.

Rollback: set `enabled:false` in the protected runtime config and invalidate sessions issued during the failed rollout. Existing native accounts/password hashes/roles were not changed by the adapters. Investigate before re-enabling; never add a request parameter or public bypass.

## Tests and known limitations

`bash ci/unit.sh` covers token signature/algorithm/issuer/audience/time/nonce, verified email, immutable identity mapping and state replay. `bash ci/browser.sh` tests the real WordPress/webtrees sessions and permissions with a synthetic identity broker and intercepted provider navigation; it also runs the complete GEDCOM import/rollback/privacy suite. The mock is not copied into the release image. These tests do not claim that the real AWS CAPTCHA, SES delivery or authenticator enrollment has been exercised.

The local test image overlays the changes onto an existing local base; GitHub CI builds, scans and tests the complete immutable candidate. The current base-image vulnerability backlog can block that candidate even when authentication tests pass.

Official references: [Cognito WAF](https://docs.aws.amazon.com/cognito/latest/developerguide/user-pool-waf.html), [TOTP exclusions](https://docs.aws.amazon.com/cognito/latest/developerguide/user-pool-settings-mfa-totp.html), [authorization code and PKCE](https://docs.aws.amazon.com/cognito/latest/developerguide/authorization-endpoint.html), [Dutch managed login localization](https://docs.aws.amazon.com/cognito/latest/developerguide/cognito-user-pools-managed-login.html#managed-login-localization).

## Runtime remediation for deployment

The Debian 13 candidate had 183 blocking OS-package findings with no fixed package versions in its release. The replacement uses digest-pinned official PHP 8.3.36 FPM on Alpine 3.24, updates repository packages at build time and runs minimal Apache + FPM as uid 33 under tini. Application versions, native permissions, isolation and MEDIUM+ gate are unchanged. Both daemons are supervised; the health check verifies Apache, FPM, both databases and the private Cognito broker. Local complete-image scan returned zero blocking findings; the exact amd64 GitHub candidate must independently pass the same gate before deployment.

## Public-domain cutover — 28 October 2026

The owner approved schuit.info as the canonical origin, with www.schuit.info
redirecting to it. The preview redirects to the public site after activation.
Managed login moves to login.schuit.info using the existing pool and accounts.
Both preview and public callbacks remain allowed for rollback. The runtime
installer accepts only the two explicitly approved origins; arbitrary origins
remain rejected. Infrastructure and the reversible runtime cutover procedure
are maintained in centipod/hetzner-server.
