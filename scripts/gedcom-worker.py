#!/usr/bin/env python3
"""Host-side queue runner: fixed commands, private files, backup before import."""

import fcntl
import json
import os
from pathlib import Path
import re
import shutil
import socket
from safe_queue import read_job, write_job
import time

os.umask(0o077)
ROOT = Path("/srv/schuit/gedcom-jobs")


def save(folder, job):
    job["updated_at"] = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
    write_job(ROOT, folder.name, job)


def broker(action, identifier=""):
    with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as client:
        client.settimeout(2200)
        client.connect("/run/schuit-worker-broker.sock")
        client.sendall(json.dumps({"action": action, "job": identifier}).encode() + b"\n")
        with client.makefile("rb") as response:
            raw = response.readline(262145)
            if len(raw) > 262144:
                raise RuntimeError("Invalid broker response")
            return json.loads(raw)


def php(action, identifier):
    code = broker(action, identifier)["returncode"]
    if code not in (0, 75) and read_job(ROOT, identifier)["state"] not in ("failed", "completed"):
        raise RuntimeError("GEDCOM worker exited unexpectedly; private job status retained")
    return code


def require_queue_access():
    # Path.glob silently treats an inaccessible parent as an empty queue.
    # Fail visibly before taking the worker lock instead of stranding uploads.
    descriptor = os.open(ROOT, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    os.close(descriptor)


def run():
    require_queue_access()
    with Path("/run/lock/schuit-worker.lock").open("r+") as runner:
        try:
            fcntl.flock(runner, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            return
        for path in sorted(ROOT.glob("*/status.json")):
            folder = path.parent
            if not re.fullmatch("[a-f0-9]{32}", folder.name):
                continue
            try:
                job = read_job(ROOT, folder.name)
            except (ValueError, OSError):
                print("Invalid GEDCOM queue entry skipped", flush=True)
                continue
            state = job["state"]
            if state in ("uploaded", "analyzing"):
                php("analyze", folder.name)
                return
            if state == "importing":
                result = php("recover", folder.name)
                if result != 75:
                    (ROOT / "maintenance").unlink(missing_ok=True)
                return
            if state in ("queued", "backing_up"):
                with (ROOT / "webtrees.lock").open("a") as lock:
                    try:
                        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
                    except BlockingIOError:
                        return  # An existing webtrees request/worker must finish first.
                    (ROOT / "maintenance").touch(mode=0o600)
                    try:
                        # Worker is the only writer for queued/running jobs. Browser
                        # actions accept READY only, so duplicate confirmations are safe.
                        job["state"] = "backing_up"
                        job["progress"] = "Versleutelde back-up van bestanden en databases naar S3…"
                        save(folder, job)
                        backup = broker("backup")
                        if backup["returncode"]:
                            raise RuntimeError("backup failed")
                        report = backup["backup"]
                        if not report.get("success"):
                            raise RuntimeError("backup not verified")
                        job["backup_key"] = report["archive_key"]
                        save(folder, job)
                        # Maintenance marker blocks new requests during the lock handoff;
                        # PHP then holds this lock throughout its DB transaction.
                        fcntl.flock(lock, fcntl.LOCK_UN)
                        php("import", folder.name)
                        broker("metrics")
                    except Exception:
                        latest = read_job(ROOT, folder.name)
                        if latest["state"] == "backing_up":
                            latest["state"] = "failed"
                            latest["error"] = (
                                "De back-up of verwerking kon niet starten. Er zijn geen gegevens vervangen. Neem contact op met de beheerder."
                            )
                            save(folder, latest)
                        # An importing child may still own the PHP/DB locks. The next
                        # tick recovers its committed marker or records a safe failure.
                        raise
                    finally:
                        (ROOT / "maintenance").unlink(missing_ok=True)
                return
            if (
                state in ("completed", "cancelled", "failed", "ready")
                and time.time() - path.stat().st_mtime > 30 * 86400
            ):
                with (ROOT / "queue.lock").open("a") as queue:
                    fcntl.flock(queue, fcntl.LOCK_EX)
                    with (folder / "lock").open("a") as joblock:
                        fcntl.flock(joblock, fcntl.LOCK_EX)
                        if read_job(ROOT, folder.name)["state"] == state:
                            shutil.rmtree(folder)


if __name__ == "__main__":
    run()
