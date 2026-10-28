"""Bind classic Docker config IDs and containerd manifest IDs to one archive."""

import hashlib
import json
import re
import tarfile


def archive_identity(path, expected_id):
    with tarfile.open(path, "r:*") as archive:
        members = archive.getmembers()
        names = [m.name for m in members]
        if len(names) != len(set(names)):
            raise ValueError("Duplicate image archive members")

        def read(name):
            member = archive.getmember(name)
            if not member.isfile() or member.size > 1024 * 1024:
                raise ValueError("Invalid image metadata member")
            return archive.extractfile(member).read()

        def blob(digest):
            if not isinstance(digest, str) or not re.fullmatch(r"sha256:[a-f0-9]{64}", digest):
                raise ValueError("Invalid image digest")
            raw = read("blobs/sha256/" + digest[7:])
            if "sha256:" + hashlib.sha256(raw).hexdigest() != digest:
                raise ValueError("Image metadata digest mismatch")
            return json.loads(raw)

        index = json.loads(read("index.json"))
        descriptors = index.get("manifests", [])
        if (
            len(descriptors) != 1
            or descriptors[0].get("mediaType") != "application/vnd.oci.image.manifest.v1+json"
        ):
            raise ValueError("Exactly one OCI image manifest required")
        manifest_id = descriptors[0]["digest"]
        manifest = blob(manifest_id)
        config_id = manifest["config"]["digest"]
        config = blob(config_id)
        if config.get("architecture") != "amd64" or config.get("os") != "linux":
            raise ValueError("Linux amd64 candidate required")
        classic = json.loads(read("manifest.json"))
        if len(classic) != 1 or classic[0]["Config"] != "blobs/sha256/" + config_id[7:]:
            raise ValueError("Classic and OCI configuration mismatch")
        if classic[0]["Layers"] != ["blobs/sha256/" + layer["digest"][7:] for layer in manifest["layers"]]:
            raise ValueError("Classic and OCI layers mismatch")
        if expected_id not in (config_id, manifest_id):
            raise ValueError("CI image ID is not bound to this archive")
        return {"config_id": config_id, "manifest_id": manifest_id}
