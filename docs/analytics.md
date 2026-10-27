# SCHUIT visitor statistics

GA4 account: centipod.io (188509674). Dedicated property: schuit.info
(560979394), stream 16214215283, measurement G-LB9BXB5TC4.
Enhanced measurement is disabled in GA4. Keep it disabled: automatic searches,
clicks, form interactions and page titles may disclose genealogy information.

The user authorised the temporary preview schuit.centipod.ai until cutover.
Production hostnames are schuit.info and www.schuit.info. All other hosts are
rejected. No DNS changes are part of this change.

WordPress loads a small local script only for anonymous, non-admin, non-editor-preview
visitors. The webtrees theme includes the same script only for anonymous visitors.
The script asks in Dutch before loading Google; rejection causes no Google request.
Choices last 180 days in local storage and can be changed using the persistent
“Statistiekvoorkeuren” button. Withdrawal disables the property, clears its host
cookies and reloads to remove Google code. Storage failures never imply consent.

Only broad sections are sent. No original titles, record IDs, names, query strings,
fragments or full referrers are exported. Known search/social referrers are reduced
to their origin; other referrers are omitted. Advertising features are disabled.
Authentication, admin, search, contact and query-string pages are excluded; signed-in
traffic is excluded. Counts therefore describe consenting anonymous visits, not all
visitors. Acquisition reports are incomplete by design. GA still processes network
metadata and cookie identifiers, as the consent text explains.

## Weekly email

The Dutch report to jan@schuit.info is maintained in centipod/centipod.io:
`.github/workflows/schuit-weekly-report.yml` and `scripts/ga-report/schuit-*`.
It runs Monday 07:00 UTC, covering the previous Monday–Sunday in Europe/Amsterdam.
Every query uses this property and the exact three-host allowlist. A hostname table
separates preview visits. Remove the preview from both codebases after cutover.

## Verification and deployment status

PHP boundary tests and synthetic browser tests are included. Browser tests mock all
Google requests; they never pollute the real property. They cover refusal, remembered
consent, expiry, safe page fields, excluded routes/hosts and withdrawal with session
cookies preserved. The regular image/unit/browser/CVE release gate remains mandatory.

On 27 October 2026 the existing release scan (GitHub run 40502511645) blocked on
183 MEDIUM/HIGH/CRITICAL/UNKNOWN findings with no fixed version reported. This
analytics change must not bypass that gate. No live tag is installed by creating a
GA4 property, and an empty successful API result does not verify collection.

After a security-eligible deployment, check the visible consent UI, Google requests
only after acceptance, sanitised collection payload and actual GA4 Realtime data.
Do not claim end-to-end collection before these checks. The old public WordPress
site is separate; this repository only deploys the new Hetzner site.
