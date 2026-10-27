# Nederlandse interface

The SCHUIT review site uses Dutch for WordPress, GEDCOM-beheer and webtrees,
including their management screens. WordPress uses `nl_NL`; webtrees uses `nl`.
The official Dutch catalogs are already installed. The GEDCOM plugin's interface
is written in Dutch. Historical documents and the intentionally English
information page are retained in their original language.

webtrees normally prioritizes the current session and browser language over the
site default. Keep only its Dutch language module enabled so an English browser
or an old English session cannot change the interface language. Set existing
accounts' language preferences to Dutch, including the default webtrees user.
WordPress accounts created later inherit the Dutch site language unless a user
explicitly chooses a different personal language.

`scripts/configure-dutch.php` audits these settings by default. After publishing
the script to GitHub, pass it on stdin to `docker exec -i schuit-web php
/dev/stdin`. Save the audit output in a root-only file under `/srv/schuit` first.
Repeat with `--apply` to update only database language preferences. The script
does not modify accounts' rights, passwords, genealogy records or application
code, and requires no application release or restart. Deployment CVE gates stay
unchanged. To undo, restore only the language values and module statuses in the
saved snapshot (remove newly added preferences that were previously absent).

Verify the public site, WordPress login, GEDCOM administration labels, webtrees
login and control-panel labels. Test webtrees with an English Accept-Language
header as well as Dutch. Product names such as WordPress and webtrees, and
technical identifiers such as GEDCOM, remain unchanged.
