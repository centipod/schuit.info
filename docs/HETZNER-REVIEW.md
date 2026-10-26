# SCHUIT review on Hetzner — 26 October 2026

Review: https://schuit.centipod.ai/ (WordPress), https://schuit.centipod.ai/tree/ (webtrees).
The owner reviews this deployment before any public-domain cutover. Both existing public sites remain on their original hosts.

## Source and recovery

The old public WordPress source is https://www.schuit.info/schuit/ (YourHosting).
The previously redesigned site is https://stichtingschu-y-i-ij-t.nl/ (Lightsail).
The latter supplied the consistent WordPress/webtrees SQL exports, application files and existing accounts. Old public content and missing uploads were reconciled against the former.

- All 23 old public pages and 81 posts are present; normalized body text matches the original source for every entry. The Katwijk article was updated from the newer original text.
- One Contact page was added, with real email and telephone links. No mail was sent during verification.
- All eight published `dfads` sponsor records are rendered; obsolete banner-plugin tables were retained as archive data but not activated.
- 688 original uploads (186,732,263 bytes) recovered, including images, PDF publications, generated image sizes and one legacy BMP.
- Full attachment metadata was checked beyond the public REST media list. One of the 174 attachment records has no attached-file path; it is retained unchanged.
- Six original photographs from May 2013 and their 12 generated sizes return 404 at all three tested original-host upload roots. They are recorded in `public-upload-manifest.json`; recovering them requires an older source backup/photo archive. No replacement pictures were invented.
- 106 page URLs, 298 rendered upload URLs and the remaining internal links passed HTTP checks. No rendered content link points back to either original host.
- The old News/Activities posts and categories remain as archive data and existing URLs, but are absent from the main menu/homepage features.
- Existing obsolete RootsPersona helper pages are retained as source archive content. The supported genealogy interface is webtrees, linked from the new menu.

## Feedback implemented

Main menu: Home, Publicaties, De stichting, Webtrees, Contact. Foundation links include board, family codes/Geslachten, related names, worldwide counts, Grootschippers, background information, quarterly publication/newsletter, donors, sponsors, privacy, conditions, disclaimer, English information and links. Footer destinations are real pages. Publicaties includes pagination and newsletter information. The existing new design is retained, with responsive width fixes and consistent heading capitalization.

The homepage shows only **Personen** and **Gezinnen**. The active database and a fresh private GEDCOM export both contain **98,014 individuals and 46,018 family records**. Original import chunks no longer contain the uploaded GEDCOM payload, so the old upload itself could not be independently counted. The fresh export needs zero name-spacing changes.

`schuit-metrics.timer` refreshes an atomic aggregate snapshot every minute. A database snapshot transaction prevents inconsistent counts; pending native imports retain the previous complete totals. WordPress reads only the aggregate JSON, not webtrees database credentials. The obsolete fixed metrics file is not used.

GEDCOM updates now use the WordPress **Tools → GEDCOM-beheer** upload, verification and explicit-confirmation workflow. See [GEDCOM-WORKFLOW.md](GEDCOM-WORKFLOW.md) for permissions, transactional imports, pre-import backups and recovery. The native webtrees normalization hook remains available.

## Runtime and source ownership

- Source: `centipod/schuit.info`, `/opt/apps/schuit` → immutable release in `/opt/infra/schuit/releases/`.
- Shared routing, DNS and monitoring: `centipod/hetzner-server`.
- Compose: `deployment/compose.yaml`; image identity and deployed commits: `/srv/schuit/deployment.json`.
- Data: `/srv/schuit/{wordpress,uploads,webtrees,webtrees-data,webtrees-media,metrics,gedcom-jobs}`.
- Secrets: `/etc/centipod/schuit`, never Git. GEDCOM originals: `webtrees-data/import-originals`, private and denied by HTTP configuration.
- Separate databases/users: `wp_schuit`/`schuit_wp`, `wt_schuit`/`schuit_wt`, in existing `wordpress-mariadb`.
- PHP 8.3.36 with PDO MySQL; webtrees 2.2.6. Non-root application, read-only filesystem except explicit data mounts, 768 MiB/1 CPU limit, internal networks, no published application/database port.
- Existing CI workloads, DNS/email routing, disabled SSH forwarding and other application services preserved.
- The review site is noindex. WordPress outbound mail/HTTP are blocked; the isolated review container is not a production notification service. The Contact email link opens the visitor's own mail client. Re-enable and test application email only as an explicit production-readiness step.

Publish any maintained change to GitHub before deployment. Build from the recorded Dockerfile and pinned PHP base, extract the published Git archive to a new immutable release, switch `/opt/apps/schuit`, run `docker compose -f /opt/apps/schuit/deployment/compose.yaml up -d --force-recreate web`, then update the deployment manifest and verify health. Keep the prior release for code rollback; database/content rollback uses the encrypted snapshot. Never run the first-time `stage-hetzner.py` against an existing deployment.

## Backup, restore and monitoring

Daily full encrypted snapshot: `schuit-backup.timer` / `schuit-backup.service`.
Monthly isolated restore: `schuit-restore.timer` / `schuit-restore.service`.
These MariaDB snapshots are daily recovery points, not PostgreSQL-style continuous WAL archiving.

S3 uses the existing WordPress repository, prefix `test/application-files/wordpress/schuit-platform/`, 35-day lifecycle and existing off-server escrowed recovery key. Archives contain both SQL dumps, persistent files, private configuration and the deployment manifest. The key is never included in plaintext alongside the archive.

File hashes are calculated from the captured archive bytes, so a changing live statistics snapshot cannot invalidate the backup. A backup is uploaded, downloaded and hash-checked. Restore testing verifies authenticated decryption, every recorded file checksum and both SQL dumps, imports into an isolated disposable MariaDB container with no network/ports, checks database counts and removes the container. Read `/srv/schuit/backup-state.json` for the actual latest outcomes. Manually trigger the systemd backup/restore services after a substantive migration or import.

https://status.centipod.io has SCHUIT review, SCHUIT Webtrees and SCHUIT backups entries. The application probes cover HTTPS and shared database/container health; the backup probe checks backup and restore freshness. Existing host monitoring alerts also cover SCHUIT container health, overdue/failed backups/restores and inactive statistics/backup timers.

## Acceptance and remaining review

The final encrypted backup and isolated restore succeeded at 17:52 UTC on 26 October 2026, checking both databases and 14,227 files. See `migration-acceptance-2026-10-26.json` and `public-upload-manifest.json`. WordPress HTTPS sign-in/dashboard passed with a temporary account that was removed. Existing account credentials are preserved. Public webtrees routing, counts and private-record restrictions were checked; an owner should also review their usual existing webtrees account and workflows.

The only known unavailable source assets are the six historical photographs above. No production DNS switch, original-project deletion or billing cancellation is part of this review deployment.
