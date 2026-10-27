"""Checks independently applied by the Hetzner deployment receiver."""
import re

REPOSITORY = 'centipod/schuit.info'
REQUIRED_JOBS = {'Build candidate', 'Unit tests', 'Browser E2E', 'CVE scan', 'Release gate'}

def parse_command(command):
    match = re.fullmatch(r'deploy ([a-f0-9]{40}) (sha256:[a-f0-9]{64}) ([1-9][0-9]{0,19})', command)
    if not match:
        raise ValueError('Only a gated deployment command is allowed')
    return match.groups()

def verify_run(run, jobs, revision, run_id, main_sha):
    if str(run.get('id')) != run_id or run.get('head_sha') != revision or main_sha != revision:
        raise ValueError('Run, candidate and current main must identify the same commit')
    if run.get('head_branch') != 'main' or run.get('event') not in {'push', 'workflow_dispatch'}:
        raise ValueError('Only main push/manual release runs are accepted')
    if run.get('path') != '.github/workflows/ci.yml' or run.get('head_repository', {}).get('full_name') != REPOSITORY:
        raise ValueError('Unexpected workflow or repository')
    if jobs.get('total_count') != len(jobs.get('jobs', [])):
        raise ValueError('Incomplete job evidence')
    for name in REQUIRED_JOBS:
        matches = [job for job in jobs['jobs'] if job.get('name') == name]
        if len(matches) != 1 or matches[0].get('status') != 'completed' or matches[0].get('conclusion') != 'success':
            raise ValueError('Required check did not pass: ' + name)

def verify_image(metadata, revision, run_id, image_id):
    labels = metadata.get('Config', {}).get('Labels', {}) or {}
    if metadata.get('Id') != image_id or metadata.get('Architecture') != 'amd64':
        raise ValueError('Image identity or architecture does not match')
    if labels.get('org.opencontainers.image.revision') != revision or labels.get('io.centipod.ci-run') != run_id:
        raise ValueError('Image provenance does not match the checked run')
