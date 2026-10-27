#!/usr/bin/env python3
"""Deploy published source plus verified WP core and Webtrees dependency artifacts.
Run on Hetzner as root: upgrade-security.py <published-sha> <artifact-directory>.
Keeps the old application directories and a private DB rollback checkpoint.
"""
import datetime
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tarfile
import time
import zipfile

os.umask(0o077)
revision, artifacts = sys.argv[1:]
assert re.fullmatch('[a-f0-9]{40}', revision)
artifacts = Path(artifacts)
release = Path('/opt/infra/schuit/releases') / revision
base = Path('/srv/schuit')
assert release.is_dir()
expected = json.loads((release / 'deployment/security-artifacts.json').read_text())
for filename, checksum in expected.items():
    with (artifacts / filename).open('rb') as source:
        assert hashlib.file_digest(source, 'sha256').hexdigest() == checksum, filename + ': hash mismatch'
state = json.loads((base / 'backup-state.json').read_text())['backup']
assert state['success'] and time.time() - state['timestamp'] < 3600, 'A fresh verified S3 backup is required'
assert not (base / 'gedcom-jobs/maintenance').exists(), 'GEDCOM import is running'
for job in (base / 'gedcom-jobs').glob('*/status.json'):
    assert json.loads(job.read_text())['state'] not in ('queued','backing_up','importing','analyzing'), 'Finish active GEDCOM jobs first'

stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
checkpoint = Path('/srv/schuit-upgrade-checkpoints') / stamp
checkpoint.mkdir(parents=True, mode=0o700)
old_release = Path('/opt/apps/schuit').resolve()
(checkpoint / 'previous-release.txt').write_text(str(old_release) + '\n')
shutil.copyfile(base / 'deployment.json', checkpoint / 'deployment.json')

def run(args, **kwargs):
    return subprocess.run(args, check=True, **kwargs)

def compose(path):
    run(['docker','compose','-f',str(path / 'deployment/compose.yaml'),'up','-d','--force-recreate','web'])

def switch(path):
    link = Path('/opt/apps/.schuit-security-next')
    link.unlink(missing_ok=True)
    link.symlink_to(path)
    link.replace('/opt/apps/schuit')

def health():
    for _ in range(90):
        result = subprocess.run(['docker','inspect','--format','{{.State.Health.Status}}','schuit-web'], capture_output=True, text=True)
        if result.stdout.strip() == 'healthy': return
        time.sleep(1)
    raise RuntimeError('SCHUIT failed its application/database health check')

stage = checkpoint / 'stage'
stage.mkdir()
with zipfile.ZipFile(artifacts / 'wordpress-7.1.3.zip') as archive:
    for info in archive.infolist():
        assert not Path(info.filename).is_absolute() and '..' not in Path(info.filename).parts
    archive.extractall(stage)
new_wp = stage / 'wordpress'
checksums = json.loads((artifacts / 'checksums.json').read_text())['checksums']
assert all((new_wp / name).is_file() and hashlib.md5((new_wp / name).read_bytes()).hexdigest() == digest for name,digest in checksums.items())
# Retain content, translations, and mounts; use the new release's default themes.
shutil.copytree(base / 'wordpress/wp-content', new_wp / 'wp-content', dirs_exist_ok=True)
for name in ['akismet']:
    path = new_wp / 'wp-content/plugins' / name
    if path.exists(): shutil.rmtree(path)
# These inactive old themes are retained in the rollback checkpoint/S3 backup.
for name in ['twentytwentythree','twentytwentyfour']:
    path = new_wp / 'wp-content/themes' / name
    if path.exists(): shutil.rmtree(path)
# Restore the new package versions after merging existing wp-content.
with zipfile.ZipFile(artifacts / 'wordpress-7.1.3.zip') as archive:
    for item in archive.infolist():
        if item.filename.startswith('wordpress/wp-content/themes/'):
            archive.extract(item,stage)
(new_wp / 'wp-config.php').write_text('')
for name in ['tree','wp-content/uploads','wp-content/mu-plugins','wp-content/plugins/schuit-gedcom','wp-content/themes/schuit-portal']:
    (new_wp / name).mkdir(parents=True, exist_ok=True)
new_wt = stage / 'webtrees'
shutil.copytree(base / 'webtrees', new_wt)
shutil.rmtree(new_wt / 'vendor')
with tarfile.open(artifacts / 'webtrees-vendor.tar.gz') as archive:
    for item in archive:
        assert item.name == 'vendor' or item.name.startswith('vendor/')
        assert not Path(item.name).is_absolute() and '..' not in Path(item.name).parts
        assert item.isdir() or item.isfile(), 'Links are not allowed in the vendor artifact'
    archive.extractall(new_wt, filter='data')
for root in [new_wp,new_wt]:
    for path in [root,*root.rglob('*')]:
        assert not path.is_symlink(), 'Unexpected application symlink'
        path.chmod(0o755 if path.is_dir() else 0o644)
        os.chown(path,33,33)

run(['docker','build','-f',str(release/'docker/hetzner.Dockerfile'),'-t','centipod-schuit:php8.3.36-20261027-security',str(release)])
run(['docker','run','--rm','--entrypoint','apache2ctl','centipod-schuit:php8.3.36-20261027-security','-t'])
lock = (base / 'gedcom-jobs/host-worker.lock').open('a')
fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
swapped = []
try:
    run(['systemctl','stop','schuit-gedcom.timer'])
    # Stop app writes before taking the exact database rollback checkpoint.
    run(['docker','stop','schuit-web'])
    with (checkpoint / 'wordpress.sql').open('wb') as output:
        run(['docker','exec','wordpress-mariadb','mariadb-dump','--defaults-extra-file=/run/secrets/root-client.cnf','--single-transaction','--routines','--triggers','wp_schuit'], stdout=output)
    for name, fresh in [('wordpress',new_wp),('webtrees',new_wt)]:
        (base / name).rename(checkpoint / name)
        fresh.rename(base / name)
        swapped.append(name)
    switch(release)
    compose(release)
    run(['docker','exec','schuit-web','wp','--path=/var/www/html','core','update-db'])
    health()
    version = subprocess.check_output(['docker','exec','schuit-web','wp','--path=/var/www/html','core','version'],text=True).strip()
    assert version == '7.1.3'
    run(['docker','exec','schuit-web','php','/opt/schuit-scripts/audit-runtime.php'])
    manifest = json.loads((base / 'deployment.json').read_text())
    image = subprocess.check_output(['docker','inspect','--format','{{.Image}}','schuit-web'],text=True).strip()
    manifest.update(source_commit=revision, wordpress=version, webtrees='2.2.6', webtrees_dependency_patch='2026-10-27', theme='0.3.0',image=image,deployed_at=datetime.datetime.now(datetime.timezone.utc).isoformat())
    (base / 'deployment.json').write_text(json.dumps(manifest,indent=2)+'\n')
except Exception:
    subprocess.run(['docker','stop','schuit-web'])
    for name in reversed(swapped):
        (base / name).rename(checkpoint / (name+'-failed'))
        (checkpoint / name).rename(base / name)
    if (checkpoint / 'wordpress.sql').exists():
        with (checkpoint / 'wordpress.sql').open('rb') as source:
            run(['docker','exec','-i','wordpress-mariadb','mariadb','--defaults-extra-file=/run/secrets/root-client.cnf','wp_schuit'],stdin=source)
    switch(old_release)
    shutil.copyfile(checkpoint / 'deployment.json',base / 'deployment.json')
    compose(old_release)
    raise
finally:
    fcntl.flock(lock,fcntl.LOCK_UN)
    lock.close()
    run(['systemctl','start','schuit-gedcom.timer'])
print('Security upgrade complete. Private rollback checkpoint: '+str(checkpoint))
