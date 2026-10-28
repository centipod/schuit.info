#!/usr/bin/env python3
"""Recover public uploads referenced by the old WordPress content inventory.

Input/output stay in ignored working/: public source API JSON, then upload files
and a checksum manifest. Never overwrite an existing website or fetch credentials.
"""

import argparse
import concurrent.futures
import hashlib
import html
import json
import pathlib
import re
import subprocess
import time
import urllib.parse

parser = argparse.ArgumentParser()
parser.add_argument("--workers", type=int, default=1)
parser.add_argument("--delay", type=float, default=0.5)
args = parser.parse_args()
root = pathlib.Path("working")
uploads = root / "recovered-uploads"
uploads.mkdir(exist_ok=True)
paths = set()
for file in (root / "source").glob("*"):
    if file.name.startswith("."):
        continue
    if file.suffix not in (".json", ".html"):
        continue
    text = file.read_text()
    for match in re.finditer(r'(?:https?:)?(?:\\/|/){2}[^\s"<>]+', text):
        value = html.unescape(match.group().replace("\\/", "/").rstrip("\\"))
        if "/wp-content/uploads/" not in value:
            continue
        value = value.split("/wp-content/uploads/", 1)[1]
        value = re.split(r'[\s"<>\\]', value)[0]
        value = urllib.parse.unquote(urllib.parse.urlsplit(value).path)
        p = pathlib.PurePosixPath(value)
        if (
            ".." in p.parts
            or p.is_absolute()
            or p.suffix.lower()
            not in [
                ".jpg",
                ".jpeg",
                ".png",
                ".gif",
                ".bmp",
                ".tif",
                ".tiff",
                ".webp",
                ".svg",
                ".pdf",
                ".doc",
                ".docx",
                ".xls",
                ".xlsx",
                ".zip",
                ".mp3",
                ".mp4",
            ]
        ):
            continue
        paths.add(str(p))


def fetch(path):
    dest = uploads / path
    if dest.is_file():
        data = dest.read_bytes()
        return dict(path=path, sha256=hashlib.sha256(data).hexdigest(), bytes=len(data), status="copied")
    errors = []
    time.sleep(args.delay)
    for prefix in [
        "https://www.schuit.info/schuit/schuit/wp-content/uploads/",
        "https://www.schuit.info/schuit/wp-content/uploads/",
    ]:
        url = prefix + urllib.parse.quote(path, safe="/")
        try:
            dest.parent.mkdir(parents=True, exist_ok=True)
            part = dest.with_name(dest.name + ".part")
            response = subprocess.run(
                [
                    "curl",
                    "--fail",
                    "--silent",
                    "--show-error",
                    "--location",
                    "--connect-timeout",
                    "10",
                    "--max-time",
                    "90",
                    "--max-filesize",
                    str(100 * 1024 * 1024),
                    "--output",
                    str(part),
                    "--write-out",
                    "%{content_type}",
                    url,
                ],
                capture_output=True,
                text=True,
            )
            if response.returncode or "text/html" in response.stdout:
                part.unlink(missing_ok=True)
                raise ValueError("HTTP transfer failed: " + response.stderr.strip())
            data = part.read_bytes()
            part.replace(dest)
            return dict(
                path=path,
                sha256=hashlib.sha256(data).hexdigest(),
                bytes=len(data),
                status="copied",
                source=url,
            )
        except Exception as e:
            errors.append(str(e))
    return dict(
        path=path,
        status="missing_at_source" if all("error: 404" in e for e in errors) else "transfer_failed",
        errors=errors,
    )


results = []
with concurrent.futures.ThreadPoolExecutor(max_workers=args.workers) as pool:
    for result in pool.map(fetch, sorted(paths)):
        results.append(result)
        if len(results) % 50 == 0:
            print(str(len(results)) + "/" + str(len(paths)) + " checked", flush=True)
(root / "asset-recovery.json").write_text(json.dumps(results, indent=2) + "\n")
print(
    json.dumps(
        dict(
            discovered=len(paths),
            copied=sum(x["status"] == "copied" for x in results),
            missing_at_source=sum(x["status"] == "missing_at_source" for x in results),
            transfer_failed=sum(x["status"] == "transfer_failed" for x in results),
            bytes=sum(x.get("bytes", 0) for x in results),
        )
    )
)
