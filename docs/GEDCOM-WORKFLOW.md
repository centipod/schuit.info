# WordPress GEDCOM workflow

Review environment: https://schuit.centipod.ai/wp-admin/tools.php?page=schuit-gedcom-import

The installable `wordpress-plugins/schuit-gedcom` plugin adds **Tools / Gereedschap → GEDCOM-beheer**. Administrators receive `manage_schuit_gedcom` on activation. A separate `schuit_gedcom_manager` role has only `read` and this capability; explicitly assign it to users who may replace the configured tree. Such users see only their own uploads; administrators see all uploads. Existing WordPress sign-ins are used. Existing webtrees accounts are preserved.

1. Upload `.ged` (100 MB maximum, GEDCOM 5.5 / 5.5.1).
2. Background analysis checks structure, encoding, duplicate identifiers and file boundaries. Review individuals, family records, sources, media records, total records, size, SHA-256, normalization count, missing-reference warnings and differences from the current database.
3. Explicitly confirm replacement. Upload alone never changes webtrees. A changed source file or changed tree since analysis requires a new upload/review.
4. The host worker creates and verifies an encrypted S3 backup. Failed backup prevents import. Webtrees temporarily returns a maintenance page during backup/import; WordPress and the status page remain available.
5. Import uses the native webtrees importer in one InnoDB transaction. Counts must match before commit. Failure rolls back. An atomic completion marker in the same DB transaction permits recovery if a worker exits after commit but before saving its status.

The upload is retained unchanged. Normalization converts to UTF-8 and adds missing spaces before `/` **only in NAME fields**. URLs, file paths and notes are unchanged. UTF-16 uses iconv because the installed webtrees 2.2.6 converter substitutes valid accented Latin characters. Unsupported/malformed input is rejected rather than silently repaired. Media metadata is replaced from the GEDCOM, but existing media files are not deleted. GEDCOM does not contain the photo/document bytes.

## Runtime

- Fixed target: `SCHUIT_GEDCOM_TREE_ID=7`, the existing SCHUIT tree. No browser-controlled database identifier or command.
- Private uploads/status: `/srv/schuit/gedcom-jobs` → `/var/lib/schuit-gedcom`, UID 33, directory 0700, files 0600, outside document root.
- All original/normalized files and job metadata are included in the existing encrypted S3 backup and restore verification. Completed/failed/cancelled/unconfirmed ready jobs expire after 30 days. Backups retain their independent 35-day lifecycle. Queue limit: three outstanding jobs; 500 MB original uploads retained (normalized files require additional disk).
- `schuit-gedcom.timer` invokes `scripts/gedcom-worker.py` every 10 seconds after the previous run. There is no dependence on visits or WordPress cron.
- Browser writes require capability, ownership, nonce and valid server-side state. Per-job locks prevent double confirmation; worker and webtrees request locks serialize imports and exclude native web requests while replacing data.
- Native webtrees import remains available but should not run concurrently. Pending native imports and unreviewed edits block this workflow.
- Failed import status is private to its owner/admin. Errors never expose raw GEDCOM records or database query parameters. Operators can inspect the exception class and job ID.
- Monitor the timer/service and stalled jobs. Public status reports worker health without publishing filenames or genealogy data.

## Release and verification

Publish source to GitHub before deployment. Extract the exact Git archive into `/opt/infra/schuit/releases/<commit>` and execute that release's `scripts/deploy-gedcom.py <commit>`. It creates the private mount/locks, switches the source symlink, recreates only `schuit-web`, activates the plugin, installs the timer, waits for health and records the deployed revision. The container image is unchanged.

`tests/gedcom-workflow.php` checks valid LF/CRLF/CR, UTF-16, Windows-1252 and malformed fixtures. `tests/gedcom-transaction.php` exercises the native importer using a synthetic private tree within an outer rolled-back transaction; it tests successful import, completion marker, rollback on count mismatch and modified-file rejection. Production tree data must not be replaced for testing. HTTPS acceptance uses temporary accounts and synthetic uploads without confirming an import into the real tree.

After deployment, verify the existing complete GEDCOM export in analysis-only mode, HTTPS permissions/CSRF/upload/status, public website/webtrees counts, worker monitoring, encrypted backup and isolated restore. Retain the previous source release for code rollback. Restoring actual tree data requires the encrypted pre-import snapshot and an explicit recovery decision; do not blindly restore the entire database over unrelated subsequent edits.
