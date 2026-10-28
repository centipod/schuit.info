#!/usr/bin/env python3
"""Exercise preview sign-in with short-lived accounts; never log passwords/cookies."""

import http.cookiejar
import json
import re
import secrets
import subprocess
import time
import urllib.parse
import urllib.request
from pathlib import Path

SITES = ["schuit"]


def wp(site, *args):
    result = subprocess.run(
        [
            "docker",
            "exec",
            "schuit-web",
            "wp",
            "--path=/var/www/html",
            "--skip-plugins",
            "--skip-themes",
            *args,
        ],
        text=True,
        capture_output=True,
        timeout=45,
    )
    if result.returncode:
        raise RuntimeError(site + ": WordPress command failed (private output withheld)")
    return result.stdout.strip()


class SameOriginRedirect(urllib.request.HTTPRedirectHandler):
    def __init__(self, host):
        self.host = host

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        target = urllib.parse.urlsplit(newurl)
        if target.scheme != "https" or target.netloc != self.host:
            raise RuntimeError("Refusing a login redirect outside the preview origin")
        return super().redirect_request(req, fp, code, msg, headers, newurl)


def check(site):
    host = site + ".centipod.ai"
    origin = "https://" + host
    assert wp(site, "option", "get", "home") == origin
    assert wp(site, "eval", "echo wp_get_environment_type();") == "staging"
    assert wp(site, "eval", 'echo has_filter("pre_wp_mail") !== false ? "blocked" : "unsafe";') == "blocked"
    count = int(wp(site, "user", "list", "--format=count"))
    login = "migrationcheck_" + secrets.token_hex(10)
    account = None
    result = {"site": site, "https_login": False, "dashboard": False, "test_account_removed": False}
    try:
        # The password is generated inside PHP and captured privately; it never enters argv.
        code = """$p=wp_generate_password(48,true,true);
        $u=wp_insert_user(['user_login'=>%s,'user_pass'=>$p,
        'user_email'=>%s,'role'=>'administrator']);
        if(is_wp_error($u)){throw new Exception('Test account creation failed');}
        echo json_encode(['id'=>$u,'password'=>$p]);""" % (
            json.dumps(login),
            json.dumps(login + "@example.invalid"),
        )
        private = wp(site, "eval", code)
        account = json.loads(private)
        del private
        jar = http.cookiejar.CookieJar()
        opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(jar), SameOriginRedirect(host)
        )
        with opener.open(origin + "/wp-login.php", timeout=30) as response:
            assert response.status == 200
            response.read()
        data = urllib.parse.urlencode(
            {
                "log": login,
                "pwd": account["password"],
                "wp-submit": "Log In",
                "redirect_to": origin + "/wp-admin/",
                "testcookie": "1",
            }
        ).encode()
        with opener.open(urllib.request.Request(origin + "/wp-login.php", data=data), timeout=45) as response:
            body = response.read()
            result["admin_email_reminder"] = "confirm_admin_email" in urllib.parse.parse_qs(
                urllib.parse.urlsplit(response.url).query
            ).get("action", [])
            if result["admin_email_reminder"] and any(c.name.startswith("wordpress_logged_in_") for c in jar):
                # A post-login reminder is not an authentication failure. Do not acknowledge
                # or change the owner's email; use the authenticated dashboard route.
                with opener.open(origin + "/wp-admin/", timeout=45) as dashboard:
                    body = dashboard.read()
                    assert dashboard.status == 200 and urllib.parse.urlsplit(dashboard.url).path.startswith(
                        "/wp-admin"
                    )
            elif response.status != 200 or not urllib.parse.urlsplit(response.url).path.startswith(
                "/wp-admin"
            ):
                error = re.search(rb"<div[^>]+id=[\"\']login_error[\"\'][^>]*>(.*?)</div>", body, re.S)
                visible = re.sub(rb"<(script|style)\b[^>]*>.*?</\1>", b"", body, flags=re.S | re.I)
                diagnostic = re.sub(rb"<[^>]*>", b" ", error.group(1) if error else visible).decode(
                    errors="replace"
                )
                diagnostic = (
                    " ".join(diagnostic.split())
                    .replace(account["password"], "[redacted]")
                    .replace(login, "[test account]")[:1800]
                )
                diagnostic += "; cookie names=" + ",".join(c.name for c in jar)
                raise RuntimeError(
                    site + ": login ended at " + urllib.parse.urlsplit(response.url).path + ": " + diagnostic
                )
        assert any(c.name.startswith("wordpress_logged_in_") for c in jar), "No authenticated session"
        assert b"wpadminbar" in body and b'id="loginform"' not in body, "Dashboard was not authenticated"
        result.update(https_login=True, dashboard=True)
    finally:
        if account is not None:
            uid = str(int(account["id"]))
            assert wp(site, "user", "get", uid, "--field=user_login") == login
            wp(site, "user", "delete", uid, "--yes")
            result["test_account_removed"] = True
        assert int(wp(site, "user", "list", "--format=count")) == count, "User count changed during test"
    return result


if __name__ == "__main__":
    report = {"checked_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()), "sites": []}
    for site in SITES:
        report["sites"].append(check(site))
    Path("/srv/schuit/login-acceptance.json").write_text(json.dumps(report, indent=2) + "\n")
    print(json.dumps(report, indent=2))
