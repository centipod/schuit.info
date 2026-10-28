# Privacy operations and approval record

Status: technical controls prepared; foundation decisions must be recorded before claiming
GDPR readiness. Contact: jan@schuit.info. The current notice identifies Stichting Schu-y-i-ij-t
as responsible; confirm controller/processor contracts rather than infer them from hosting.

## Foundation decisions to record

| Decision | Required evidence | Status |
|---|---|---|
| Purpose and lawful basis per data category | Approved processing record, including data received from third parties | Awaiting foundation |
| Living people, children, religion/health and other sensitive data | Need/condition assessment, minimisation and DPIA screening | Awaiting foundation |
| Processors and remote access | Centipod/Hetzner/AWS agreements and transfer/access assessment | Awaiting foundation |
| Retention and archival exceptions | Approved duration/criteria for genealogy, accounts, uploads and backups | Awaiting foundation |
| Incident response | Named decision maker, secure intake, investigation and legally required notification assessment | Awaiting foundation |

## Rights request workflow

1. Receive requests privately at the foundation contact. Verify identity proportionately;
   do not ask for identity documents by ordinary email or put genealogy in GitHub issues.
2. Record a request reference and affected source identifiers in a restricted case register.
   Determine the lawful response and any applicable archival exceptions with the foundation.
3. Correct the source genealogy first. For an approved deletion/exclusion, add its stable
   GEDCOM XREF to `/srv/schuit/privacy-controls/excluded-xrefs.json` using host administration.
   This directory is root-owned, readable by UID 33 and mounted read-only into the app.
   Format: `{"version":1,"excluded_xrefs":["I123"]}`. Do not store names in this file.
4. Correct/remove the corresponding live record through authorised webtrees administration,
   review publications/media separately, and validate with a synthetic/non-identifying check.
5. Upload the corrected export. The importer rejects excluded record IDs at analysis and
   again immediately before import. It never silently removes a person or their relationships.
6. **XREF stability matters:** if the exporter renumbers IDs, stop importing until the
   foundation/operator has reconciled the exclusion register against the new identifiers.
   This safeguard does not recognise a person reintroduced under a different identifier.
7. In recovery, reapply the current exclusions/corrections before reopening recovered data.
   Keep the current restricted case register off-host. Backups expire under the storage
   lifecycle; document any legally approved exceptions rather than deleting all backups.
8. Record completion and communicate the outcome within applicable legal deadlines.

Do not invent consent, agreements, lawful bases or approval. Updating technology does not
replace the foundation's decision-making. GDPR primary source: https://eur-lex.europa.eu/eli/reg/2016/679/oj.
