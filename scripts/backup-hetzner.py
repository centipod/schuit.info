#!/usr/bin/env python3
"""Encrypted SCHUIT file/database backups and isolated restore verification.
Uses the existing WordPress S3 repository credentials, escrowed recovery key and
35-day snapshot retention. No database credentials leave the encrypted archive.
"""
import fcntl,hashlib,importlib.util,json,os,pathlib,subprocess,sys,tarfile,tempfile,time
from cryptography.hazmat.primitives.ciphers.aead import AESGCM
os.umask(0o077)
spec=importlib.util.spec_from_file_location('wordpress_backup','/opt/apps/wordpress/backup.py');shared=importlib.util.module_from_spec(spec);spec.loader.exec_module(shared)
BASE=pathlib.Path('/srv/schuit');WORK=pathlib.Path('/srv/schuit-backup-work');WORK.mkdir(mode=0o700,exist_ok=True)
PREFIX='test/application-files/wordpress/schuit-platform/';MAGIC=b'SCHUIT-BACKUP-v1\n'
ROOTS=['wordpress','uploads','webtrees','webtrees-data','webtrees-media','metrics']
DATABASES={'wp_schuit':['wp_posts','wp_users'],'wt_schuit':['wt_individuals','wt_families','wt_user']}
IMAGE='mariadb@sha256:250e1609e93c0e6aecf64bfbab2d84d5e89d2481540fe77447ff6c855c3b1873'
KEY=bytes.fromhex(json.loads(pathlib.Path('/etc/centipod/wordpress/backup.json').read_text())['key_hex'])

def run(args,**kw):
 p=subprocess.run(args,stderr=subprocess.PIPE,**kw)
 if p.returncode:raise RuntimeError('Backup/restore subprocess failed; private output withheld')
 return p

def hashes():
 return {str(p.relative_to(BASE)):hashlib.file_digest(p.open('rb'),'sha256').hexdigest() for root in ROOTS for p in sorted((BASE/root).rglob('*')) if p.is_file() and '/cache/' not in str(p)}

def checks(container,auth):
 result={}
 for db,tables in DATABASES.items():
  sql=';'.join('SELECT COUNT(*) FROM `'+t+'`' for t in tables)
  p=run(['docker','exec',container,'mariadb',*auth,'--batch','--skip-column-names',db,'-e',sql],stdout=subprocess.PIPE)
  result[db]=dict(zip(tables,map(int,p.stdout.split())))
 return result

def backup():
 with tempfile.TemporaryDirectory(dir=WORK) as d:
  folder=pathlib.Path(d);manifest={'created_at':time.time(),'files':hashes(),'counts':checks('wordpress-mariadb',['--defaults-extra-file=/run/secrets/root-client.cnf'])}
  for db in DATABASES:
   with (folder/(db+'.sql')).open('wb') as out:
    run(['docker','exec','wordpress-mariadb','mariadb-dump','--defaults-extra-file=/run/secrets/root-client.cnf','--single-transaction','--skip-lock-tables','--hex-blob',db],stdout=out)
   manifest[db+'_sha256']=hashlib.file_digest((folder/(db+'.sql')).open('rb'),'sha256').hexdigest()
  (folder/'manifest.json').write_text(json.dumps(manifest))
  archive=folder/'backup.tar.gz'
  with tarfile.open(archive,'w:gz') as t:
   t.add(folder/'manifest.json',arcname='manifest.json')
   for db in DATABASES:t.add(folder/(db+'.sql'),arcname=db+'.sql')
   for root in ROOTS:t.add(BASE/root,arcname='data/'+root,filter=lambda m: None if '/cache/' in m.name or m.name.endswith('/cache') else m)
   t.add('/etc/centipod/schuit',arcname='config')
  nonce=os.urandom(12);body=MAGIC+nonce+AESGCM(KEY).encrypt(nonce,archive.read_bytes(),MAGIC)
  key=PREFIX+time.strftime('%Y%m%dT%H%M%SZ',time.gmtime())+'.aesgcm'
  shared.transfer('PUT',key,body)
  digest=hashlib.sha256(body).hexdigest()
  assert hashlib.sha256(shared.transfer('GET',key)).hexdigest()==digest
  shared.transfer('PUT',PREFIX+'latest.json',json.dumps({'key':key,'sha256':digest}).encode())
  return {'archive_key':key,'counts':manifest['counts'],'files':len(manifest['files'])}

def restore():
 pointer=json.loads(shared.transfer('GET',PREFIX+'latest.json'));body=shared.transfer('GET',pointer['key'])
 assert hashlib.sha256(body).hexdigest()==pointer['sha256'] and body.startswith(MAGIC)
 offset=len(MAGIC);plain=AESGCM(KEY).decrypt(body[offset:offset+12],body[offset+12:],MAGIC)
 with tempfile.TemporaryDirectory(dir=WORK) as d:
  folder=pathlib.Path(d);archive=folder/'backup.tar.gz';archive.write_bytes(plain);del body,plain
  with tarfile.open(archive) as t:
   for m in t:
    assert not pathlib.PurePosixPath(m.name).is_absolute() and '..' not in pathlib.PurePosixPath(m.name).parts and not m.issym() and not m.islnk()
   manifest=json.load(t.extractfile('manifest.json'))
   for name,digest in manifest['files'].items():assert hashlib.file_digest(t.extractfile('data/'+name),'sha256').hexdigest()==digest
   for db in DATABASES:
    data=t.extractfile(db+'.sql').read();assert hashlib.sha256(data).hexdigest()==manifest[db+'_sha256'];(folder/(db+'.sql')).write_bytes(data)
  name='schuit-restore-check';dbdata=folder/'db';dbdata.mkdir()
  run(['docker','run','-d','--name',name,'--network','none','--memory','768m','--cpus','0.5','--pids-limit','128','--env','MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=1','--mount',f'type=bind,src={dbdata},dst=/var/lib/mysql',IMAGE,'--innodb-buffer-pool-size=128M'],stdout=subprocess.DEVNULL)
  try:
   for _ in range(90):
    if subprocess.run(['docker','exec',name,'mariadb-admin','-uroot','ping'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL).returncode==0:break
    time.sleep(1)
   else:raise RuntimeError('Restore database startup failed')
   for db in DATABASES:
    run(['docker','exec',name,'mariadb','-uroot','-e','CREATE DATABASE `'+db+'`'],stdout=subprocess.DEVNULL)
    with (folder/(db+'.sql')).open('rb') as src:run(['docker','exec','-i',name,'mariadb','-uroot',db],stdin=src,stdout=subprocess.DEVNULL)
   assert checks(name,['-uroot'])==manifest['counts']
  finally:run(['docker','rm','-f',name],stdout=subprocess.DEVNULL)
  return {'counts':manifest['counts'],'files_verified':len(manifest['files'])}

mode=sys.argv[1];assert mode in ['backup','restore']
with (WORK/'lock').open('w') as lock:
 fcntl.flock(lock,fcntl.LOCK_EX)
 statefile=BASE/'backup-state.json';state=json.loads(statefile.read_text()) if statefile.exists() else {}
 try:
  result=globals()[mode]();state[mode]={'timestamp':time.time(),'success':True,**result}
 except Exception:
  state[mode]={'timestamp':time.time(),'success':False};raise
 finally:
  temp=statefile.with_suffix('.tmp');temp.write_text(json.dumps(state,indent=2)+'\n');temp.replace(statefile)
 print(json.dumps(state[mode]))
