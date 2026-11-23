#!/usr/bin/env python3
"""Local HTTPS fixture test only; this does not contact the Royal Brand host.

For maintainers: PHP_CMD='npx --yes @php-wasm/cli' python3 tests_http.py
The temporary test copy trusts a newly created LOCAL certificate and targets a
loopback server. The delivered diagnose.php remains unchanged.
"""

import gzip
import http.server
import json
import os
from pathlib import Path
import shlex
import shutil
import ssl
import subprocess
import tempfile
import threading
import urllib.parse

HERE = Path(__file__).resolve().parent
PHP = shlex.split(os.environ.get('PHP_CMD', 'php' if shutil.which('php') else 'npx --yes @php-wasm/cli'))


def run_php(args, env):
    result = subprocess.run(PHP + args, cwd=HERE, env=env, text=True, capture_output=True, timeout=90)
    if result.returncode:
        raise RuntimeError('PHP fixture failed: ' + result.stderr[:1000])
    return result.stdout


with tempfile.TemporaryDirectory(prefix='rb-http-fixture-') as temp:
    temp_path = Path(temp)
    cert, key = temp_path / 'cert.pem', temp_path / 'key.pem'
    subprocess.run([
        'openssl', 'req', '-x509', '-nodes', '-newkey', 'rsa:2048',
        '-keyout', str(key), '-out', str(cert), '-days', '1', '-subj', '/CN=localhost',
        '-addext', 'subjectAltName=DNS:localhost,IP:127.0.0.1',
    ], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    requests = []

    class Handler(http.server.BaseHTTPRequestHandler):
        def log_message(self, *args):
            pass

        def do_GET(self):
            path = urllib.parse.urlsplit(self.path).path
            user_agent = self.headers.get('User-Agent', '')
            requests.append((path, user_agent))
            origin = 'https://127.0.0.1:' + str(self.server.server_port)
            hub = origin + '/services/'
            branding = origin + '/services/' + urllib.parse.quote('کمپین-برندینگ') + '/'
            campaign = origin + '/services/' + urllib.parse.quote('کمپین-360-درجه') + '/'
            bot = 'Googlebot/2.1' in user_agent
            status = 200
            headers = [('Content-Type', 'text/html; charset=UTF-8'), ('Set-Cookie', 'secret=PRIVATE_COOKIE')]
            if path == '/robots.txt':
                body = b'User-agent: *\nAllow: /\n'
                headers[0] = ('Content-Type', 'text/plain')
            elif path == '/page-sitemap.xml':
                xml = '<urlset>' + ''.join('<url><loc>' + u + '</loc><lastmod>2020-01-02</lastmod></url>' for u in (hub, branding, campaign)) + '</urlset>'
                body = xml.encode()
                headers[0] = ('Content-Type', 'application/xml')
            elif path == '/sitemap_index.xml':
                body = ('<sitemapindex><sitemap><loc>' + origin + '/page-sitemap.xml</loc></sitemap></sitemapindex>').encode()
                headers[0] = ('Content-Type', 'application/xml')
            elif path == urllib.parse.urlsplit(branding).path and not bot:
                status, body = 301, b''
                headers.append(('Location', '/services/'))
            elif path == urllib.parse.urlsplit(campaign).path and bot:
                status, body = 403, b'Access denied fixture'
            elif path == '/large':
                body = b'x' * (3145728 + 8192)
                headers.extend([('Link', '<' + hub + '>; rel="alternate"')] * 25)
            else:
                canonical = hub if path == '/services/' else (branding if path == urllib.parse.urlsplit(branding).path else campaign)
                meta = '<meta name="googlebot" content="noindex">' if bot and path == urllib.parse.urlsplit(branding).path else ''
                body = ('<html><head><title>Fixture</title><link rel="canonical" href="' + canonical + '">' + meta + '</head>'
                        '<body><h1>سلام</h1><a href="' + branding + '">Branding</a>'
                        '<a href="' + campaign + '">360</a><p>PRIVATE_BODY</p></body></html>').encode()
                headers.append(('X-Robots-Tag', 'otherbot: noindex'))
                if path == '/services/':
                    body = gzip.compress(body)
                    headers.append(('Content-Encoding', 'gzip'))
            self.send_response(status)
            for name, value in headers:
                self.send_header(name, value)
            self.send_header('Content-Length', str(len(body)))
            self.end_headers()
            try:
                self.wfile.write(body)
            except (BrokenPipeError, ConnectionResetError, ssl.SSLError):
                pass  # expected when the cURL body limit interrupts a response

    server = http.server.HTTPServer(('127.0.0.1', 0), Handler)
    tls = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    tls.load_cert_chain(cert, key)
    server.socket = tls.wrap_socket(server.socket, server_side=True)
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    env = dict(os.environ)
    try:
        port = server.server_port
        original = (HERE / 'diagnose.php').read_text(encoding='utf-8')
        replacements = [
            ("const RB_HOST = 'royalbrand.vip';", "const RB_HOST = '127.0.0.1';"),
            ("$origin = 'https://' . RB_HOST;", "$origin = 'https://' . RB_HOST . ':" + str(port) + "';"),
            ("(int) $parts['port'] !== 443", "(int) $parts['port'] !== " + str(port)),
            ("CURLOPT_SSL_VERIFYPEER => true,", "CURLOPT_SSL_VERIFYPEER => true,\n        CURLOPT_CAINFO => '" + str(cert) + "',"),
        ]
        patched = original
        for old, new in replacements:
            assert patched.count(old) == 1, 'fixture patch target changed: ' + old
            patched = patched.replace(old, new, 1)
        copy = temp_path / 'local-fixture.php'
        copy.write_text(patched, encoding='utf-8')

        # A self-signed local certificate must fail when the unmodified tool does not trust it.
        probe = ('define("RB_DIAG_LIBRARY_ONLY",true); require "' + str(HERE / 'diagnose.php') + '"; '
                 '$r=rb_http_get("https://127.0.0.1:' + str(port) + '/untrusted", "fixture"); '
                 'echo json_encode(array("status"=>$r["status"],"error"=>$r["curl_error_code"]));')
        untrusted = json.loads(run_php(['-r', probe], env))
        assert untrusted['status'] is None and untrusted['error'] != 0, 'TLS verification unexpectedly bypassed'
        assert not requests, 'untrusted TLS must fail before an HTTP request'

        report_text = run_php([str(copy)], env)
        report = json.loads(report_text)
        assert len(requests) == 10, 'nine requests plus one allowed redirect expected'
        assert set(report['pages']) == {'hub', 'branding', 'campaign_360'}
        assert report['pages']['hub']['browser_ua']['status'] == 200
        assert report['pages']['hub']['browser_ua']['raw_html']['h1_count'] == 1, 'gzip source parsing'
        assert report['pages']['branding']['browser_ua']['hops'][0]['status'] == 301
        assert report['pages']['branding']['browser_ua']['raw_html']['canonical_matches_final_url'] is True
        assert report['pages']['branding']['browser_ua']['raw_html']['canonical_matches_requested_url'] is False
        assert report['pages']['branding']['googlebot_ua_simulated_not_google']['raw_html']['noindex_in_relevant_meta'] is True
        assert report['pages']['campaign_360']['googlebot_ua_simulated_not_google']['status'] == 403
        assert report['page_sitemap']['entries']['campaign_360']['present'] is True
        assert report['sitemap_index']['entries']['page_sitemap']['present'] is True
        for sensitive in ('PRIVATE_COOKIE', 'PRIVATE_BODY'):
            assert sensitive not in report_text, 'raw fixture content leaked'

        # Exercise the actual cURL callbacks and hard limits against local HTTPS.
        probe = ('define("RB_DIAG_LIBRARY_ONLY",true); require "' + str(copy) + '"; '
                 '$r=rb_http_get("https://127.0.0.1:' + str(port) + '/large", "fixture"); '
                 'echo json_encode(array("bytes"=>strlen($r["body"]),"truncated"=>$r["truncated"],'
                 '"error"=>$r["curl_error_code"],"header_limit"=>$r["header_limit_reached"],'
                 '"link_header_count"=>count($r["headers"]["link"]??array()),"has_cookie"=>isset($r["headers"]["set-cookie"])));')
        large = json.loads(run_php(['-r', probe], env))
        assert large['bytes'] == 3145728 and large['truncated'] is True and large['error'] != 0, 'body limit'
        assert large['header_limit'] is True and large['link_header_count'] == 20, 'header limit'
        assert large['has_cookie'] is False, 'sensitive response headers must not be retained'
        print('Local HTTPS fixture tests passed; no real site was contacted.')
    finally:
        server.shutdown()
        server.server_close()
