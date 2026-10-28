#!/usr/bin/env python3
"""Bounded, read-only HTTPS regression checks. Never submit credentials or GEDCOM."""

import http.cookiejar
import json
import urllib.error
import urllib.request
import time

ORIGIN = "https://schuit.centipod.ai"
UA = "Mozilla/5.0 (SCHUIT security verification) AppleWebKit/537.36 Chrome/131.0 Safari/537.36"
jar = http.cookiejar.CookieJar()
client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
results = []


def fetch(path, method="GET"):
    request = urllib.request.Request(ORIGIN + path, headers={"User-Agent": UA}, method=method)
    try:
        response = client.open(request, timeout=30)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        return response.status, response.headers, response.read(), response.url


def check(label, condition):
    results.append({"check": label, "passed": bool(condition)})


code, headers, body, url = fetch("/")
check("Portal reachable", code == 200)
check(
    "Strict secure functional Webtrees cookie",
    any(
        c.name == "schuit_webtrees_visit"
        and c.secure
        and c.path == "/tree/"
        and c.has_nonstandard_attr("HttpOnly")
        and c.get_nonstandard_attr("SameSite") == "Strict"
        for c in jar
    ),
)
for path in ["/", "/tree/", "/wp-login.php", "/tree/login/0"]:
    code, headers, body, url = fetch(path)
    check(
        path + " reachable without cookie challenge",
        code == 200 and b"<title>Cookie check</title>" not in body,
    )
    check(
        path + " clickjacking protection",
        headers.get("X-Frame-Options") == "SAMEORIGIN"
        and "frame-ancestors 'self'" in headers.get("Content-Security-Policy", ""),
    )
    check(path + " HSTS", "max-age=31536000" in headers.get("Strict-Transport-Security", ""))
    check(path + " MIME protection", headers.get("X-Content-Type-Options") == "nosniff")
    check(path + " no server version exposure", not headers.get("Server") and not headers.get("X-Powered-By"))
    if path == "/tree/":
        check(
            "Webtrees integrated navigation and logo",
            b"schuit-portal-header" in body
            and b"/logo.png" in body
            and b'aria-current="page">Webtrees' in body,
        )
        check("Webtrees CSRF retained", b'name="_csrf"' in body or b'name="csrf"' in body)

for path in [
    "/wp-config.php",
    "/.git/config",
    "/xmlrpc.php",
    "/wp-admin/install.php",
    "/wp-admin/setup-config.php",
    "/readme.html",
    "/tree/data/config.ini.php",
    "/tree/data/import-originals/0.ged",
    "/tree/vendor/autoload.php",
    "/tree/app/Tree.php",
    "/tree/resources/views/layouts/default.phtml",
    "/tree/modules_v4/schuit-import/module.php",
    "/tree/media/",
    "/wp-content/uploads/",
    "/wp-content/uploads/security-probe.php",
    "/wp-content/uploads/security-probe.phtml",
    "/wp-content/uploads/security-probe.phar",
    "/wp-content/uploads/security-probe.PHP.jpg",
    "/wp-json/wp/v2/users",
    "/wp-json/wp/v2/users/1",
]:
    code, headers, body, url = fetch(path)
    check(path + " blocked", code in (400, 403, 404))
code, headers, body, url = fetch("/", "TRACE")
check("TRACE disabled", code in (403, 405))
code, headers, body, url = fetch("/wp-admin/tools.php?page=schuit-gedcom-import")
check("GEDCOM administration requires login", "/wp-login.php" in url and b'id="loginform"' in body)
code, headers, body, url = fetch("/tree/admin")
check("Webtrees administration requires login", "/login" in url or code in (401, 403))
report = {"checked_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()), "results": results}
print(json.dumps(report, indent=2))
raise SystemExit(0 if all(x["passed"] for x in results) else 1)
