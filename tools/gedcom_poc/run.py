#!/usr/bin/env python3
"""Local-only POC CLI/browser. No application or database credentials required."""
import argparse
import json
import os
from pathlib import Path
import secrets
from http.server import BaseHTTPRequestHandler, HTTPServer
from urllib.parse import urlparse, parse_qs

from core import inspect, Rejected, MAX_BYTES


def private_write(path, data):
    # Refuse overwrites, including accidentally selecting the original input.
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(fd, 'wb') as f:
        f.write(data)


def serve(port):
    token = secrets.token_urlsafe(32)
    page = Path(__file__).with_name('index.html').read_text().replace('__TOKEN__', token).encode()

    class Handler(BaseHTTPRequestHandler):
        def log_message(self, *_):
            pass  # No filenames or genealogy content in HTTP access logs.

        def reply(self, code, body, content_type='application/json'):
            self.send_response(code)
            self.send_header('Content-Type', content_type)
            self.send_header('Content-Length', str(len(body)))
            self.send_header('Cache-Control', 'no-store')
            self.send_header('X-Content-Type-Options', 'nosniff')
            self.send_header('Content-Security-Policy', "default-src 'none'; script-src 'nonce-"+token+"'; style-src 'nonce-"+token+"'; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'")
            self.end_headers()
            self.wfile.write(body)

        def allowed_host(self):
            return self.headers.get('Host') == f'127.0.0.1:{self.server.server_port}'

        def do_GET(self):
            if not self.allowed_host() or self.path != '/':
                self.reply(404, b'{}')
                return
            self.reply(200, page, 'text/html; charset=utf-8')

        def do_POST(self):
            # Host check prevents DNS rebinding; random custom header prevents
            # cross-origin forms/fetch. No CORS is enabled; bind loopback only.
            if not self.allowed_host() or self.headers.get('X-POC-Token') != token:
                self.reply(403, b'{}')
                return
            if urlparse(self.path).path != '/inspect':
                self.reply(404, b'{}')
                return
            try:
                length = int(self.headers.get('Content-Length', '0'))
                if not 20 <= length <= MAX_BYTES:
                    raise Rejected('Bestand moet tussen 20 bytes en 100 MB zijn.')
                self.connection.settimeout(20)
                data = self.rfile.read(length)
                if len(data) != length:
                    raise Rejected('Upload is onvolledig.')
                selected = parse_qs(urlparse(self.path).query).get('format', ['auto'])[0]
                report, _ = inspect(data, selected)
                self.reply(200, json.dumps(report, ensure_ascii=False).encode())
            except (Rejected, ValueError, OSError):
                # Parser errors contain line numbers/tags only, but do not expose
                # arbitrary exception text or submitted genealogy through HTTP.
                self.reply(400, json.dumps({'error':'Bestand afgewezen. Controleer het gekozen formaat, de tekencodering en de GEDCOM-structuur.'}).encode())

    server = HTTPServer(('127.0.0.1', port), Handler)
    print(f'POC: http://127.0.0.1:{server.server_port}/', flush=True)
    print('Alleen lokale analyse. Geen import; uploads worden niet opgeslagen.', flush=True)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        server.server_close()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('input', nargs='?', type=Path)
    parser.add_argument('--format', choices=('auto','aldfaer','gedcom'), default='auto')
    parser.add_argument('--report', type=Path)
    parser.add_argument('--output', type=Path)
    parser.add_argument('--serve', action='store_true')
    parser.add_argument('--port', type=int, default=18835)
    args = parser.parse_args()
    if args.serve:
        serve(args.port)
        return 0
    if not args.input:
        parser.error('input of --serve vereist')
    try:
        if args.input.stat().st_size > MAX_BYTES:
            raise Rejected('Bestand is groter dan 100 MB.')
        report, output = inspect(args.input.read_bytes(), args.format)
        encoded = (json.dumps(report, ensure_ascii=False, indent=2) + '\n').encode()
        if args.output and output:
            private_write(args.output, output)
        if args.report:
            private_write(args.report, encoded)
        print(encoded.decode())
        return 0 if output else 2
    except (Rejected, OSError) as exc:
        print(json.dumps({'error': str(exc)}, ensure_ascii=False))
        return 2


if __name__ == '__main__':
    raise SystemExit(main())
