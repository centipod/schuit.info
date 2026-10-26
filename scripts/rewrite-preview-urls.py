#!/usr/bin/env python3
"""Serialization-aware replacement on the new SCHUIT review database only."""
import subprocess
new='https://schuit.centipod.ai'
for old,target in [(scheme+'://'+host+path, new+suffix)
                   for scheme in ['https','http']
                   for host in ['www.schuit.info','schuit.info']
                   for path,suffix in [('/schuit/schuit',''),('/schuit',''),('/webtrees','/tree')]] + [('https://stichtingschu-y-i-ij-t.nl',new),('http://stichtingschu-y-i-ij-t.nl',new)]:
    subprocess.run(['docker','exec','schuit-web','wp','search-replace',old,target,'--all-tables-with-prefix','--skip-columns=guid','--precise','--report-changed-only','--path=/var/www/html'],check=True)
subprocess.run(['docker','exec','schuit-web','wp','eval','require "/opt/schuit-scripts/finalize-content.php";','--path=/var/www/html'],check=True)
