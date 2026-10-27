#!/usr/bin/env python3
"""Root-owned, forced-command receiver. No shell, arbitrary paths or bypass flags.

The workflow supplies a short-lived, read-only GITHUB_TOKEN on stdin. The host
downloads the candidate artifact from that verified run itself, then rescans it.
It never trusts an image supplied by the SSH client or stores a GitHub token.
"""
import datetime
import fcntl
import gzip
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tarfile
import tempfile
import time
import urllib.request
import urllib.error
import zipfile
from deploy_policy import REPOSITORY, parse_command, verify_run, verify_image
from image_identity import archive_identity

BASE = Path('/srv/schuit')
RELEASES = Path('/opt/infra/schuit/releases')

def run(args, **kwargs):
    return subprocess.run(args, check=True, **kwargs)

def output(args):
    return subprocess.check_output(args, text=True).strip()

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None

def api(path, token):
    url = 'https://api.github.com/repos/' + REPOSITORY + '/' + path
    request = urllib.request.Request(url, headers={'Authorization': 'Bearer ' + token, 'Accept': 'application/vnd.github+json', 'User-Agent': 'schuit-release-gate'})
    return request

def json_api(path, token):
    with urllib.request.urlopen(api(path, token), timeout=30) as response:
        return json.load(response)

def download(url, destination, limit):
    if not url.startswith('https://'):
        raise ValueError('HTTPS download required')
    size = 0
    with urllib.request.urlopen(url, timeout=120) as response, destination.open('wb') as target:
        while block := response.read(1024 * 1024):
            size += len(block)
            if size > limit:
                raise ValueError('Download exceeds release size limit')
            target.write(block)

def artifact(run_id, token, work):
    listing = json_api('actions/runs/' + run_id + '/artifacts?per_page=100', token)
    if listing['total_count'] != len(listing['artifacts']):
        raise ValueError('Incomplete artifact list')
    candidates = [a for a in listing['artifacts'] if a['name'] == 'candidate-image' and not a['expired']]
    if len(candidates) != 1:
        raise ValueError('A unique, unexpired candidate artifact is required')
    candidate = candidates[0]
    # Do not forward the bearer token when GitHub redirects to artifact storage.
    try:
        urllib.request.build_opener(NoRedirect).open(api('actions/artifacts/' + str(candidate['id']) + '/zip', token), timeout=30)
    except urllib.error.HTTPError as error:
        if error.code != 302:
            raise
        location = error.headers['Location']
    else:
        raise ValueError('Expected signed artifact download redirect')
    archive = work / 'candidate.zip'
    download(location, archive, 2 * 1024**3)
    digest = 'sha256:' + hashlib.file_digest(archive.open('rb'), 'sha256').hexdigest()
    if candidate.get('digest') != digest:
        raise ValueError('GitHub artifact digest mismatch')
    with zipfile.ZipFile(archive) as package:
        if package.namelist() != ['candidate.tar.gz'] or package.getinfo('candidate.tar.gz').file_size > 2 * 1024**3:
            raise ValueError('Unexpected candidate artifact contents')
        package.extract('candidate.tar.gz', work)
    return work / 'candidate.tar.gz'

def source(revision, work):
    archive = work / 'source.tar.gz'
    download('https://codeload.github.com/' + REPOSITORY + '/tar.gz/' + revision, archive, 128 * 1024**2)
    target = work / 'source'
    target.mkdir()
    with tarfile.open(archive) as package:
        members = package.getmembers()
        if sum(m.size for m in members) > 512 * 1024**2:
            raise ValueError('Source archive is too large')
        if any(not (m.isfile() or m.isdir()) for m in members):
            raise ValueError('Source archive must contain only regular files and directories')
        package.extractall(target, filter='data')
    roots = list(target.iterdir())
    if len(roots) != 1 or not roots[0].is_dir():
        raise ValueError('Unexpected source archive layout')
    return roots[0]

def switch(path):
    link = Path('/opt/apps/.schuit-ci-next')
    link.unlink(missing_ok=True)
    link.symlink_to(path)
    link.replace('/opt/apps/schuit')

def compose(release):
    run(['docker', 'compose', '-f', str(release / 'deployment/compose.yaml'), 'up', '-d', '--force-recreate', '--wait', '--wait-timeout', '120', 'web'])

def activate(release, revision, image_id, run_id):
    backup = json.loads((BASE / 'backup-state.json').read_text())['backup']
    if not backup['success'] or time.time() - backup['timestamp'] > 3600:
        run(['systemctl', 'start', 'schuit-backup.service'])
        backup = json.loads((BASE / 'backup-state.json').read_text())['backup']
        if not backup['success'] or time.time() - backup['timestamp'] > 3600:
            raise ValueError('A successful S3 backup less than an hour old is required')
    old = Path('/opt/apps/schuit').resolve()
    manifest = (BASE / 'deployment.json').read_text()
    before = json.loads(output(['docker', 'exec', 'schuit-web', 'php', '/opt/schuit-scripts/audit-runtime.php']))
    with (BASE / 'gedcom-jobs/host-worker.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        if (BASE / 'gedcom-jobs/maintenance').exists():
            raise ValueError('GEDCOM maintenance is active')
        for job in (BASE / 'gedcom-jobs').glob('*/status.json'):
            if json.loads(job.read_text())['state'] in {'queued', 'backing_up', 'importing', 'analyzing'}:
                raise ValueError('GEDCOM work is active; retry after it finishes')
        run(['systemctl', 'stop', 'schuit-gedcom.timer'])
        try:
            switch(release)
            compose(release)
            after = json.loads(output(['docker', 'exec', 'schuit-web', 'php', '/opt/schuit-scripts/audit-runtime.php']))
            for version in ('wordpress', 'php'):
                before.pop(version, None)
                after.pop(version, None)
            if before != after:
                raise ValueError('Application/database inventory changed unexpectedly')
            run(['curl', '--fail', '--silent', '--show-error', '--max-time', '30', 'https://schuit.centipod.ai/'], stdout=subprocess.DEVNULL)
            data = json.loads(manifest)
            data.update(source_commit=revision, image=image_id, ci_run=run_id, previous_release=str(old), deployed_at=datetime.datetime.now(datetime.timezone.utc).isoformat())
            (BASE / 'deployment.json').write_text(json.dumps(data, indent=2) + '\n')
        except Exception:
            switch(old)
            compose(old)
            (BASE / 'deployment.json').write_text(manifest)
            raise
        finally:
            run(['systemctl', 'start', 'schuit-gedcom.timer'])

def main():
    os.umask(0o077)
    revision, image_id, run_id = parse_command(sys.argv[1] if len(sys.argv) == 2 else '')
    token = sys.stdin.readline(4096).strip()
    if not token or len(token) >= 4095:
        raise ValueError('A short-lived Actions read token is required')
    with Path('/run/lock/schuit-ci-deploy.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        evidence = json_api('actions/runs/' + run_id, token)
        jobs = json_api('actions/runs/' + run_id + '/jobs?filter=latest&per_page=100', token)
        main_sha = json_api('git/ref/heads/main', token)['object']['sha']
        verify_run(evidence, jobs, revision, run_id, main_sha)
        with tempfile.TemporaryDirectory(prefix='schuit-release-', dir='/var/tmp') as temporary:
            work = Path(temporary)
            image_archive = artifact(run_id, token, work)
            identity = archive_identity(image_archive, image_id)
            release_source = source(revision, work)
            with image_archive.open('rb') as stream:
                run(['docker', 'load'], stdin=stream)
            # Classic Docker exposes the config digest; containerd exposes the
            # manifest digest. Both must be cryptographically bound to the exact
            # GitHub-verified archive. Never resolve the mutable candidate tag.
            ci_image_id = image_id
            for local_id in (identity['manifest_id'], identity['config_id']):
                inspected = subprocess.run(['docker', 'image', 'inspect', local_id], capture_output=True, text=True)
                if inspected.returncode == 0:
                    metadata = json.loads(inspected.stdout)[0]
                    verify_image(metadata, revision, run_id, local_id)
                    image_id = local_id
                    break
            else:
                raise ValueError('Verified image identities not loaded')
            # Fresh host scan of the exact tested artifact, no saved eligibility reuse.
            run(['bash', '/opt/infra/schuit/deploy-gate/scan.sh'], cwd=release_source, env={**os.environ, 'SOURCE_REVISION': revision, 'SCHUIT_TEST_IMAGE': image_id})
            latest_main = json_api('git/ref/heads/main', token)['object']['sha']
            if latest_main != revision:
                raise ValueError('Main advanced while validating the release; use the newest run')
            release = RELEASES / (revision + '-ci-' + run_id)
            if release.exists():
                raise ValueError('This release already exists; do not overwrite it')
            shutil.copytree(release_source, release, ignore=shutil.ignore_patterns('.ci'))
            # The only generated Compose difference is the verified immutable image ID.
            compose_file = release / 'deployment/compose.yaml'
            compose_file.write_text((release / 'deployment/release-compose.yaml').read_text().replace('SCHUIT_VERIFIED_IMAGE', image_id))
            # Keep evidence privately with the deployment, outside application data.
            evidence_path = BASE / 'release-evidence' / run_id
            evidence_path.mkdir(parents=True, mode=0o700)
            (evidence_path / 'image-identity.json').write_text(json.dumps({**identity, 'ci_image_id': ci_image_id, 'host_image_id': image_id}, indent=2) + '\n')
            shutil.copytree(release_source / '.ci/scans', evidence_path / 'scans')
            activate(release, revision, image_id, run_id)
    print('Verified SCHUIT release deployed and healthy.')

if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        # Avoid exposing tokens, redirect URLs or server data from exception payloads.
        print('Deployment blocked: ' + (str(error) if isinstance(error, ValueError) else type(error).__name__), file=sys.stderr)
        sys.exit(1)
