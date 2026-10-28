#!/usr/bin/env python3
"""Root-only atomic config provisioning from stdin; never print values."""

import json
import os
import sys
import tempfile
from pathlib import Path

if os.geteuid() != 0:
    raise SystemExit("Root required")
raw = sys.stdin.buffer.read(1048577)
if len(raw) > 1048576:
    raise SystemExit("Config too large")
d = json.loads(raw)
if not isinstance(d.get("enabled"), bool) or not isinstance(d.get("users"), dict):
    raise SystemExit("Invalid configuration")
if d.get("origin") != "https://schuit.centipod.ai":
    raise SystemExit("Unexpected site origin")
p = Path("/etc/centipod/schuit/cognito.json")
p.parent.mkdir(parents=True, exist_ok=True)
fd, name = tempfile.mkstemp(dir=p.parent, prefix=".cognito-")
try:
    with os.fdopen(fd, "wb") as f:
        os.fchmod(f.fileno(), 0o640)
        os.fchown(f.fileno(), 0, 33)
        f.write(raw)
        f.flush()
        os.fsync(f.fileno())
    os.replace(name, p)
finally:
    if os.path.exists(name):
        os.unlink(name)
print("Cognito configuration installed; values withheld.")
