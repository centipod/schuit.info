#!/usr/bin/env python3
"""Bounded checks from the server itself; no passwords or existing accounts.
Restore only the test source's counters, leaving other clients untouched.
"""

import concurrent.futures
import datetime
import http.cookiejar
import json
import secrets
import subprocess
import urllib.error
import urllib.request

ORIGIN = "https://schuit.centipod.ai"
STATE = "/tmp/schuit-login-limits/attempts.json"


def php(code):
    return subprocess.check_output(["docker", "exec", "schuit-web", "php", "-r", code], text=True).strip()


def state():
    return json.loads(
        php("echo is_file(" + json.dumps(STATE) + ") ? file_get_contents(" + json.dumps(STATE) + ') : "{}";')
    )


jar = http.cookiejar.CookieJar()
client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
client.addheaders = [("User-Agent", "Mozilla/5.0 SCHUIT bounded security verification")]


def fetch(path, post=False, spoof=1):
    req = urllib.request.Request(
        ORIGIN + path,
        data=b"" if post else None,
        headers={"X-Forwarded-For": "198.51.100." + str(spoof), "Forwarded": "for=198.51.100." + str(spoof)},
    )
    try:
        r = client.open(req, timeout=30)
    except urllib.error.HTTPError as e:
        r = e
    with r:
        return r.status, r.headers, r.read()


fetch("/")
results = {}
for path in ["/wp-login.php", "/tree/login/0"]:
    before = state()
    key = None
    try:
        code, headers, body = fetch(path, True)
        assert code in (200, 302, 303), path + ": first attempt denied"
        after = state()
        changed = [k for k, v in after.items() if v != before.get(k)]
        assert len(changed) == 1, "Expected one server-source counter"
        key = changed[0]
        for n in range(after[key]["count"], 20):
            assert fetch(path, True, n + 2)[0] in (200, 302, 303), "Early rate limit"
        code, headers, body = fetch(path, True, 99)
        assert code == 429 and 0 < int(headers["Retry-After"]) <= 600
        assert fetch(path, True, 100)[0] == 429, "Spoofed forwarding headers bypassed limit"
        assert fetch(path)[0] == 200, "GET login page affected"
        results[path] = {
            "limit_enforced": True,
            "retry_after": True,
            "forged_forwarding_headers_rejected": True,
            "get_unaffected": True,
        }
    finally:
        if key:
            # A lock protects counters for unrelated clients during test cleanup.
            original = json.dumps(before.get(key))
            php(
                "$f=fopen("
                + json.dumps(STATE)
                + ',"c+");flock($f,LOCK_EX);$d=json_decode(stream_get_contents($f),true);$v=json_decode('
                + json.dumps(original)
                + ",true);$k="
                + json.dumps(key)
                + ";if($v===null){unset($d[$k]);}else{$d[$k]=$v;}rewind($f);$s=json_encode($d);fwrite($f,$s);ftruncate($f,strlen($s));fflush($f);flock($f,LOCK_UN);fclose($f);"
            )
file = "/tmp/schuit-concurrency-" + secrets.token_hex(8) + ".json"


def attempt(_):
    return php(
        'require "/opt/schuit-security/login-throttle.php";echo Schuit\\Security\\attempt('
        + json.dumps(file)
        + ',"192.0.2.1","test",100);'
    )


try:
    with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
        values = list(pool.map(attempt, range(40)))
    assert values.count("0") == 20 and values.count("600") == 20
    results["concurrency"] = {"allowed": 20, "blocked": 20}
finally:
    php("@unlink(" + json.dumps(file) + ");")
print(
    json.dumps(
        {"checked_at": datetime.datetime.now(datetime.timezone.utc).isoformat(), "results": results}, indent=2
    )
)
