# Webtrees 2.2.6 security dependency patch

The upstream 2.2.6 release is retained. Its pinned runtime libraries include
published vulnerabilities as of 27 October 2026. This Composer manifest derives
from the upstream 2.2.6 manifest, removes development/build scripts and permits
only the explicitly pinned compatible security updates. Composer plugins and
scripts must remain disabled. The lock file is the reproducible dependency set.

Build in an extracted, verified official webtrees-2.2.6.zip directory:

1. Copy this composer.json and composer.lock into the extracted `webtrees` root.
2. Run `composer install --no-dev --no-plugins --no-scripts --no-interaction`.
3. Run `composer audit --no-dev` and the SCHUIT GEDCOM/HTTP regression checks.
4. Package only `vendor` (regular files/directories, no symlinks) as
   `webtrees-vendor.tar.gz`; record SHA-256 in deployment/security-artifacts.json.
5. Publish the source/lock/checksum before deployment. Binaries stay outside Git.

On a future upstream upgrade, compare its dependency set with these fixes before
removing this overlay. Also diff the custom layouts/default.phtml against the
upstream layout; authentication, privacy handling, CSRF, menus and script stacks
must be retained.
