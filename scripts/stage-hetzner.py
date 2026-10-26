#!/usr/bin/env python3
"""Initial SCHUIT review deployment; refuse to overwrite existing application data.
Run as root on Hetzner after publishing this revision. Secrets stay outside Git.
"""
import gzip,json,os,pathlib,secrets,shutil,subprocess,tarfile
os.umask(0o077)
base=pathlib.Path('/srv/schuit');stage=pathlib.Path('/srv/schuit-migration-staging/working');config=pathlib.Path('/etc/centipod/schuit')
assert not base.exists() and not config.exists(), 'Existing SCHUIT deployment: use the documented update procedure.'
extracted=stage/'snapshot';extracted.mkdir(exist_ok=True)
with tarfile.open(stage/'private/application.tar.gz') as t:
 for m in t:
  assert not pathlib.PurePosixPath(m.name).is_absolute() and '..' not in pathlib.PurePosixPath(m.name).parts
  if m.isfile() or m.isdir():t.extract(m,extracted,filter='data')
base.mkdir(mode=0o755);config.mkdir(mode=0o750,parents=True)
for source,target in [('current/wordpress','wordpress'),('current/webtrees','webtrees'),('shared/webtrees/data','webtrees-data'),('shared/webtrees/media','webtrees-media')]:
 shutil.copytree(extracted/source,base/target,symlinks=False)
for name in ['uploads','metrics']:(base/name).mkdir(exist_ok=True)
for name in ['wordpress/wp-content/uploads','wordpress/tree','webtrees/data','webtrees/media','webtrees/config']:
 (base/name).mkdir(parents=True,exist_ok=True)
# Never serve the old credentials. Nested mount targets are harmless empty files.
(base/'wordpress/wp-config.php').write_text('')
(base/'webtrees-data/config.ini.php').write_text('')
for name in ['webtrees-data/cache']:
 if (base/name).exists():shutil.rmtree(base/name)
recovery={'wordpress':{'database':'wp_schuit','user':'schuit_wp','password':secrets.token_hex(24)},'webtrees':{'database':'wt_schuit','user':'schuit_wt','password':secrets.token_hex(24)}}
for item in recovery.values():
 sql=f"CREATE DATABASE `{item['database']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER '{item['user']}'@'%' IDENTIFIED BY '{item['password']}'; GRANT ALL PRIVILEGES ON `{item['database']}`.* TO '{item['user']}'@'%';"
 subprocess.run(['docker','exec','-i','wordpress-mariadb','mariadb','--defaults-extra-file=/run/secrets/root-client.cnf'],input=sql.encode(),check=True,stdout=subprocess.DEVNULL)
for role,item in recovery.items():
 with gzip.open(stage/('private/'+role+'.sql.gz'),'rb') as stream:
  p=subprocess.Popen(['docker','exec','-i','wordpress-mariadb','mariadb','--defaults-extra-file=/run/secrets/root-client.cnf',item['database']],stdin=subprocess.PIPE,stdout=subprocess.DEVNULL)
  try:shutil.copyfileobj(stream,p.stdin)
  finally:p.stdin.close()
  assert p.wait()==0, 'Database import failed'
wp=recovery['wordpress'];wt=recovery['webtrees'];url='https://schuit.centipod.ai'
values={'DB_NAME':wp['database'],'DB_USER':wp['user'],'DB_PASSWORD':wp['password'],'DB_HOST':'wordpress-mariadb','DB_CHARSET':'utf8mb4','DB_COLLATE':'','WP_HOME':url,'WP_SITEURL':url,'WP_ENVIRONMENT_TYPE':'staging'}
values.update({k:secrets.token_hex(48) for k in ['AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT']})
text='<?php\n'+''.join("define('"+k+"', "+json.dumps(v)+");\n" for k,v in values.items())
text+="define('DISABLE_WP_CRON', true); define('DISALLOW_FILE_MODS', true); define('DISALLOW_FILE_EDIT', true); define('AUTOMATIC_UPDATER_DISABLED', true); define('WP_HTTP_BLOCK_EXTERNAL', true); define('FORCE_SSL_ADMIN', true);\n"
text+="$table_prefix='wp_'; if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') { $_SERVER['HTTPS']='on'; } if (!defined('ABSPATH')) { define('ABSPATH', __DIR__.'/'); } require_once ABSPATH.'wp-settings.php';\n"
(config/'wp-config.php').write_text(text)
(config/'webtrees.ini.php').write_text('; <?php exit; ?>\n'+''.join(k+'="'+v+'"\n' for k,v in {'dbhost':'wordpress-mariadb','dbport':'3306','dbuser':wt['user'],'dbpass':wt['password'],'dbname':wt['database'],'tblpfx':'wt_','base_url':url+'/tree'}.items()))
# Shared repository recovery key is already escrowed in AWS; database secrets are
# included only inside the encrypted application backup.
(config/'recovery.json').write_text(json.dumps(recovery))
for path in base.rglob('*'):
 if path.is_file():path.chmod(0o644)
 else:path.chmod(0o755)
 os.chown(path,33,33)
for path in config.iterdir():
 path.chmod(0o640 if path.name.endswith('.php') else 0o600)
 os.chown(path,0,33 if path.name.endswith('.php') else 0)
os.chown(config,0,33)
print('Staged both databases and application files; credentials kept private.')
