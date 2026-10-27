import hashlib
import importlib.util
import io
import json
from pathlib import Path
import tarfile
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('image_identity', Path(__file__).resolve().parents[2] / 'ci/image_identity.py')
policy = importlib.util.module_from_spec(spec)
spec.loader.exec_module(policy)


class ImageIdentity(unittest.TestCase):
    def fixture(self, arch='amd64'):
        config = json.dumps({'architecture': arch, 'os': 'linux'}).encode()
        cid = 'sha256:' + hashlib.sha256(config).hexdigest()
        layers = [{'digest': 'sha256:' + 'a' * 64}]
        manifest = json.dumps({'config': {'digest': cid}, 'layers': layers}).encode()
        mid = 'sha256:' + hashlib.sha256(manifest).hexdigest()
        return {
            'index.json': json.dumps({'manifests': [{'mediaType': 'application/vnd.oci.image.manifest.v1+json', 'digest': mid}]}).encode(),
            'manifest.json': json.dumps([{'Config': 'blobs/sha256/' + cid[7:], 'Layers': ['blobs/sha256/' + 'a' * 64]}]).encode(),
            'blobs/sha256/' + cid[7:]: config,
            'blobs/sha256/' + mid[7:]: manifest,
        }, cid, mid

    def check(self, files, expected, duplicate=False, symlink=False):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / 'candidate.tar.gz'
            with tarfile.open(path, 'w:gz') as archive:
                for name, data in files.items():
                    info = tarfile.TarInfo(name)
                    info.size = len(data)
                    if symlink and name == 'index.json':
                        info.type = tarfile.SYMTYPE
                        info.linkname = '/etc/passwd'
                        info.size = 0
                    archive.addfile(info, io.BytesIO(data))
                    if duplicate and name == 'index.json':
                        archive.addfile(info, io.BytesIO(data))
            return policy.archive_identity(path, expected)

    def test_classic_and_containerd_ids_bound_to_same_archive(self):
        files, cid, mid = self.fixture()
        for expected in (cid, mid):
            self.assertEqual(self.check(files, expected), {'config_id': cid, 'manifest_id': mid})

    def test_wrong_ci_identity_rejected(self):
        files, _, _ = self.fixture()
        with self.assertRaises(ValueError): self.check(files, 'sha256:' + 'b' * 64)

    def test_tampered_manifest_or_config_rejected(self):
        for target in (1, 2):
            files, cid, mid = self.fixture()
            files['blobs/sha256/' + (cid if target == 1 else mid)[7:]] += b' '
            with self.assertRaises(ValueError): self.check(files, cid)

    def test_classic_config_and_layer_substitution_rejected(self):
        for field, value in [('Config', 'other.json'), ('Layers', [])]:
            files, cid, _ = self.fixture()
            classic = json.loads(files['manifest.json']); classic[0][field] = value
            files['manifest.json'] = json.dumps(classic).encode()
            with self.assertRaises(ValueError): self.check(files, cid)

    def test_wrong_architecture_rejected(self):
        files, cid, _ = self.fixture('arm64')
        with self.assertRaises(ValueError): self.check(files, cid)

    def test_ambiguous_manifest_and_unsafe_archive_rejected(self):
        files, cid, _ = self.fixture()
        with self.assertRaises(ValueError): self.check(files, cid, duplicate=True)
        with self.assertRaises(ValueError): self.check(files, cid, symlink=True)
        index = json.loads(files['index.json']); index['manifests'] *= 2
        files['index.json'] = json.dumps(index).encode()
        with self.assertRaises(ValueError): self.check(files, cid)

if __name__ == '__main__': unittest.main()
