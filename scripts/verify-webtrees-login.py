#!/usr/bin/env python3
"""HTTPS sign-in/CSRF/admin/logout test with one short-lived synthetic account."""
import http.cookiejar
import json
import subprocess
import urllib.error
import urllib.parse
import urllib.request
from html.parser import HTMLParser
from pathlib import Path

ORIGIN = 'https://schuit.centipod.ai'
HELPER = '/tmp/schuit-security-tests/tests/webtrees-security-account.php'
class Fields(HTMLParser):
    def __init__(self): super().__init__(); self.fields = {}
    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'input' and attrs.get('name'): self.fields[attrs['name']] = attrs.get('value', '')
class Redirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        assert newurl.startswith(ORIGIN + '/'), 'Unexpected external authentication redirect'
        return super().redirect_request(req, fp, code, msg, headers, newurl)
def account(*args):
    p = subprocess.run(['docker','exec','schuit-web','php',HELPER,*args],capture_output=True,text=True,timeout=30)
    assert p.returncode == 0, 'Private test-account helper failed'
    return json.loads(p.stdout)
jar = http.cookiejar.CookieJar()
client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar),Redirect())
client.addheaders = [('User-Agent','Mozilla/5.0 Chrome/131.0 SCHUIT verification')]
def fetch(path, fields=None):
    request = urllib.request.Request(ORIGIN+path,data=None if fields is None else urllib.parse.urlencode(fields).encode())
    try: response=client.open(request,timeout=30)
    except urllib.error.HTTPError as error: response=error
    with response: return response.status,response.url,response.read().decode()
user = None
results = {}
try:
    user = account('create')
    fetch('/')
    status,url,body = fetch('/tree/login/0')
    parser=Fields();parser.feed(body)
    fields=parser.fields
    csrf = next(k for k in fields if 'csrf' in k)
    fields.update(username=user['name'],password=user['password'],url=ORIGIN+'/tree/admin')
    invalid=dict(fields);invalid[csrf]='invalid-verification-token'
    status,url,body=fetch('/tree/login/0',invalid)
    assert status==403, 'Invalid CSRF token was not rejected'
    results['invalid_csrf_rejected']=True
    # Obtain a fresh CSRF token after the deliberate rejection.
    status,url,body=fetch('/tree/login/0');parser=Fields();parser.feed(body)
    fields=parser.fields;fields.update(username=user['name'],password=user['password'],url=ORIGIN+'/tree/admin')
    status,url,body=fetch('/tree/login/0',fields)
    assert status==200 and urllib.parse.urlsplit(url).path=='/tree/admin' and '/logout' in body
    results['https_login_and_admin_access']=True
    session_cookies=[c for c in jar if c.name not in ('schuit_webtrees_visit','x')]
    assert session_cookies and all(c.secure and c.has_nonstandard_attr('HttpOnly') for c in session_cookies)
    results['secure_httponly_sessions']=True
    status,url,body=fetch('/tree/logout')
    status,url,body=fetch('/tree/admin')
    assert '/login' in url or status in (401,403)
    results['logout_revokes_admin_access']=True
finally:
    if user:
        assert account('delete',user['name'])['remaining']==user['before']
        results['temporary_account_removed']=True
print(json.dumps(results,indent=2))
