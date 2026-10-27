#!/usr/bin/env python3
"""Deploy a published SCHUIT runtime hardening revision, with rollback."""
import datetime,fcntl,json,os,re,subprocess,sys,time
from pathlib import Path
revision=sys.argv[1]
assert re.fullmatch('[0-9a-f]{40}',revision)
release=Path('/opt/infra/schuit/releases')/revision
base=Path('/srv/schuit');image='centipod-schuit:php8.3.36-20261027-security4'
def run(args,**kw):return subprocess.run(args,check=True,**kw)
def output(args):return subprocess.check_output(args,text=True).strip()
def switch(path):
    link=Path('/opt/apps/.schuit-hardening-next');link.unlink(missing_ok=True)
    link.symlink_to(path);link.replace('/opt/apps/schuit')
def compose(path):run(['docker','compose','-f',str(path/'deployment/compose.yaml'),'up','-d','--force-recreate','--wait','--wait-timeout','120','web'])
assert release.is_dir()
network=json.loads(output(['docker','network','inspect','schuit-web']))[0]
assert network['IPAM']['Config'][0]['Subnet']=='192.168.160.0/20','Proxy trust range must match the actual isolated network'
assert any(c['Name']=='centipod-proxy' for c in network['Containers'].values())
run(['docker','build','-f',str(release/'docker/media-hardening.Dockerfile'),'-t',image,str(release)])
run(['docker','run','--rm','-e','SCHUIT_TRUSTED_PROXY_CIDR=192.168.160.0/20','--entrypoint','apache2ctl',image,'-t'])
run(['docker','run','--rm','--entrypoint','php','-v',str(release/'tests')+':/tests:ro',image,'/tests/login-throttle.php','/opt/schuit-security/login-throttle.php'])
run(['docker','run','--rm','--entrypoint','php','-v',str(release/'tests')+':/tests:ro',image,'/tests/runtime-guard.php'])
backup=json.loads((base/'backup-state.json').read_text())['backup']
assert backup['success'] and time.time()-backup['timestamp']<3600,'Fresh verified S3 backup required'
old=Path('/opt/apps/schuit').resolve();manifest=(base/'deployment.json').read_text()
before=output(['docker','exec','schuit-web','php','/opt/schuit-scripts/audit-runtime.php'])
lock=(base/'gedcom-jobs/host-worker.lock').open('a');fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
try:
    assert not (base/'gedcom-jobs/maintenance').exists()
    for job in (base/'gedcom-jobs').glob('*/status.json'):
        assert json.loads(job.read_text())['state'] not in ('queued','backing_up','importing','analyzing')
    run(['systemctl','stop','schuit-gedcom.timer'])
    switch(release);compose(release)
    after=output(['docker','exec','schuit-web','php','/opt/schuit-scripts/audit-runtime.php'])
    assert json.loads(before)==json.loads(after),'Application/database inventory changed unexpectedly'
    m=json.loads(manifest);m.update(source_commit=revision,image=output(['docker','inspect','--format','{{.Image}}','schuit-web']),runtime_hardening='2026-10-27',deployed_at=datetime.datetime.now(datetime.timezone.utc).isoformat())
    m['previous_release']=str(old)
    (base/'deployment.json').write_text(json.dumps(m,indent=2)+'\n')
except Exception:
    switch(old);compose(old);(base/'deployment.json').write_text(manifest);raise
finally:
    fcntl.flock(lock,fcntl.LOCK_UN);lock.close();run(['systemctl','start','schuit-gedcom.timer'])
print('Hardened runtime healthy; application/database inventory unchanged.')
