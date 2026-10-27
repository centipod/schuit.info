# Aldfaer / GEDCOM import proof of concept

Local, isolated feasibility study. It does **not** change the WordPress plugin,
connect to a database, submit a tree, send email or deploy to Hetzner. The browser
offers Dutch choices for automatic detection, GEDCOM and Aldfaer, followed by a
metrics/verification screen. There is intentionally no live-import button.

## Results (27 October 2026)

| Input | Outcome |
| --- | --- |
| Supplied Aldfaer 8.1 GEDCOM 5.5 | Windows-1252 → UTF-8; accepted by current plugin parser; all 2,009 individuals, 950 families, 5,946 family links and all non-header fields preserved. Seven `ë` and two `é` preserved. |
| Synthetic GEDCOM 5.5.1 | Accepted without changing genealogy fields; UTF-8 and UTF-16 cases covered. |
| Synthetic GEDCOM 7.0 family | Limited-profile conversion to 5.5.1 accepted by current plugin parser: three people, one family, one shared note, one source and one submitter preserved. |
| Supplied Aldfaer `.alx` | ZIP CRC passes; one binary `.aldf` and one log, no embedded GEDCOM. Automatic conversion blocked. |

Validation compares every non-header field, identifier and order before/after
conversion, then compares the entire output of the current PHP analyzer against
the expected NAME slash-spacing transformation. Counts alone are insufficient.
The source files were not altered. The supplied GEDCOM and ALX are **not** a
matching full-export pair: the backup log suggests approximately 75,265 active
people at maintenance time, versus 2,009 in the older GEDCOM. Log counts are not
verified counts decoded from the ALDF database. `Relatie` is not assumed to mean
the same thing as a GEDCOM FAM record.

The real GEDCOM retains `_NEW`, `_NOPARTNER`, `_PEDI` and `_NOCHILD`. Their storage
is preserved; their application-specific meaning/display in webtrees has not
been validated. Structural/link validation is not complete schema validation,
and no actual webtrees database import/round-trip has been performed. GEDCOM 7
testing currently uses **synthetic fixtures**, not a real Aldfaer 7-format export.

## Supported scope and conversion issues

- GEDCOM 5.5/5.5.1: UTF-8 normalization and structural/reference checks. Existing
  custom fields are preserved and disclosed. No claim of supporting every legacy
  encoding: unknown ANSI/ANSEL encodings are rejected by this POC.
- Aldfaer 8.1 `CHAR ANSI`: the supplied sample is Windows-1252; the current
  webtrees encoding factory otherwise selects CP850, corrupting `ë`/`é`. The POC
  uses a producer/version-specific override. This is **not deployed** to the live
  uploader. Other producers and Aldfaer versions are not guessed.
- GEDCOM 7.0 (including patch-version headers): strict UTF-8, small path allowlist.
  Converts shared `SNOTE` records/references to `NOTE`, translates text `@`
  escaping and adds 5.5.1 header metadata. Missing submitter information blocks
  conversion rather than inventing an author.
- Blocks unsupported GEDCOM 7 fields, including `PHRASE`, negative assertions
  (`NO`), translations, schemas/extensions, formatted notes, name pieces,
  enumeration changes, non-basic dates/calendars, media, long lines/identifiers
  and `@VOID@`. Unsupported data is listed; no converted file is released.
- Missing records, incorrect pointer target types and nonreciprocal family links
  block output. There is no duplicate-person resolution or genealogical fact
  verification. IDs are checked for duplicates; people are not merged.
- Aldfaer ALX/ALDF: detection and bounded archive inspection only. No reliable
  native decoder or supported headless export mechanism was established. An
  archive can be valid while the database inside is semantically damaged.
- Media upload remains outside scope. Existing 5.x references are preserved;
  files are not collected or fetched.

**Recommendation:** ship GEDCOM 5.5/5.5.1 first with the encoding correction;
keep GEDCOM 7 behind the conversion/validation gate until tested against real
exports. Only enable native Aldfaer import once an isolated Aldfaer export worker
or documented decoder is proven against complete matching exports. Do not
reverse-engineer a family tree by extracting visible strings from ALDF.

## Run

Requires Python 3.10+ (standard library only). From the repository root:

```sh
python3 tools/gedcom_poc/run.py --serve
# Open http://127.0.0.1:18835/
python3 tools/gedcom_poc/run.py /private/path/input.ged --format gedcom
python3 tools/gedcom_poc/run.py /private/path/backup.alx --format aldfaer
```

Optional `--output /private/path/new.ged` and `--report /private/path/report.json`
write mode-0600 files and refuse overwrites. Output is withheld on blockers. Exit
code 2 means rejected or blocked. The browser never saves uploads or exposes a
download; CLI output can be used for further isolated tests. No genealogy values
are included in the metrics report.

The server binds only to `127.0.0.1`, checks Host, uses a per-process request token
and has no CORS. It is **not a production upload service**. Do not publish it
through the reverse proxy. ALX contents are read in bounded chunks, CRC-checked,
never extracted or executed. Traversal, symlinks, encryption and oversized or
high-compression archives are rejected.

## Reproduce validation

```sh
python3 -m unittest discover -s tests/python -v
node tools/gedcom_poc/browser-check.cjs
# If using installed Chrome instead of Playwright's downloaded Chromium:
POC_BROWSER_CHANNEL=chrome node tools/gedcom_poc/browser-check.cjs
python3 tools/gedcom_poc/validate.py \
  --webtrees-runtime /private/path/to/webtrees \
  --gedcom /private/path/input.ged \
  --aldfaer /private/path/backup.alx
```

The PHP check initializes encoding services only, never database services.
Private temporary files are removed afterwards. Fixtures in Git contain synthetic
genealogy only. Real input files and generated GEDCOM must remain outside Git.
The Python tests join the existing unit gate; browser checks join the existing
browser gate. Existing MEDIUM+ CVE and deployment gates remain required.

## Before production integration

1. Obtain a full GEDCOM export from the **same** ALX backup. Test a real Aldfaer
   5.5.1 export and a real GEDCOM 7 export, including notes, sources and custom tags.
2. Test an isolated webtrees import and re-export, comparing counts, every
   individual/family relationship, names/accents, dates, places, notes, citations,
   privacy controls and custom-field handling. Never replace the live tree for a POC.
3. Extend and validate the 7→5.5.1 mapping where real data requires it. Retain
   original files and a per-job transformation report; prevent confirmation when
   unsupported data or unexplained changes remain.
4. Integrate into the existing authenticated WordPress workflow with its rights,
   confirmation, backups and rollback, then pass all CI/security gates.

## Primary references

- [GEDCOM specification](https://gedcom.io/specifications/FamilySearchGEDCOMv7.html)
- [Official 5.5.1 → 7 migration guide](https://gedcom.io/migrate/) (semantic changes
  also matter in the reverse direction; changing the version line is insufficient)
- [webtrees GEDCOM compatibility](https://webtrees.net/gedcom/)
- [Aldfaer export support](https://aldfaerforum.nl/forum/helpdesk/9740-oude-bestanden-2013-overzetten-naar-nieuwe-versie-mogelijk.html)

## Follow-up: upstream module review (27 October 2026)

Reviewed Jefferson49/ExtendedImportExport **v4.3.2** (released 25 October), source
commit `8979e11f145c6c7485d5de947b163961eaae4cb6`, plus the official release ZIP's
bundled dependency. The declared licence is GPL-3.0-or-later. No dedicated
CONTRIBUTING file, automated test suite or GitHub Actions workflows were found
among the 88 tracked files at this revision. This does not imply the maintainer
does not test; the README describes manual checks and external validators.

**Revised recommendation:** evaluate and extend this existing PHP module as the
conversion implementation. Keep our Python POC as an independent comparison
harness, rather than grow two competing production converters. The module's
GEDCOM 7 filter explicitly targets **5.5.1 → 7**; it is not an Aldfaer binary
decoder, universal format converter or proof of full native GEDCOM 7 editing in
webtrees. Direct core import, module-assisted import and re-export still need
isolated database tests before selecting the final path.

`review-upstream.php` runs the actual upstream filter engine and filter classes
with synthetic records and the local webtrees runtime. It does not mock the
conversion algorithms or connect to a database:

```sh
php tools/gedcom_poc/review-upstream.php \
  /private/path/to/webtrees \
  /private/path/to/official-release/extended_import_export
```

Observed results:

- Basic 5.5.1 header/shared-note conversion succeeds.
- Applying that filter directly to 5.5 leaves `VERS 5.5` while deleting CHAR and
  FORM. Add a source-version guard; do not interpret this as supported 5.5 input.
- Literal `test@@example.org` remains doubled in the 7 output. GEDCOM 7 interprets
  non-leading at signs differently; a version-aware text conversion is needed.
- `30 JAN 1648/9` and a phrase-only date `(during winter)` remain unchanged under
  a 7 header; the filter handles only some legacy date forms.
- The old ROMAN calendar becomes `ROMAN`, rather than a documented extension
  calendar. Unsupported calendars need an explicit mapping or diagnostic.
- A nested note-reference probe shows the limits of fixed-depth tag rules;
  establish schema-valid recursive fixtures before proposing a general fix.
- The separate optimisation filter removes a webtrees note's RESN restriction
  while retaining its text. This is intentional standardisation, not evidence of
  an HTTP authorization bypass. It must be an explicit, audited policy choice.
- A synthetic custom regex hitting its runtime resource limit silently deletes
  the matching NOTE subtree. `preg_replace(...) ?? ''` is the verified cause.
  Throw on null and preserve the input instead.

Additional static observations: filter `validate()` validates rule definitions,
not the resulting genealogy; conversion materialises record arrays in memory;
the database importer accumulates individual record exceptions rather than
necessarily aborting the transaction. These need output-validation, scale and
rollback tests; they were not exercised against a database in this review.

Proposed upstream sequence:

1. Synthetic fixture/CI foundation and the small regex-failure fix.
2. Source/target version and encoding guards; correct escaping and date mappings.
3. Optional structured diagnostics: changed/deleted/unmapped fields, counts,
   reference checks, privacy-impact warnings and a strict mode that blocks loss.
   Keep the existing filter interface compatible; add a result/reporting layer.
4. A callable conversion/preflight service and CLI adapter without HTTP globals,
   with an integration suite that tests import, editing, re-export and rollback.
5. Bounded memory/progress for large files once benchmarks identify bottlenecks.

Keep SCHUIT's Dutch WordPress UI, uploader permissions, confirmation, retained
originals, job scheduling, backups and rollback in our integration. Invoke the
PHP service from the existing worker; the remote URL-key endpoint is unnecessary
for this local workflow. Native Aldfaer export belongs in a separate adapter,
only after reliable extraction is established. Media upload stays out of scope.

No code has been submitted to or messages sent to the maintainer. Initial upstream
contributions should be small, independently reproducible fixes with synthetic
fixtures, followed by agreement on the larger diagnostic/service interfaces.
