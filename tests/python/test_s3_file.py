"""Transfers use bounded file bodies and never follow a repository redirect."""

import datetime
import io
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "scripts"))
import s3_file


class FileTransfer(unittest.TestCase):
    def test_signing_is_payload_bound(self):
        now = datetime.datetime(2026, 10, 28, tzinfo=datetime.timezone.utc)
        cred = {"key": "synthetic", "secret": "not-a-secret"}
        a = s3_file.headers("PUT", "/test", 7, "a" * 64, cred, now)
        b = s3_file.headers("PUT", "/test", 7, "b" * 64, cred, now)
        self.assertNotEqual(a["authorization"], b["authorization"])
        self.assertEqual(a["content-length"], "7")
        self.assertEqual(a["host"], s3_file.HOST)
        self.assertNotIn(cred["secret"], str(a))

    def test_repository_paths_are_restricted_before_credentials_are_read(self):
        for method, key in [
            ("DELETE", s3_file.PREFIX + "x"),
            ("GET", "another/x"),
            ("PUT", s3_file.PREFIX + "../x"),
        ]:
            with self.assertRaises(ValueError):
                s3_file.transfer_file(method, key, Path("/unused"))

    def test_put_streams_and_get_is_bounded_and_redirects_fail(self):
        class Response:
            status = 200

            def __init__(self, data):
                self.data = io.BytesIO(data)

            def read(self, count):
                if count > s3_file.CHUNK:
                    raise AssertionError("Unbounded read")
                return self.data.read(count)

        class Connection:
            def __init__(self, *args, **kwargs):
                pass

            def request(self, method, path, body=None, headers=None):
                if method == "PUT":
                    if not hasattr(body, "read"):
                        raise AssertionError("Buffered PUT")
                    while body.read(s3_file.CHUNK):
                        pass

            def getresponse(self):
                return response

            def close(self):
                pass

        config = (
            '{"bucket":"centipod-hetzner-postgres-526247032691","prefix":"test/application-files/wordpress/"}'
        )

        def credentials(parser, path):
            parser.read_dict({"global": {"repo1-s3-key": "synthetic", "repo1-s3-key-secret": "synthetic"}})

        with (
            tempfile.TemporaryDirectory() as d,
            patch.object(Path, "read_text", return_value=config),
            patch.object(s3_file.configparser.ConfigParser, "read", credentials),
            patch.object(s3_file.http.client, "HTTPSConnection", Connection),
        ):
            root = Path(d)
            source = root / "source"
            source.write_bytes(b"fixture")
            response = Response(b"")
            s3_file.transfer_file("PUT", s3_file.PREFIX + "x", source)
            response = Response(b"download")
            s3_file.transfer_file("GET", s3_file.PREFIX + "x", root / "result")
            self.assertEqual((root / "result").read_bytes(), b"download")
            response = Response(b"")
            response.status = 307
            with self.assertRaises(RuntimeError):
                s3_file.transfer_file("GET", s3_file.PREFIX + "x", root / "redirect")
            self.assertFalse((root / "redirect").exists())
