#!/usr/bin/env python3
"""Root-only provisioning from private stdin. Never print secret values."""
import json, os, pathlib, re, sys, tempfile

def main():
    if os.geteuid() != 0:
        raise RuntimeError('Run as root on the SCHUIT host')
    raw = sys.stdin.buffer.read(8193)
    if len(raw) > 8192:
        raise ValueError('Configuration too large')
    config = json.loads(raw)
    for key in ('site_key', 'secret_key'):
        if not isinstance(config.get(key), str) or not re.fullmatch(r'[A-Za-z0-9_-]{20,200}', config[key]):
            raise ValueError('Invalid key format')
    hosts = ['schuit.info', 'www.schuit.info', 'schuit.centipod.ai']
    if config.get('hosts') != hosts:
        raise ValueError('Unexpected host scope')
    root = pathlib.Path('/etc/centipod/schuit')
    if not root.is_dir() or root.is_symlink() or root.stat().st_uid != 0 or root.stat().st_mode & 0o022:
        raise ValueError('Configuration directory must be root-owned and not writable by other users')
    for name, data in [('recaptcha.json', config), ('recaptcha-public.json', {'site_key':config['site_key'], 'hosts':hosts})]:
        fd, temp = tempfile.mkstemp(prefix='.recaptcha-', dir=root)
        try:
            with os.fdopen(fd, 'w') as stream:
                os.fchmod(stream.fileno(), 0o640)
                os.fchown(stream.fileno(), 0, 33)
                stream.write(json.dumps(data)+'\n'); stream.flush(); os.fsync(stream.fileno())
            os.replace(temp, root/name)
        finally:
            pathlib.Path(temp).unlink(missing_ok=True)
    print('SCHUIT reCAPTCHA configuration installed; values withheld.')

if __name__ == '__main__':
    main()
