#!/usr/bin/env python3
"""Exercise actual Docker recovery networking with no data or published ports."""

import json
import os
from pathlib import Path
import subprocess
import sys
import uuid

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "scripts"))
from restore_app import connect_database, run

name = "schuit-recovery-network-test-" + uuid.uuid4().hex[:12]
image = os.environ.get("SCHUIT_TEST_IMAGE", "schuit-ci:local")
try:
    run(["network", "create", "--internal", name])
    run(
        [
            "run",
            "-d",
            "--name",
            name,
            "--network",
            "none",
            "--read-only",
            "--cap-drop",
            "ALL",
            "--security-opt",
            "no-new-privileges",
            "--entrypoint",
            "sleep",
            image,
            "120",
        ]
    )
    connect_database(name, name, ["recovered-database"])
    container = json.loads(run(["inspect", name]))[0]
    network = json.loads(run(["network", "inspect", name]))[0]
    assert set(container["NetworkSettings"]["Networks"]) == {name}
    assert not container["HostConfig"]["PortBindings"]
    assert network["Internal"] is True
    assert "recovered-database" in container["NetworkSettings"]["Networks"][name]["Aliases"]
    print("Restored database reaches only its internal recovery network; no public ports.")
finally:
    for args in (["rm", "-f", name], ["network", "rm", name]):
        subprocess.run(["docker", *args], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=False)
