"""Prepare a loopback-only tutorial using the real app and synthetic CI fixtures."""
import json, pathlib, subprocess
root=pathlib.Path(__file__).resolve().parents[2]
out=root/'.tutorial';out.mkdir(exist_ok=True)
subprocess.run(['python3',str(root/'tests/ci/setup.py')],check=True)
init=(root/'tests/ci/init.php').read_text().replace('https://localhost:19443','http://127.0.0.1:18890').replace('SCHUIT CI','SCHUIT · demonstratie').replace("'en_US'","'nl_NL'").replace('define("FORCE_SSL_ADMIN",true)','define("FORCE_SSL_ADMIN",false)').replace('$_SERVER["HTTPS"]="on";','')
init=init.replace("echo \"Synthetic WordPress fixture initialized.\\n\";", "update_option('WPLANG','nl_NL'); update_user_meta(1,'locale','nl_NL'); wp_update_user(['ID'=>1,'display_name'=>'Demonstratie']); echo \"Tutorial ready.\\n\";")
(out/'init.php').write_text(init)
tree=(root/'tests/ci/init-tree.php').read_text().replace('en-US','nl').replace('Synthetic CI family tree','Demonstratiestamboom')
(out/'init-tree.php').write_text(tree)
(out/'Caddyfile').write_text(':8080 {\n reverse_proxy web:8080\n}\n')
compose={'name':'schuit-tutorial','services':{'db':{'image':'mariadb:11.4','environment':{'MARIADB_ROOT_PASSWORD':'${CI_DB_PASSWORD}'},'volumes':['db:/var/lib/mysql'],'networks':['isolated'],'healthcheck':{'test':['CMD','healthcheck.sh','--connect','--innodb_initialized'],'interval':'2s','timeout':'5s','retries':40}},'web':{'image':'schuit-quality:local','environment':{'CI_FIXTURE':'synthetic-only','CI_DB_PASSWORD':'${CI_DB_PASSWORD}','CI_LOGIN_PASSWORD':'${CI_LOGIN_PASSWORD}','SCHUIT_TRUSTED_PROXY_CIDR':'172.30.92.0/24','SCHUIT_GEDCOM_TREE_ID':'1','SCHUIT_GEDCOM_JOBS':'/var/lib/schuit-gedcom','WEBTREES_METRICS_PATH':'/run/schuit-metrics/metrics.json','WEBTREES_CONFIG_PATH':'/var/www/html/tree/data/config.ini.php'},'volumes':[str(root)+':/opt/schuit-repo:ro',str(root/'tests/ci/privacy')+':/run/schuit-privacy:ro',str(root/'.ci/auth')+':/run/secrets:ro','site:/var/www/html','jobs:/var/lib/schuit-gedcom','metrics:/run/schuit-metrics'],'networks':['isolated'],'depends_on':{'db':{'condition':'service_healthy'}}},'proxy':{'image':'caddy:2','ports':['127.0.0.1:18890:8080'],'volumes':[str(out/'Caddyfile')+':/etc/caddy/Caddyfile:ro'],'networks':['isolated','edge']}},'networks':{'isolated':{'internal':True,'ipam':{'config':[{'subnet':'172.30.92.0/24'}]}},'edge':{}},'volumes':{x:{} for x in ['db','site','jobs','metrics']}}
(out/'compose.json').write_text(json.dumps(compose,indent=2))
processor=(root/'tests/ci/process-jobs.py').read_text().replace('root = Path(__file__).resolve().parents[2]', 'root = Path('+repr(str(root))+')').replace('str(root / "tests/ci/compose.yaml")','str(root / ".tutorial/compose.json")').replace('"schuit-ci"','"schuit-tutorial"')
(out/'process-jobs.py').write_text(processor)
