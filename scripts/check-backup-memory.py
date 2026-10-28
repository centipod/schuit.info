#!/usr/bin/env python3
"""Synthetic 1 GiB backup roundtrip; no live data and a 128 MiB RSS budget."""

import hashlib
import json
import os
from pathlib import Path
import resource
import sys
import tempfile
from backup_stream import encrypt, decrypt

with tempfile.TemporaryDirectory(prefix="schuit-backup-memory-") as directory:
    root = Path(directory)
    source = root / "source"
    with source.open("wb") as output:
        output.truncate(1024**3)
    key = os.urandom(32)
    encrypt(source, root / "cipher", key)
    decrypt(root / "cipher", root / "restored", key)
    with source.open("rb") as a, (root / "restored").open("rb") as b:
        if hashlib.file_digest(a, "sha256").digest() != hashlib.file_digest(b, "sha256").digest():
            raise RuntimeError("Roundtrip mismatch")
    rss = resource.getrusage(resource.RUSAGE_SELF).ru_maxrss
    rss_bytes = rss if sys.platform == "darwin" else rss * 1024
    print(
        json.dumps(
            {
                "synthetic_bytes": 1024**3,
                "peak_rss_bytes": rss_bytes,
                "limit_bytes": 128 * 1024**2,
                "success": rss_bytes < 128 * 1024**2,
            }
        )
    )
    if rss_bytes >= 128 * 1024**2:
        raise RuntimeError("Backup memory budget exceeded")
