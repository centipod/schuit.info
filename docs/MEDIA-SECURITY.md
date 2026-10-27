# SCHUIT media runtime — 27 October 2026

The deployed site uses PHP GD for raster image processing. ImageMagick,
Ghostscript and optional HEIF, TIFF, RAW and printing libraries are removed from
the runtime. Existing JPEG, PNG, GIF and BMP images remain supported, along with
GD's WebP/AVIF support. Existing PDFs remain available as documents, but new PDF
thumbnail generation is unavailable.

The PHP entry guard rejects active-content uploads by extension, detected MIME
type and XML/DTD signatures, including disguised and nested multipart uploads.
WordPress's upload directory also denies direct access to active file types.
GEDCOM files continue through their existing authenticated analysis/confirmation
workflow. These controls do not replace application permissions or validation.

External XML entity resolution and PHP process-spawning functions are disabled.
Strict session IDs and exception-argument redaction are enabled. Login throttling,
the read-only/non-root container and network isolation remain in force.

Deploy with `scripts/deploy-media-hardening.py <published-commit>` after creating
the matching release directory. The script requires a fresh verified S3 backup,
tests the image, holds the GEDCOM worker lock, preserves application inventory
and rolls back on failure. Deployment identity is in `/srv/schuit/deployment.json`.

Regression checks include `tests/runtime-guard.php`, `scripts/audit-security.py`,
`scripts/verify-upload-guard.py`, both login checks, `scripts/verify-gedcom.py` and
the public-content verifier. Test accounts and synthetic queued GEDCOM jobs are
removed; the real genealogy is never replaced by acceptance tests.

This is attack-surface reduction, not a claim that every OS library is patched.
Detailed scanner evidence and unresolved vendor findings are held in the private
infrastructure repository. Administrator authentication remains unchanged pending
the owner's choice of native authenticator enrolment or Cognito.
