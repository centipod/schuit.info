"""File-backed, signed S3 GET/PUT; TLS validation on, no redirects or payload buffering."""

import configparser
import datetime
import hashlib
import hmac
import http.client
import json
from pathlib import Path
import re

CHUNK = 1024 * 1024
MAX_OBJECT = 5 * 1024**3  # Single S3 PUT limit; fail before uploading, never silently truncate.
PREFIX = "test/application-files/wordpress/schuit-platform/"
HOST = "centipod-hetzner-postgres-526247032691.s3.eu-west-1.amazonaws.com"


def digest(path):
    with path.open("rb") as stream:
        return hashlib.file_digest(stream, "sha256").hexdigest()


def headers(method, path, size, payload_hash, credentials, now):
    stamp, day = now.strftime("%Y%m%dT%H%M%SZ"), now.strftime("%Y%m%d")
    values = {"host": HOST, "x-amz-date": stamp, "x-amz-content-sha256": payload_hash}
    if method == "PUT":
        values["content-length"] = str(size)
    names = ";".join(sorted(values))
    canonical = "\n".join(
        [method, path, "", "".join(f"{k}:{values[k]}\n" for k in sorted(values)), names, payload_hash]
    )
    scope = f"{day}/eu-west-1/s3/aws4_request"
    message = "\n".join(["AWS4-HMAC-SHA256", stamp, scope, hashlib.sha256(canonical.encode()).hexdigest()])
    key = ("AWS4" + credentials["secret"]).encode()
    for part in (day, "eu-west-1", "s3", "aws4_request"):
        key = hmac.new(key, part.encode(), hashlib.sha256).digest()
    signature = hmac.new(key, message.encode(), hashlib.sha256).hexdigest()
    values["authorization"] = (
        f"AWS4-HMAC-SHA256 Credential={credentials['key']}/{scope}, SignedHeaders={names}, Signature={signature}"
    )
    return values


def transfer_file(method, key, local):
    if (
        method not in {"PUT", "GET"}
        or not key.startswith(PREFIX)
        or ".." in key
        or not re.fullmatch(r"[a-zA-Z0-9/_.-]+", key)
    ):
        raise ValueError("Invalid backup transfer")
    conf = json.loads(Path("/etc/centipod/wordpress/backup.json").read_text())
    if conf["bucket"] != HOST.split(".")[0] or conf["prefix"] != "test/application-files/wordpress/":
        raise ValueError("Unexpected repository")
    parser = configparser.ConfigParser()
    parser.read("/etc/centipod/postgres/test/pgbackrest/pgbackrest.conf")
    credentials = {"key": parser["global"]["repo1-s3-key"], "secret": parser["global"]["repo1-s3-key-secret"]}
    size = local.stat().st_size if method == "PUT" else 0
    if size > MAX_OBJECT:
        raise ValueError("Backup exceeds single-object limit; configure multipart before expansion")
    payload = digest(local) if method == "PUT" else hashlib.sha256(b"").hexdigest()
    signed = headers(
        method, "/" + key, size, payload, credentials, datetime.datetime.now(datetime.timezone.utc)
    )
    connection = http.client.HTTPSConnection(HOST, timeout=300)
    try:
        if method == "PUT":
            with local.open("rb") as stream:
                connection.request(method, "/" + key, stream, signed)
        else:
            connection.request(method, "/" + key, headers=signed)
        response = connection.getresponse()
        if response.status != 200:
            raise RuntimeError("Backup object request rejected: " + str(response.status))
        if method == "GET":
            received = 0
            with local.open("xb") as stream:
                while block := response.read(CHUNK):
                    received += len(block)
                    if received > MAX_OBJECT:
                        raise ValueError("Backup object exceeds size limit")
                    stream.write(block)
        elif response.read(CHUNK):
            raise RuntimeError("Unexpected PUT response")
    finally:
        connection.close()
