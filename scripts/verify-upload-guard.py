#!/usr/bin/env python3
"""Bounded unauthenticated HTTPS tests; no imports, accounts or saved uploads."""

import json
import secrets
import urllib.request
import urllib.error

origin = "https://schuit.centipod.ai"
results = []


def upload(path, name, content, mime="application/octet-stream"):
    boundary = "schuit-check-" + secrets.token_hex(12)
    body = (
        (
            f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="{name}"\r\nContent-Type: {mime}\r\n\r\n'
        ).encode()
        + content
        + f"\r\n--{boundary}--\r\n".encode()
    )
    request = urllib.request.Request(
        origin + path, data=body, headers={"Content-Type": "multipart/form-data; boundary=" + boundary}
    )
    try:
        with urllib.request.urlopen(request, timeout=30) as response:
            return response.status
    except urllib.error.HTTPError as error:
        return error.code


for path in ["/wp-login.php", "/tree/login/0"]:
    for name, content, mime in [
        ("fixture.svg", b'<svg xmlns="http://www.w3.org/2000/svg"/>', "image/svg+xml"),
        ("fixture.jpg", b'<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"/>', "image/jpeg"),
    ]:
        code = upload(path, name, content, mime)
        results.append({"check": path + " rejects " + name, "passed": code == 415, "status": code})
for ext in ["svg", "xml", "html"]:
    try:
        with urllib.request.urlopen(
            origin + "/wp-content/uploads/security-fixture." + ext, timeout=30
        ) as response:
            code = response.status
    except urllib.error.HTTPError as error:
        code = error.code
    results.append(
        {"check": "Direct active-content upload denied: " + ext, "passed": code == 403, "status": code}
    )
print(json.dumps(results, indent=2))
raise SystemExit(0 if all(x["passed"] for x in results) else 1)
