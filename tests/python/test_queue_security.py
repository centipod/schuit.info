"""Queue and broker regression cases use only disposable local data."""

import importlib.util
import json
import os
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch

SCRIPTS = Path(__file__).resolve().parents[2] / "scripts"
sys.path.insert(0, str(SCRIPTS))
import safe_queue  # noqa: E402 - import the source under test after adding its directory

spec = importlib.util.spec_from_file_location("broker", SCRIPTS / "worker-broker.py")
broker = importlib.util.module_from_spec(spec)
spec.loader.exec_module(broker)

worker_spec = importlib.util.spec_from_file_location("queue_worker", SCRIPTS / "gedcom-worker.py")
worker = importlib.util.module_from_spec(worker_spec)
worker_spec.loader.exec_module(worker)


class QueueSecurity(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.identifier = "a" * 32
        self.folder = self.root / self.identifier
        self.folder.mkdir()
        self.owner = patch.object(safe_queue, "OWNER", os.getuid())
        self.owner.start()
        self.addCleanup(self.owner.stop)
        self.job = {"id": self.identifier, "state": "queued"}

    def test_inaccessible_queue_is_not_treated_as_empty(self):
        with patch.object(worker.os, "open", side_effect=PermissionError("denied")):
            with self.assertRaises(PermissionError):
                worker.require_queue_access()

    def test_empty_accessible_queue_is_valid(self):
        with patch.object(worker, "ROOT", self.root):
            worker.require_queue_access()

    def test_queue_root_symlink_is_rejected(self):
        link = self.root / "link"
        link.symlink_to(self.folder, target_is_directory=True)
        with patch.object(worker, "ROOT", link):
            with self.assertRaises(OSError):
                worker.require_queue_access()

    def test_original_symlink_attack_cannot_overwrite_external_file(self):
        victim = self.root / "victim"
        victim.write_text("unchanged")
        (self.folder / ".host-status.tmp").symlink_to(victim)
        (self.folder / "status.json").symlink_to(victim)
        safe_queue.write_job(self.root, self.identifier, self.job)
        self.assertEqual(victim.read_text(), "unchanged")
        self.assertEqual(safe_queue.read_job(self.root, self.identifier), self.job)
        self.assertFalse((self.folder / "status.json").is_symlink())

    def test_parent_symlink_is_rejected(self):
        self.folder.rmdir()
        other = self.root / "other"
        other.mkdir()
        self.folder.symlink_to(other, target_is_directory=True)
        with self.assertRaises(OSError):
            safe_queue.write_job(self.root, self.identifier, self.job)
        self.assertEqual(list(other.iterdir()), [])

    def test_malformed_oversized_and_hardlinked_status_rejected(self):
        target = self.folder / "status.json"
        for body in [
            "no-json",
            "{}",
            json.dumps({"id": self.identifier, "state": "evil"}),
            "x" * (safe_queue.MAX_STATUS + 1),
        ]:
            target.write_text(body)
            with self.assertRaises(ValueError):
                safe_queue.read_job(self.root, self.identifier)
        target.write_text(json.dumps(self.job))
        os.link(target, self.root / "alias")
        with self.assertRaises(ValueError):
            safe_queue.read_job(self.root, self.identifier)

    def test_broker_has_no_arbitrary_command_or_path(self):
        for request in [
            {"action": "exec", "job": "id"},
            {"action": "import", "job": "../../etc/passwd"},
            {"action": "backup", "job": "", "command": "bash"},
        ]:
            with self.assertRaises(ValueError):
                broker.command(request)
        args, _ = broker.command({"action": "import", "job": self.identifier})
        self.assertIn("33:33", args)
        self.assertEqual(args[-1], self.identifier)

    def test_parent_replacement_cannot_redirect_atomic_write(self):
        original_open = os.open
        moved = self.root / "original"
        outside = self.root / "outside"
        outside.mkdir()

        def swap_after_open(path, flags, *args, **kwargs):
            fd = original_open(path, flags, *args, **kwargs)
            if path == self.identifier:
                self.folder.rename(moved)
                self.folder.symlink_to(outside, target_is_directory=True)
            return fd

        with patch.object(safe_queue.os, "open", side_effect=swap_after_open):
            safe_queue.write_job(self.root, self.identifier, self.job)
        self.assertEqual(list(outside.iterdir()), [])
        self.assertEqual(json.loads((moved / "status.json").read_text()), self.job)

    def test_interrupted_write_leaves_existing_status_intact(self):
        safe_queue.write_job(self.root, self.identifier, self.job)
        changed = {**self.job, "state": "completed"}
        with patch.object(safe_queue.os, "rename", side_effect=OSError("interrupted")):
            with self.assertRaises(OSError):
                safe_queue.write_job(self.root, self.identifier, changed)
        self.assertEqual(safe_queue.read_job(self.root, self.identifier), self.job)
        self.assertEqual([p.name for p in self.folder.iterdir()], ["status.json"])
