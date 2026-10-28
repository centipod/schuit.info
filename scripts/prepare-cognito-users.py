#!/usr/bin/env python3
"""Provision existing identities without email delivery; emit private, disabled config.

Run with a reviewed export-auth-accounts.php JSON, once the SCHUIT stack exists.
Passwords/hashes are never exported. New users recover their password by email
on first sign-in, then enroll TOTP. Existing Cognito passwords are not reset.
"""

import argparse
import json
import os
import re
import secrets
import subprocess
import tempfile
from pathlib import Path

os.umask(0o077)


def aws(service, operation, **payload):
    with tempfile.NamedTemporaryFile(mode="w", suffix=".json") as f:
        json.dump(payload, f)
        f.flush()
        p = subprocess.run(
            [
                "aws",
                "--profile",
                "centipod_prd",
                "--region",
                "eu-west-1",
                service,
                operation,
                "--cli-input-json",
                "file://" + f.name,
            ],
            capture_output=True,
            text=True,
            timeout=60,
        )
    if p.returncode:
        if "UserNotFoundException" in p.stderr:
            return None
        raise RuntimeError("AWS operation failed: " + operation)  # never echo sensitive payload/errors
    return json.loads(p.stdout or "{}")


def mappings(accounts):
    users = {}
    for app, rows in accounts.items():
        if app not in ["wordpress", "webtrees"]:
            raise ValueError("Unknown application")
        for row in rows:
            if app == "webtrees" and not (row["approved"] and row["verified"]):
                continue
            if app == "wordpress" and not row["roles"]:
                continue
            email = row["email"].strip().lower()
            if not re.fullmatch(r"[^\s@]+@[^\s@]+\.[^\s@]+", email):
                raise ValueError("Invalid source email")
            if not isinstance(row["id"], int) or row["id"] < 1:
                raise ValueError("Invalid source ID")
            m = users.setdefault(email, {"email": email})
            if app in m:
                raise ValueError("Duplicate email within application; review required")
            m[app] = row["id"]
    if not users:
        raise ValueError("No approved accounts")
    return users


def main():
    p = argparse.ArgumentParser()
    p.add_argument("--inventory", required=True, type=Path)
    p.add_argument("--output", required=True, type=Path)
    a = p.parse_args()
    if a.output.exists():
        raise ValueError("Refuse to overwrite existing mapping")
    users = mappings(json.loads(a.inventory.read_text()))
    if aws("sts", "get-caller-identity")["Account"] != "526247032691":
        raise ValueError("Wrong AWS account")
    stack = aws("cloudformation", "describe-stacks", StackName="schuit-review-auth")["Stacks"][0]
    if stack["StackStatus"] not in ["CREATE_COMPLETE", "UPDATE_COMPLETE"]:
        raise ValueError("Stack not ready")
    outputs = {x["OutputKey"]: x["OutputValue"] for x in stack["Outputs"]}
    pool = outputs["PoolId"]
    mapped = {}
    created = 0
    for email, m in users.items():
        user = aws("cognito-idp", "admin-get-user", UserPoolId=pool, Username=email)
        if user is None:
            aws(
                "cognito-idp",
                "admin-create-user",
                UserPoolId=pool,
                Username=email,
                UserAttributes=[
                    {"Name": "email", "Value": email},
                    {"Name": "email_verified", "Value": "true"},
                ],
                MessageAction="SUPPRESS",
            )
            user = aws("cognito-idp", "admin-get-user", UserPoolId=pool, Username=email)
            created += 1
        # A random undisclosed permanent password enables self-service recovery;
        # no native password is copied and no unsolicited invitation is sent.
        if user["UserStatus"] == "FORCE_CHANGE_PASSWORD":
            aws(
                "cognito-idp",
                "admin-set-user-password",
                UserPoolId=pool,
                Username=email,
                Password="Aa1!" + secrets.token_urlsafe(48),
                Permanent=True,
            )
            user = aws("cognito-idp", "admin-get-user", UserPoolId=pool, Username=email)
        attrs = {x["Name"]: x["Value"] for x in user["UserAttributes"]}
        if (
            not user["Enabled"]
            or attrs.get("email", "").lower() != email
            or attrs.get("email_verified") != "true"
        ):
            raise ValueError("Identity not approved for linking")
        mapped[attrs["sub"]] = m
    cfg = {
        "enabled": False,
        "origin": "https://schuit.centipod.ai",
        "domain": outputs["Domain"],
        "issuer": outputs["Issuer"],
        "client_id": outputs["ClientId"],
        "users": mapped,
    }
    with a.output.open("x") as f:
        json.dump(cfg, f, indent=2)
        f.write("\n")
    print(json.dumps({"created": created, "mapped": len(mapped), "enabled": False, "emails_sent": 0}))


if __name__ == "__main__":
    main()
