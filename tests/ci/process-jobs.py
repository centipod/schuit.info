#!/usr/bin/env python3
"""Drive the real importer against disposable CI data; never contact S3 or live DBs."""

from pathlib import Path
import hashlib
import json
import subprocess
import sys

root = Path(__file__).resolve().parents[2]
cmd = [
    "docker",
    "compose",
    "--env-file",
    str(root / ".ci/env"),
    "-f",
    str(root / "tests/ci/compose.yaml"),
    "-p",
    "schuit-ci",
]


def run(*args, **kw):
    return subprocess.run(cmd + list(args), check=True, **kw)


def php(code):
    return run("exec", "-T", "web", "php", input=code, text=True, capture_output=True).stdout


assert php('<?php echo getenv("CI_FIXTURE");') == "synthetic-only"
mode = sys.argv[1]
assert mode in ("analyze", "import")
jobs = json.loads(
    php(
        '<?php $r=[];foreach(glob("/var/lib/schuit-gedcom/*/status.json") as $p){$j=json_decode(file_get_contents($p),true);$r[]=["id"=>$j["id"],"state"=>$j["state"]];}echo json_encode($r);'
    )
)
for job in jobs:
    if mode == "analyze" and job["state"] == "uploaded":
        result = subprocess.run(
            cmd + ["exec", "-T", "web", "php", "/opt/schuit-scripts/gedcom-worker.php", "analyze", job["id"]],
            capture_output=True,
        )
        if len(sys.argv) > 2 and sys.argv[2] == "expect-invalid":
            assert result.returncode == 1, "Invalid file must fail analysis"
        else:
            result.check_returncode()
    elif mode == "import" and job["state"] == "queued":
        # Actual test-database backup before invoking the production importer.
        dump = run(
            "exec",
            "-T",
            "db",
            "sh",
            "-c",
            'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" mariadb-dump -uroot --single-transaction wt_ci',
            capture_output=True,
        ).stdout
        backup = root / ".ci" / ("backup-" + job["id"] + ".sql")
        backup.write_bytes(dump)
        assert (
            len(dump) > 100 and hashlib.sha256(backup.read_bytes()).digest() == hashlib.sha256(dump).digest()
        )
        php(
            '<?php $p="/var/lib/schuit-gedcom/'
            + job["id"]
            + '/status.json";$j=json_decode(file_get_contents($p),true);if($j["state"]!=="queued")throw new Exception("State changed");$j["state"]="backing_up";$j["backup_key"]="synthetic-ci-backup";file_put_contents($p,json_encode($j));'
        )
        run("exec", "-T", "web", "php", "/opt/schuit-scripts/gedcom-worker.php", "import", job["id"])
        run("exec", "-T", "web", "php", "/opt/schuit-scripts/update-metrics.php")
