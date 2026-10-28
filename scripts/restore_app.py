"""Boot restored applications on an isolated, unpublished network; no provider calls."""

import json
import os
from pathlib import Path
import re
import subprocess
import time


def run(args, **kwargs):
    result = subprocess.run(["docker", *args], capture_output=True, timeout=150, check=False, **kwargs)
    if result.returncode:
        raise RuntimeError("Isolated application restore check failed")
    return result.stdout


def connect_database(network: str, database: str, aliases: list[str]) -> None:
    # Docker's special `none` network must be detached before attaching another.
    # The destination is already an internal network; no public bridge is used.
    run(["network", "disconnect", "none", database])
    run(["network", "connect", *[arg for a in aliases for arg in ["--alias", a]], network, database])


def verify(folder: Path, manifest: dict, database: str) -> dict:
    started = time.monotonic()
    image = manifest["deployment"]["image"]
    if not re.fullmatch(r"sha256:[a-f0-9]{64}", image):
        raise ValueError("Backup has no immutable image")
    namespace = "schuit-restore-" + str(os.getpid())
    web, broker = namespace + "-web", namespace + "-identity"
    config, data = folder / "config", folder / "data"
    stub = folder / "stub"
    stub.mkdir(mode=0o755)
    stub.chmod(0o755)  # Restore process intentionally uses a restrictive umask.
    (stub / "wp-settings.php").write_text("<?php // Recovery bootstrap only.\n")
    (stub / "wp-settings.php").chmod(0o644)
    for root in (config, data):
        for path in [root, *root.rglob("*")]:
            if path.is_symlink():
                raise ValueError("Restored links are not allowed")
            os.chown(path, 33, 33)
            os.chmod(path, 0o700 if path.is_dir() else 0o600)
    # Interpret the original PHP configuration only as UID 33 in an isolated container.
    code = "define('ABSPATH','/stub/');require '/config/wp-config.php';echo json_encode(['wp'=>[DB_HOST,DB_USER,DB_PASSWORD,DB_NAME],'tree'=>parse_ini_file('/config/webtrees.ini.php')]);"
    credentials = json.loads(
        run(
            [
                "run",
                "--rm",
                "--network",
                "none",
                "--read-only",
                "--cap-drop",
                "ALL",
                "--security-opt",
                "no-new-privileges",
                "--entrypoint",
                "php",
                "-v",
                str(config) + ":/config:ro",
                "-v",
                str(stub) + ":/stub:ro",
                image,
                "-r",
                code,
            ]
        )
    )
    wp = credentials["wp"]
    tree = credentials["tree"]
    if wp[3] != "wp_schuit" or tree["dbname"] != "wt_schuit":
        raise ValueError("Unexpected restored databases")

    def literal(value):
        return "'" + value.replace("\\", "\\\\").replace("'", "''") + "'"

    statements = []
    for user, password, db in [(wp[1], wp[2], wp[3]), (tree["dbuser"], tree["dbpass"], tree["dbname"])]:
        if not re.fullmatch(r"[a-zA-Z0-9_]+", user):
            raise ValueError("Unexpected database username")
        statements += [
            f"CREATE USER {literal(user)}@'%' IDENTIFIED BY {literal(password)};",
            f"GRANT ALL ON `{db}`.* TO {literal(user)}@'%';",
        ]
    run(["exec", "-i", database, "mariadb", "-uroot"], input=("\n".join(statements)).encode())
    run(["network", "create", "--internal", namespace])
    try:
        aliases = sorted({wp[0].split(":")[0], tree["dbhost"]})
        if any(not re.fullmatch(r"[a-zA-Z0-9_-]+", a) for a in aliases):
            raise ValueError("Unexpected database host")
        connect_database(namespace, database, aliases)
        base = [
            "run",
            "-d",
            "--network",
            namespace,
            "--read-only",
            "--user",
            "33:33",
            "--cap-drop",
            "ALL",
            "--security-opt",
            "no-new-privileges",
            "--pids-limit",
            "128",
            "--cpus",
            "0.5",
            "--tmpfs",
            "/tmp:uid=33,gid=33,nosuid,noexec,size=128m",
        ]
        run(
            [
                *base,
                "--name",
                broker,
                "--network-alias",
                "cognito",
                "--memory",
                "64m",
                "--entrypoint",
                "php",
                "-v",
                str(config / "cognito.json") + ":/run/secrets/schuit-cognito.json:ro",
                image,
                "-S",
                "0.0.0.0:8081",
                "/opt/schuit-security/cognito-broker.php",
            ]
        )
        mounts = {
            data / "privacy-controls": "/run/schuit-privacy",
            data / "uploads": "/var/www/html/wp-content/uploads",
            data / "webtrees-data": "/var/www/html/tree/data",
            data / "webtrees-media": "/var/www/html/tree/media",
            data / "metrics": "/run/schuit-metrics",
            data / "gedcom-jobs": "/var/lib/schuit-gedcom",
            config / "wp-config.php": "/var/www/html/wp-config.php",
            config / "webtrees.ini.php": "/var/www/html/tree/data/config.ini.php",
            config / "cognito.json": "/run/secrets/schuit-cognito.json",
        }
        run(
            [
                *base,
                "--name",
                web,
                "--memory",
                "768m",
                "--tmpfs",
                "/var/run/apache2:uid=33,gid=33",
                "--tmpfs",
                "/var/lock/apache2:uid=33,gid=33",
                "-e",
                "SCHUIT_TRUSTED_PROXY_CIDR=127.0.0.1/32",
                "-e",
                "WEBTREES_METRICS_PATH=/run/schuit-metrics/metrics.json",
                *[
                    arg
                    for a, b in mounts.items()
                    for arg in ["-v", str(a) + ":" + b + (":ro" if config in a.parents else ":rw")]
                ],
                image,
            ]
        )
        for _ in range(30):
            try:
                run(["exec", web, "php", "/opt/schuit-scripts/health.php"])
                break
            except RuntimeError:
                time.sleep(1)
        else:
            raise RuntimeError("Restored application is not healthy")
        probe = r"""
$c = json_decode(file_get_contents('/run/secrets/schuit-cognito.json'), true);
$origin = parse_url($c['origin'], PHP_URL_HOST);
$statuses = [];
foreach (['/', '/tree/', '/tree/tree/0', '/wp-login.php'] as $path) {
    $ctx = stream_context_create(['http'=>[
        'timeout'=>15, 'follow_location'=>0, 'ignore_errors'=>true,
        'header'=>'Host: '.$origin."\r\nX-Forwarded-Proto: https\r\n"
    ]]);
    $http_response_header = [];
    @file_get_contents('http://127.0.0.1:8080'.$path, false, $ctx);
    preg_match('/^HTTP\/[^ ]+ ([0-9]{3})/', $http_response_header[0] ?? '', $matches);
    $statuses[$path] = (int) ($matches[1] ?? 0);
}
echo json_encode($statuses);
"""
        statuses = json.loads(run(["exec", web, "php", "-r", probe]))
        expected = {"/": 200, "/tree/": 302, "/tree/tree/0": 200, "/wp-login.php": 303}
        if statuses != expected:
            # Fixed public route names and numeric status only; no private response bodies.
            raise RuntimeError("Restored HTTP status check failed: " + json.dumps(statuses))
        return {
            "booted": True,
            "private_network": True,
            "database_users_restored": True,
            "portal_tree_login_checked": True,
            "image": image,
            "duration_seconds": round(time.monotonic() - started, 2),
            "live_identity_acceptance": False,
        }
    finally:
        for name in (web, broker):
            subprocess.run(
                ["docker", "rm", "-f", name],
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
                check=False,
            )
        subprocess.run(
            ["docker", "network", "disconnect", namespace, database],
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            check=False,
        )
        subprocess.run(
            ["docker", "network", "rm", namespace],
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            check=False,
        )
