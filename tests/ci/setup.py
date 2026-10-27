#!/usr/bin/env python3
"""Create only disposable local test credentials. Never accept production targets."""
from pathlib import Path
import json,os,secrets
os.umask(0o077)
p=Path(__file__).resolve().parents[2]/'.ci';p.mkdir(exist_ok=True)
password=secrets.token_hex(24)
(p/'env').write_text('CI_DB_PASSWORD='+secrets.token_hex(24)+'\nCI_LOGIN_PASSWORD='+password+'\n')
(p/'credentials.json').write_text(json.dumps({'password':password}))
