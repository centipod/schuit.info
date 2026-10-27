#!/usr/bin/env python3
"""Retired: releases must pass the GitHub Actions and Hetzner CVE gates."""
raise SystemExit("Deployment blocked: use .github/workflows/ci.yml; legacy deployment cannot bypass unit, browser and MEDIUM+ CVE checks.")
