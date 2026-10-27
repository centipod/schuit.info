# SCHUIT review environment: security and Webtrees integration

Scope: `https://schuit.centipod.ai`, its WordPress and Webtrees applications,
SCHUIT container/configuration, private import queue, dependency integrity,
database isolation, HTTPS controls and encrypted backup/restore. No DNS changes
or changes to other sites. Non-destructive application review, not a certification
or a claim that every vulnerability has been excluded.

## Changes prepared

- WordPress 7.0.7 → 7.1.3. Official distribution SHA-256 and every WordPress.org
  core checksum verified before deployment. The release fixes seven security
  issues: https://wordpress.org/news/2026/10/wordpress-7-1-3-maintenance-and-security-release/.
- Webtrees remains upstream 2.2.6. Its bundled dependency versions have known
  advisories despite being the current Webtrees release. A scoped Composer overlay
  updates nine affected packages plus Guzzle's required promises dependency.
  `deployment/webtrees-dependencies/composer.lock` pins the resolved versions.
  Both OSV and Composer were consulted; Composer additionally identified
  CVE-2026-109449 in Symfony ExpressionLanguage. Final Composer audit: no advisories.
- Explicitly prohibit PHP/PHTML/PHAR execution in WordPress uploads, including
  mixed-case and double extensions. Disable indexing and installation endpoints;
  preserve authenticated administration, GEDCOM uploads and legitimate assets.
- Add HSTS (this host only), SAMEORIGIN/frame-ancestors, MIME protection,
  object/base restrictions and camera/microphone/geolocation restrictions in
  SCHUIT's Apache configuration. No changes to the shared reverse proxy.
- Remove unused Akismet and the two oldest inactive bundled WordPress themes
  from the new runtime, retaining rollback/S3 copies. Keep the new distribution's
  fallback theme and all SCHUIT content, accounts and uploads.
- Shared logo, website navigation, system fonts, cream/brown/teal palette and
  wrapping Webtrees menus. Native chart CSS, CSRF tokens, menus, privacy rendering
  and script stacks remain present. No external Google Fonts request.
- A non-identifying, Secure/HttpOnly/SameSite=Strict session cookie scoped to
  `/tree/` is set while viewing WordPress. Webtrees' native first-visit guard
  accepts it, avoiding the visible cookie-check reload when navigating from the
  portal. This does not sign a visitor in. The native guard is unmodified;
  direct cold visits or browsers refusing cookies can still show its challenge.

## Existing controls reviewed

Non-root UID 33; read-only application code/root filesystem; all Linux
capabilities dropped; no-new-privileges; CPU/RAM/PID limits; no published app or
MariaDB ports. Separate WordPress/Webtrees database users are restricted to
`wp_schuit`/`wt_schuit`. Secrets and private GEDCOM jobs are outside the public
root. Webtrees data/vendor/config/source/media routes and XML-RPC are blocked.
WordPress registration, online code editing and online updates are disabled.
Preview mail and WordPress outbound HTTP are suppressed. PHP 8.3.36 has secure
session cookies, no displayed errors, no PHP version disclosure and no URL
includes. Webtrees keeps living-person privacy rules and account approval.

GEDCOM actions require a dedicated capability, CSRF nonce and job ownership;
confirmation is separate from upload. File sizes/queue capacity are bounded,
IDs are validated, originals are private, processing uses fixed commands and
transactional import plus a verified S3 backup. Real prepared job
`201139c346ebefb83190c37ce6857e5f` must stay ready, not be imported by this review.

## Limits and follow-up

- No MFA is enforced for the existing native WordPress/Webtrees accounts.
  Administrative accounts should use unique strong passwords; MFA or restricted
  administrative access remains a follow-up decision before public cutover.
- No dedicated edge login throttling is configured. Webtrees has native contact,
  registration and password-reset throttles, but its login handler does not add
  a login-attempt throttle. The shared proxy was intentionally left untouched.
- The CSP is a compatible protective subset, not a strict script-src policy:
  Webtrees and WordPress still use inline scripts. A strict policy needs nonce
  integration and testing, rather than a blanket header that breaks genealogy.
- Preview noindex is not access control. Historical public genealogy remains
  public under the existing privacy rules; living-person restrictions must be
  verified separately from whether pages are indexed.
- In-app updates are disabled by design (immutable deployments). This dependency
  overlay must be reviewed alongside every future upstream Webtrees release.
- Domain cutover, email delivery acceptance, account recovery and missing source
  photographs are outside this change. No existing account credentials are reset.

## Deployment and verification

`scripts/upgrade-security.py` requires a fresh successful encrypted S3 backup,
checks every artifact hash, builds and validates the SCHUIT image, takes a private
WordPress DB rollback checkpoint while app writes are stopped, replaces only
SCHUIT runtime directories and updates the WordPress database. Previous source,
files and database remain recoverable. The GEDCOM worker is paused only for the
runtime switch, and the real tree is never re-imported.

Final live acceptance results will be recorded after deployment.
