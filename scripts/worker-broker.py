#!/usr/bin/env python3
"""Socket-activated privileged broker: fixed commands, never opens queue paths."""

import json
import os
import re
import subprocess
import sys
import time
from pathlib import Path


def command(request):
    if not isinstance(request, dict) or set(request) != {"action", "job"}:
        raise ValueError("Invalid request")
    action, identifier = request["action"], request["job"]
    if action == "backup" and identifier == "":
        return [
            "/opt/infra/schuit/backup-venv/bin/python3",
            "/opt/apps/schuit/scripts/backup-hetzner.py",
            "backup",
        ], 1200
    if (
        action in {"analyze", "import", "recover"}
        and isinstance(identifier, str)
        and re.fullmatch("[a-f0-9]{32}", identifier)
    ):
        return [
            "/usr/bin/docker",
            "exec",
            "--user",
            "33:33",
            "schuit-web",
            "php",
            "/opt/schuit-scripts/gedcom-worker.php",
            action,
            identifier,
        ], 2100
    if action == "metrics" and identifier == "":
        return [
            "/usr/bin/docker",
            "exec",
            "--user",
            "33:33",
            "schuit-web",
            "php",
            "/opt/schuit-scripts/update-metrics.php",
        ], 120
    raise ValueError("Command denied")


def main():
    os.umask(0o077)
    request = json.loads(sys.stdin.buffer.readline(1025))
    args, timeout = command(request)
    if request["action"] == "import":
        backup = json.loads(Path("/srv/schuit/backup-state.json").read_text())["backup"]
        if not backup["success"] or not 0 <= time.time() - backup["timestamp"] < 3600:
            raise ValueError("Verified backup required")
    # Children emit only bounded aggregate status. Private errors never cross the socket.
    result = subprocess.run(
        args, capture_output=True, timeout=timeout, check=False, env={"PATH": "/usr/sbin:/usr/bin:/sbin:/bin"}
    )
    reply = {"returncode": result.returncode}
    if request["action"] == "backup" and result.returncode == 0:
        reply["backup"] = json.loads(result.stdout)
    print(json.dumps(reply), flush=True)


if __name__ == "__main__":
    try:
        main()
    except Exception:
        print('{"returncode":1}', flush=True)
        sys.exit(1)
