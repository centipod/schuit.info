#!/usr/bin/env python3
"""Host-side queue runner: fixed commands, private files, backup before import."""
import fcntl
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import time

os.umask(0o077)
ROOT = Path('/srv/schuit/gedcom-jobs')


def save(folder, job):
    job['updated_at'] = time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime())
    temp = folder / '.host-status.tmp'
    temp.write_text(json.dumps(job, ensure_ascii=False, indent=2))
    os.chown(temp, 33, 33)
    temp.replace(folder / 'status.json')


def php(action, identifier):
    return subprocess.run(['docker', 'exec', 'schuit-web', 'php', '/opt/schuit-scripts/gedcom-worker.php', action, identifier],
                          capture_output=True, timeout=2100).returncode


def run():
    with (ROOT / 'host-worker.lock').open('a') as runner:
        try:
            fcntl.flock(runner, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            return
        for path in sorted(ROOT.glob('*/status.json')):
            folder = path.parent
            if not re.fullmatch('[a-f0-9]{32}', folder.name):
                continue
            job = json.loads(path.read_text())
            state = job['state']
            if state in ('uploaded', 'analyzing'):
                php('analyze', folder.name)
                return
            if state == 'importing':
                result = php('recover', folder.name)
                if result != 75:
                    (ROOT / 'maintenance').unlink(missing_ok=True)
                return
            if state in ('queued', 'backing_up'):
                with (ROOT / 'webtrees.lock').open('a') as lock:
                    try:
                        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
                    except BlockingIOError:
                        return  # An existing webtrees request/worker must finish first.
                    (ROOT / 'maintenance').touch(mode=0o600)
                    os.chown(ROOT / 'maintenance', 33, 33)
                    try:
                        # Worker is the only writer for queued/running jobs. Browser
                        # actions accept READY only, so duplicate confirmations are safe.
                        job['state'] = 'backing_up'
                        job['progress'] = 'Versleutelde back-up van bestanden en databases naar S3…'
                        save(folder, job)
                        backup = subprocess.run(['python3', '/opt/apps/schuit/scripts/backup-hetzner.py', 'backup'],
                                                capture_output=True, timeout=1200)
                        if backup.returncode:
                            raise RuntimeError('backup failed')
                        report = json.loads(backup.stdout)
                        if not report.get('success'):
                            raise RuntimeError('backup not verified')
                        job['backup_key'] = report['archive_key']
                        save(folder, job)
                        # Maintenance marker blocks new requests during the lock handoff;
                        # PHP then holds this lock throughout its DB transaction.
                        fcntl.flock(lock, fcntl.LOCK_UN)
                        php('import', folder.name)
                        subprocess.run(['docker', 'exec', 'schuit-web', 'php', '/opt/schuit-scripts/update-metrics.php'],
                                       capture_output=True, timeout=120)
                    except Exception:
                        latest = json.loads(path.read_text())
                        if latest['state'] == 'backing_up':
                            latest['state'] = 'failed'
                            latest['error'] = 'De back-up of verwerking kon niet starten. Er zijn geen gegevens vervangen. Neem contact op met de beheerder.'
                            save(folder, latest)
                        # An importing child may still own the PHP/DB locks. The next
                        # tick recovers its committed marker or records a safe failure.
                        raise
                    finally:
                        (ROOT / 'maintenance').unlink(missing_ok=True)
                return
            if state in ('completed', 'cancelled', 'failed', 'ready') and time.time() - path.stat().st_mtime > 30 * 86400:
                with (ROOT / 'queue.lock').open('a') as queue:
                    fcntl.flock(queue, fcntl.LOCK_EX)
                    with (folder / 'lock').open('a') as joblock:
                        fcntl.flock(joblock, fcntl.LOCK_EX)
                        if json.loads(path.read_text())['state'] == state:
                            shutil.rmtree(folder)


if __name__ == '__main__':
    run()
