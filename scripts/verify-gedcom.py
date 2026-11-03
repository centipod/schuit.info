#!/usr/bin/env python3
"""Retired: synthetic uploads must never supersede real unconfirmed GEDCOMs."""

raise SystemExit(
    "Verification blocked: live uploads supersede unconfirmed files. "
    "Use ci/browser.sh with its disposable database and queue; "
    "production-code smoke checks must use a separate temporary queue."
)
