#!/usr/bin/env python3
"""Daily scan of the running image and its published source; never deploys."""

import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import time

BASE = Path("/srv/schuit")


def main():
    os.umask(0o077)
    result = {"timestamp": time.time(), "success": False}
    try:
        deployment = json.loads((BASE / "deployment.json").read_text())
        image = subprocess.check_output(
            ["docker", "inspect", "schuit-web", "--format", "{{.Image}}"], text=True
        ).strip()
        if image != deployment["image"]:
            raise ValueError("Runtime differs from deployment record")
        with tempfile.TemporaryDirectory(prefix="schuit-scan-", dir="/var/tmp") as tmp:
            source = Path(tmp) / "source"
            shutil.copytree(
                "/opt/apps/schuit",
                source,
                ignore=shutil.ignore_patterns(".ci", ".git", "node_modules", "vendor"),
            )
            run = subprocess.run(
                ["bash", "/opt/infra/schuit/deploy-gate/scan.sh"],
                cwd=source,
                env={
                    **os.environ,
                    "SOURCE_REVISION": deployment["source_commit"],
                    "SCHUIT_TEST_IMAGE": image,
                },
                timeout=2400,
                check=False,
            )
            evidence = BASE / "release-evidence" / "daily" / time.strftime("%Y%m%dT%H%M%SZ", time.gmtime())
            evidence.mkdir(parents=True)
            shutil.copytree(source / ".ci/scans", evidence / "scans")
            eligibility = json.loads((evidence / "scans/eligibility.json").read_text())
            result.update(
                success=run.returncode == 0 and eligibility["eligible"],
                image=image,
                revision=deployment["source_commit"],
                blocking_findings=eligibility["blocking_findings"],
            )
    finally:
        target = BASE / "security-state.json"
        temporary = target.with_suffix(".tmp")
        temporary.write_text(json.dumps(result, indent=2) + "\n")
        temporary.replace(target)
    if not result["success"]:
        raise RuntimeError("SCHUIT daily security scan requires attention")


if __name__ == "__main__":
    main()
