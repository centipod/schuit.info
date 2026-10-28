"""Descriptor-relative, bounded queue access; never follow application links."""

import contextlib
import json
import os
import re
import secrets
import stat

MAX_STATUS = 262144
OWNER = 33
STATES = {
    "uploaded",
    "analyzing",
    "ready",
    "queued",
    "backing_up",
    "importing",
    "completed",
    "failed",
    "cancelled",
}


@contextlib.contextmanager
def job_directory(root, identifier):
    if not re.fullmatch(r"[a-f0-9]{32}", identifier):
        raise ValueError("Invalid job identifier")
    parent = os.open(root, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        child = os.open(identifier, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=parent)
        try:
            if os.fstat(child).st_uid != OWNER:
                raise ValueError("Unexpected queue owner")
            yield child
        finally:
            os.close(child)
    finally:
        os.close(parent)


def read_job(root, identifier):
    with job_directory(root, identifier) as directory:
        descriptor = os.open("status.json", os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=directory)
        with os.fdopen(descriptor, "rb") as stream:
            info = os.fstat(stream.fileno())
            if (
                not stat.S_ISREG(info.st_mode)
                or info.st_nlink != 1
                or info.st_uid != OWNER
                or info.st_size > MAX_STATUS
            ):
                raise ValueError("Invalid queue file")
            data = stream.read(MAX_STATUS + 1)
            if len(data) > MAX_STATUS:
                raise ValueError("Oversized queue file")
            job = json.loads(data)
            if not isinstance(job, dict) or job.get("id") != identifier or job.get("state") not in STATES:
                raise ValueError("Invalid queue state")
            return job


def write_job(root, identifier, job):
    data = json.dumps(job, ensure_ascii=False).encode()
    if len(data) > MAX_STATUS or job.get("id") != identifier or job.get("state") not in STATES:
        raise ValueError("Invalid queue state")
    with job_directory(root, identifier) as directory:
        name = ".host-" + secrets.token_hex(16)
        descriptor = os.open(
            name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600, dir_fd=directory
        )
        try:
            with os.fdopen(descriptor, "wb") as stream:
                stream.write(data)
                stream.flush()
                os.fsync(stream.fileno())
            os.rename(name, "status.json", src_dir_fd=directory, dst_dir_fd=directory)
        finally:
            try:
                os.unlink(name, dir_fd=directory)
            except FileNotFoundError:
                pass
