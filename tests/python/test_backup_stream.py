"""Prove old-format compatibility, bounded reads and fail-closed authentication."""

import hashlib
import os
from pathlib import Path
import sys
import tempfile
import unittest
from cryptography.hazmat.primitives.ciphers.aead import AESGCM
from cryptography.exceptions import InvalidTag

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "scripts"))
from backup_stream import MAGIC, encrypt, decrypt


class BackupStream(unittest.TestCase):
    def test_legacy_and_streaming_formats_are_compatible(self):
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            key = os.urandom(32)
            nonce = os.urandom(12)
            content = b"synthetic genealogy only" * 10000
            old = root / "old"
            old.write_bytes(MAGIC + nonce + AESGCM(key).encrypt(nonce, content, MAGIC))
            restored = root / "restored"
            decrypt(old, restored, key)
            self.assertEqual(restored.read_bytes(), content)
            new = root / "new"
            encrypt(restored, new, key)
            body = new.read_bytes()
            offset = len(MAGIC)
            self.assertEqual(
                AESGCM(key).decrypt(body[offset : offset + 12], body[offset + 12 :], MAGIC), content
            )

    def test_tampering_and_truncation_never_publish_plaintext(self):
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            key = os.urandom(32)
            source = root / "source"
            source.write_bytes(b"private synthetic")
            cipher = root / "cipher"
            encrypt(source, cipher, key)
            good = cipher.read_bytes()
            for data in [good[:-1], good[:-1] + bytes([good[-1] ^ 1]), good[:5]]:
                cipher.write_bytes(data)
                with self.assertRaises((InvalidTag, ValueError)):
                    decrypt(cipher, root / "result", key)
                self.assertFalse((root / "result").exists())
                self.assertFalse((root / "result.unverified").exists())

    def test_large_stream_roundtrip(self):
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            source = root / "source"
            key = os.urandom(32)
            with source.open("wb") as stream:
                for _ in range(64):
                    stream.write(b"s" * 1024 * 1024)
            encrypt(source, root / "encrypted", key)
            decrypt(root / "encrypted", root / "restored", key)
            with source.open("rb") as a, (root / "restored").open("rb") as b:
                self.assertEqual(
                    hashlib.file_digest(a, "sha256").digest(), hashlib.file_digest(b, "sha256").digest()
                )
