"""Bounded AES-GCM backups, compatible with existing SCHUIT-BACKUP-v1 archives."""

import os
from pathlib import Path
from cryptography.hazmat.primitives.ciphers import Cipher, algorithms, modes

MAGIC = b"SCHUIT-BACKUP-v1\n"
CHUNK = 1024 * 1024


def encrypt(source: Path, destination: Path, key: bytes) -> None:
    nonce = os.urandom(12)
    cipher = Cipher(algorithms.AES(key), modes.GCM(nonce)).encryptor()
    cipher.authenticate_additional_data(MAGIC)
    with source.open("rb") as incoming, destination.open("xb") as outgoing:
        outgoing.write(MAGIC + nonce)
        while block := incoming.read(CHUNK):
            outgoing.write(cipher.update(block))
        outgoing.write(cipher.finalize())
        outgoing.write(cipher.tag)


def decrypt(source: Path, destination: Path, key: bytes) -> None:
    """Quarantine plaintext until tag verification; remove it on any failure."""
    temporary = destination.with_suffix(destination.suffix + ".unverified")
    try:
        with source.open("rb") as incoming:
            if incoming.read(len(MAGIC)) != MAGIC:
                raise ValueError("Unknown backup format")
            nonce = incoming.read(12)
            remaining = source.stat().st_size - len(MAGIC) - 12 - 16
            if remaining < 0:
                raise ValueError("Truncated backup")
            incoming.seek(-16, 2)
            tag = incoming.read(16)
            incoming.seek(len(MAGIC) + 12)
            cipher = Cipher(algorithms.AES(key), modes.GCM(nonce, tag)).decryptor()
            cipher.authenticate_additional_data(MAGIC)
            with temporary.open("xb") as outgoing:
                os.chmod(temporary, 0o600)
                while remaining:
                    block = incoming.read(min(CHUNK, remaining))
                    if not block:
                        raise ValueError("Truncated backup")
                    outgoing.write(cipher.update(block))
                    remaining -= len(block)
                outgoing.write(cipher.finalize())
                outgoing.flush()
                os.fsync(outgoing.fileno())
        if destination.exists():
            raise ValueError("Restore destination already exists")
        temporary.rename(destination)
    finally:
        temporary.unlink(missing_ok=True)
