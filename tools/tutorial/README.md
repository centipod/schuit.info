# Dutch SCHUIT tutorial, 29 October 2026

This is the updated Aldfaer/GEDCOM tutorial. The application was revalidated at
commit `39eb7cfa24d8d083119c3d39f1b42dbf90493845` in a loopback-only Docker fixture.
The live website and its genealogical database were not modified.

## What the video demonstrates

1. Export Aldfaer to GEDCOM (explanatory card, not a recording of native Aldfaer).
2. Open `https://schuit.info/wp-login.php`, sign in, then Tools → GEDCOM-beheer.
3. Upload a GEDCOM 5.5/5.5.1 file, review metrics, explicitly confirm replacement.
4. Wait for completion and verify webtrees statistics and sample relationships.

Direct `.alx` upload and GEDCOM 7 are **not** supported by this deployed plugin.
The narration says so explicitly. Media-file upload is outside this tutorial.
The WordPress login shown is a labelled local demonstration; live sign-in uses
Cognito and may present the AWS security check. The public login route was
verified in the browser to reach that security check, without bypassing it.

## Footage and audio provenance

- Real screen-recorded upload/review/import footage from the previous premium v2
  tutorial is re-edited and retimed. Its workflow was checked against this build.
  Only the inner application viewport is reused; old narration and captions are removed.
- New browser screenshots show the current Dutch Tools menu and webtrees counts.
- All genealogy data in the demo is fictitious. The new isolated test changed
  10 persons/3 families to 12 persons/4 families and the worker verified 18 records.
  Reused footage has a different fictional starting count (9 persons); both
  examples end with 12 persons/4 families, and the narration only states those totals.
- Runway, voice Miriam, steady multilingual narration, speed 1.0, Dutch.
  A separate English clip pronounces “Webtrees.” before the statistics chapter.
- The displayed captions and narration are new. No generative UI imagery is used.
- Music is omitted. Final speech is normalized toward -16 LUFS / -1.5 dBTP.

`narration-v3.json` contains the exact Dutch script. API download URLs, account
metadata, disposable credentials, screenshots and binaries are excluded from Git.

## Reproduction

Prerequisites: the SCHUIT application image tagged `schuit-quality:local`, Docker,
Python with Pillow, ffmpeg/ffprobe with libx264, Arial fonts on macOS.

`setup.py` derives a synthetic tutorial stack from the CI fixtures. It creates
`.tutorial/compose.json`, `.tutorial/init.php`, `.tutorial/init-tree.php` and
`.tutorial/process-jobs.py`. Credentials are generated in ignored `.ci/` files.
The stack only listens on `127.0.0.1:18890` and uses its own named volumes.
The initialization refuses to reuse an installed WordPress fixture database.

```sh
python3 tools/tutorial/setup.py
docker compose --env-file .ci/env -f .tutorial/compose.json up -d
docker compose --env-file .ci/env -f .tutorial/compose.json exec -T web php /opt/schuit-repo/.tutorial/init.php
docker compose --env-file .ci/env -f .tutorial/compose.json exec -T web php /opt/schuit-repo/.tutorial/init-tree.php
```

Install the official Dutch language pack into the fixture's `wp-content/languages`
directory: `https://downloads.wordpress.org/translation/core/7.1.3/nl_NL.zip`.
Navigate the real UI using the synthetic credentials. Upload `tests/ci/replacement.ged`.
Run `python3 .tutorial/process-jobs.py analyze`, review the report in the browser,
confirm there, then run `python3 .tutorial/process-jobs.py import`.
This worker uses the CI backup mechanism, not the production S3 backup service.

To render, supply `.tutorial/audio-final.json` as a list containing `scene` and
`text` from the narration script, `.tutorial/audio/<scene>.mp3`, plus
`webtrees.mp3` for the English name, and `.tutorial/captures/tools.png` and
`statistics.png` captured through the browser. The media directory is private.

```sh
python3 tools/tutorial/render-v3.py \
  --previous-video /absolute/path/SCHUIT-GEDCOM-handleiding-NL-premium-v2.mp4 \
  --media-dir .tutorial \
  --output-dir /absolute/path/output
docker compose --env-file .ci/env -f .tutorial/compose.json down
```

Outputs: H.264/AAC MP4, Dutch SRT subtitles, and chapter timestamps. Video binaries
are delivered separately. Review screenshots, complete decoder check, duration,
audio streams and loudness before sharing the render.

Aldfaer export reference:
https://www.aldfaerforum.nl/forum/helpdesk/9060-gedcom-bestand-aanmaken.html
