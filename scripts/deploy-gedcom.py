#!/usr/bin/env python3
"""Deploy an already-published SCHUIT release, preserving all application data."""
import datetime
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import time

revision = sys.argv[1]
assert re.fullmatch('[a-f0-9]{40}', revision)
release = Path('/opt/infra/schuit/releases') / revision
assert (release / 'wordpress-plugins/schuit-gedcom/schuit-gedcom.php').is_file()
jobs = Path('/srv/schuit/gedcom-jobs')
jobs.mkdir(mode=0o700, exist_ok=True)
os.chown(jobs, 33, 33)
for name in ['queue.lock', 'webtrees.lock', 'worker.lock']:
    path = jobs / name
    path.touch(mode=0o600, exist_ok=True)
    os.chown(path, 33, 33)
Path('/srv/schuit/wordpress/wp-content/plugins/schuit-gedcom').mkdir(exist_ok=True)
old = Path('/opt/apps/schuit').resolve()
temporary = Path('/opt/apps/.schuit-next')
temporary.unlink(missing_ok=True)
temporary.symlink_to(release)
temporary.replace('/opt/apps/schuit')
try:
    subprocess.run(['docker', 'compose', '-f', str(release / 'deployment/compose.yaml'), 'up', '-d', '--force-recreate', 'web'], check=True)
    for _ in range(60):
        health = subprocess.run(['docker', 'inspect', '--format', '{{.State.Health.Status}}', 'schuit-web'], capture_output=True, text=True)
        if health.stdout.strip() == 'healthy':
            break
        time.sleep(1)
    else:
        raise RuntimeError('New release is not healthy')
    subprocess.run(['docker', 'exec', 'schuit-web', 'wp', '--path=/var/www/html', 'plugin', 'activate', 'schuit-gedcom'], check=True)
    for name in ['schuit-gedcom.service', 'schuit-gedcom.timer']:
        shutil.copyfile(release / 'deployment' / name, Path('/etc/systemd/system') / name)
    subprocess.run(['systemctl', 'daemon-reload'], check=True)
    subprocess.run(['systemctl', 'enable', '--now', 'schuit-gedcom.timer'], check=True)
    manifest_path = Path('/srv/schuit/deployment.json')
    manifest = json.loads(manifest_path.read_text())
    image = subprocess.check_output(['docker', 'inspect', '--format', '{{.Image}}', 'schuit-web'], text=True).strip()
    manifest.update(source_commit=revision, image=image, deployed_at=datetime.datetime.now(datetime.timezone.utc).isoformat(), gedcom_plugin='1.0.0')
    manifest_path.write_text(json.dumps(manifest, indent=2) + '\n')
except Exception:
    temporary.symlink_to(old)
    temporary.replace('/opt/apps/schuit')
    subprocess.run(['docker', 'compose', '-f', '/opt/apps/schuit/deployment/compose.yaml', 'up', '-d', '--force-recreate', 'web'], check=True)
    raise
print('Published GEDCOM workflow deployed; existing genealogical data preserved.')
