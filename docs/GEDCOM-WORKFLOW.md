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

Before a subsequent release, ensure no analysis/import is running or queued; do not recreate the container during a genealogy import. Schedule updates outside upload processing.

`tests/gedcom-workflow.php` checks valid LF/CRLF/CR, UTF-16, Windows-1252 and malformed fixtures. `tests/gedcom-transaction.php` exercises the native importer using a synthetic private tree within an outer rolled-back transaction; it tests successful import, completion marker, rollback on count mismatch and modified-file rejection. Production tree data must not be replaced for testing. HTTPS acceptance uses temporary accounts and synthetic uploads without confirming an import into the real tree.

After deployment, verify the existing complete GEDCOM export in analysis-only mode, HTTPS permissions/CSRF/upload/status, public website/webtrees counts, worker monitoring, encrypted backup and isolated restore. Retain the previous source release for code rollback. Restoring actual tree data requires the encrypted pre-import snapshot and an explicit recovery decision; do not blindly restore the entire database over unrelated subsequent edits.

## Accepted review deployment — 26 October 2026

Application `2218279c160f890ac97a9db135dbadcff1447a57`; monitoring `c1a19c1522608e88c41d86794e41e3e6e3d5a8c2`. The PHP image is unchanged. All 15 parser fixtures, 15 HTTPS acceptance checks, synthetic native import, completion marker, count-mismatch rollback and file-tamper rejection passed on Hetzner. The original export was analyzed without import: 144,045 records, 98,014 individuals, 46,018 families, UTF-8 / GEDCOM 5.5.1, no missing references or normalization changes. All temporary users and jobs were removed.

The verification screen was visually inspected using a sanitized synthetic render. Public portal, webtrees, backups and GEDCOM worker all report up on status.centipod.io. Fresh S3 backup and isolated restore succeeded, validating both databases and 14,231 files; restore finished at 18:39:52 UTC. Existing genealogical data and public-domain DNS were unchanged.

## Consistentiecontrole en privé-PDF (plugin 1.1.0)

Na de structurele controle onderzoekt dezelfde achtergrondtaak het genormaliseerde
bestand met vaste regels. Er is geen AI, externe gegevensuitwisseling of automatische
historische correctie. De controlepagina toont aantallen per categorie en een
Centipod-PDF met onderwerp/recordcodes, bevinding, reden en mogelijke oplossing.

Regels omvatten ongeldige/toekomstige datums, geboorte/overlijden/doop/begrafenis,
leeftijd bij huwelijk en ouderschap, postume kinderen, ontbrekende/verkeerde of
eenzijdige verwijzingen, zelfrelaties, afstammingslussen en mogelijke dubbele
personen/huwelijken. Circa-datums, andere kalenders en dubbele jaartallen worden
niet exact geïnterpreteerd; intervallen leiden alleen tot een melding als de
tegenstrijdigheid ook aan de grenzen blijft bestaan. Biologische leeftijdsregels
slaan expliciet adoptief/niet-biologisch ouderschap over. Mogelijke dubbele personen
vereisen dezelfde naam en exacte geboortedatum; hertrouw op een andere datum wordt
niet als dubbel huwelijk gemeld. Deze signalen zijn geen bewijs van fouten.

Signalen blokkeren de import niet. Structurele fouten of een mislukte controle/PDF
houden de taak buiten de bevestigingsfase. De gebruiker beoordeelt het rapport en
bevestigt daarna expliciet. Er verandert niets aan de transactionele import of back-up.

Het PDF staat buiten de webroot met modus 0600. Downloads vereisen de uploadrechten,
taakeigendom (of administratorrechten), een taakgebonden WordPress-nonce en een
SHA-256-controle. Reacties worden niet gecachet. Persoonsdetails staan niet in de
AJAX-status of publieke statistieken. Het rapport vervalt met de upload na 30 dagen.
De bestaande TCPDF-versie uit de vastgezette webtrees-dependencies rendert uitsluitend
platte tekst en het lokale logo; er worden geen URL's uit uploads opgehaald.

Verwerkingslimieten: 180 seconden en een geheugenbewaking op 280 MiB voor de
consistentiecontrole, naast de bestaande upload-/recordlimieten. Het PDF bevat
maximaal 500 signalen om onbetrouwbare uploads geen onbeperkte renderlast te geven.
Bij meer signalen vermelden zowel pagina als PDF de beperking en het werkelijke
totaal. Na broncorrecties kan opnieuw worden geüpload. Oude uploads krijgen niet
achteraf een impliciete goedkeuring: zonder rapport vraagt de pagina om herupload.
