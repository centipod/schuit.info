#!/usr/bin/env python3
"""HTTPS acceptance with temporary accounts and synthetic uploads only.

Confirmation is tested with the timer stopped, and that synthetic queued job is
removed before restarting it. Refuse to suspend the runner if any other job is active.
Never import into the real tree. No passwords, cookies or genealogy are logged.
"""
import html
import http.cookiejar
import json
from pathlib import Path
import re
import secrets
import shutil
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request

from importlib.util import spec_from_file_location, module_from_spec
spec = spec_from_file_location('login', Path(__file__).with_name('verify-login.py'))
login = module_from_spec(spec)
spec.loader.exec_module(login)
ORIGIN = 'https://schuit.centipod.ai'
PAGE = ORIGIN + '/wp-admin/tools.php?page=schuit-gedcom-import'
ROOT = Path('/srv/schuit/gedcom-jobs')
accounts = []
jobs = []
checks = []
timer_stopped = False


def request(opener, url, data=None, headers=None):
    try:
        with opener.open(urllib.request.Request(url, data=data, headers=headers or {}), timeout=45) as response:
            return response.status, response.read().decode(), response.url
    except urllib.error.HTTPError as error:
        return error.code, error.read().decode(), error.url


def account(role):
    name = 'gedcomcheck_' + secrets.token_hex(8)
    code = '''$p=wp_generate_password(48,true,true);$u=wp_insert_user(['user_login'=>%s,'user_pass'=>$p,'user_email'=>%s,'role'=>%s]);
    if(is_wp_error($u)){throw new Exception('Temporary account failed');}echo json_encode(['id'=>$u,'password'=>$p]);''' % (json.dumps(name), json.dumps(name+'@example.invalid'), json.dumps(role))
    private = json.loads(login.wp('schuit', 'eval', code))
    accounts.append((private['id'], name))
    jar = http.cookiejar.CookieJar()
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar), login.SameOriginRedirect('schuit.centipod.ai'))
    request(opener, ORIGIN+'/wp-login.php')
    request(opener, ORIGIN+'/wp-login.php', urllib.parse.urlencode({'log':name,'pwd':private['password'],'redirect_to':PAGE,'testcookie':'1'}).encode())
    assert any(c.name.startswith('wordpress_logged_in_') for c in jar)
    return opener, private['id']


def nonce(body, name='_wpnonce'):
    match = re.search(r'name="'+name+r'" value="([^"]+)"', body)
    assert match, 'Form nonce missing'
    return html.unescape(match[1])


def upload(opener, token, filename, content):
    boundary = 'schuit-' + secrets.token_hex(12)
    fields = {'action':'schuit_gedcom','operation':'upload','_wpnonce':token}
    body = b''
    for key, value in fields.items():
        body += f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode()
    body += f'--{boundary}\r\nContent-Disposition: form-data; name="gedcom"; filename="{filename}"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode()+content+f'\r\n--{boundary}--\r\n'.encode()
    result = request(opener, ORIGIN+'/wp-admin/admin-post.php', body, {'Content-Type':'multipart/form-data; boundary='+boundary})
    if result[0] == 200:
        identifier = urllib.parse.parse_qs(urllib.parse.urlsplit(result[2]).query)['job'][0]
        jobs.append(identifier)
    return result


def post(opener, token, identifier, operation, confirm=''):
    return request(opener, ORIGIN+'/wp-admin/admin-post.php', urllib.parse.urlencode({'action':'schuit_gedcom',
        'operation':operation,'_wpnonce':token,'job':identifier,'replace_confirmed':confirm}).encode())


def wait_state(identifier, expected):
    for _ in range(60):
        state = json.loads((ROOT/identifier/'status.json').read_text())
        if state['state'] == expected:
            return state
        time.sleep(1)
    raise AssertionError('Background job did not reach '+expected)


before_users = int(login.wp('schuit','user','list','--format=count'))
try:
    guest = urllib.request.build_opener(login.SameOriginRedirect('schuit.centipod.ai'))
    assert '/wp-login.php' in request(guest,PAGE)[2]
    subscriber, _ = account('subscriber')
    assert request(subscriber,PAGE)[0] == 403
    checks += ['anonymous login required','subscriber denied']
    manager, owner = account('schuit_gedcom_manager')
    other, _ = account('schuit_gedcom_manager')
    code, body, _ = request(manager,PAGE)
    assert code == 200 and 'Nieuw bestand uploaden' in body
    token = nonce(body)
    assert post(manager,'invalid','', 'upload')[0] == 403
    fixture = b'0 HEAD\n1 SOUR Fixture\n1 GEDC\n2 VERS 5.5.1\n1 CHAR UTF-8\n0 @I1@ INDI\n1 NAME Test/Fixture/\n0 TRLR\n'
    assert upload(manager,token,'fixture.txt',fixture)[0] == 400
    assert upload(manager,token,'fixture.ged',fixture)[0] == 200
    identifier = jobs[-1]
    state = wait_state(identifier,'ready')
    assert state['analysis']['counts']['INDI'] == 1 and state['analysis']['normalized_names'] == 1
    assert (ROOT/identifier/'original.ged').read_bytes() == fixture
    assert (ROOT/identifier/'original.ged').stat().st_mode & 0o777 == 0o600
    code, body, _ = request(manager,PAGE+'&job='+identifier)
    assert code == 200 and 'Bevestigen en verwerken' in body and 'minder personen' in body
    assert 'Taak niet beschikbaar' in request(other,PAGE+'&job='+identifier)[1]
    assert post(manager,token,identifier,'confirm')[0] == 400
    assert post(other,nonce(request(other,PAGE)[1]),identifier,'cancel')[0] == 400
    checks += ['manager upload/status','nonce required','extension restriction','background analysis','private original unchanged','metrics and reduction warning','ownership isolation','explicit confirmation required']
    assert post(manager,token,identifier,'cancel')[0] == 200
    assert post(manager,token,identifier,'confirm','yes')[0] == 400
    checks += ['cancel','replayed confirmation rejected']
    assert upload(manager,token,'invalid.ged',b'not a GEDCOM file at all')[0] == 200
    wait_state(jobs[-1],'failed')
    checks += ['malformed upload rejected by worker']
    assert upload(manager,token,'confirmation.ged',fixture)[0] == 200
    identifier = jobs[-1]
    wait_state(identifier,'ready')
    for path in ROOT.glob('*/status.json'):
        j = json.loads(path.read_text())
        assert j['id'] in jobs or j['state'] in ('ready','completed','failed','cancelled'), 'Another import is active; do not pause runner'
    subprocess.run(['systemctl','stop','schuit-gedcom.timer'],check=True)
    timer_stopped = True
    # Analysis has completed; wait for its host oneshot to exit normally.
    for _ in range(10):
        active = subprocess.run(['systemctl','is-active','schuit-gedcom.service'],capture_output=True,text=True).stdout.strip()
        if active not in ('active','activating'):
            break
        time.sleep(1)
    assert active not in ('active','activating')
    assert post(manager,token,identifier,'confirm','yes')[0] == 200
    assert json.loads((ROOT/identifier/'status.json').read_text())['state'] == 'queued'
    assert post(manager,token,identifier,'confirm','yes')[0] == 400
    checks += ['confirmed transition to queue','duplicate submission rejected']
finally:
    # Delete only synthetic jobs owned by this acceptance run, before resuming.
    for identifier in jobs:
        path = ROOT/identifier
        if path.exists():
            assert json.loads((path/'status.json').read_text())['owner'] in [a[0] for a in accounts]
            shutil.rmtree(path)
    if timer_stopped:
        subprocess.run(['systemctl','start','schuit-gedcom.timer'],check=True)
    for uid, name in accounts:
        assert login.wp('schuit','user','get',str(uid),'--field=user_login') == name
        login.wp('schuit','user','delete',str(uid),'--yes')
    assert int(login.wp('schuit','user','list','--format=count')) == before_users
report = {'checked_at':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime()),'checks':checks,'temporary_accounts_and_jobs_removed':True,'real_tree_imported':False}
Path('/srv/schuit/gedcom-acceptance.json').write_text(json.dumps(report,indent=2)+'\n')
print(json.dumps(report,indent=2))
