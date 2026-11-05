"""Exercise the real PHP endpoint against a local RouterOS protocol double.
Never contacts a production router; config and login fixture live in a temp copy.
"""
import base64
import http.cookiejar
import json
import os
from pathlib import Path
import shutil
import socket
import socketserver
import subprocess
import tempfile
import threading
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('PHP_BINARY', 'php')
users = {}
adds = 0
delay = 0.005
drop_next = False
commands = 0

def exact(sock, count):
    out = b''
    while len(out) < count:
        part = sock.recv(count - len(out))
        if not part:
            raise EOFError()
        out += part
    return out

def sentence(sock):
    words = []
    while True:
        size = exact(sock, 1)[0]
        if size & 0xc0 == 0x80:
            size = ((size & 63) << 8) + exact(sock, 1)[0]
        if not size:
            return words
        words.append(exact(sock, size).decode())

def send(sock, words):
    packet = b''
    for word in words:
        data = word.encode()
        size = len(data)
        packet += (bytes([size]) if size < 128 else bytes([(size >> 8) | 0x80, size & 255])) + data
    sock.sendall(packet + b'\0')

class Router(socketserver.BaseRequestHandler):
    def handle(self):
        global adds, commands, drop_next
        try:
            while True:
                words = sentence(self.request)
                cmd = words[0]
                commands += 1
                args = dict(word[1:].split('=', 1) for word in words[1:] if word[:1] in ('=', '?'))
                if cmd == '/login':
                    send(self.request, ['!done'])
                elif cmd == '/ip/hotspot/user/profile/print':
                    send(self.request, ['!re', '=name=daily', '=on-login=,rem,10,1d,12,,Disable,'])
                    send(self.request, ['!done'])
                elif cmd == '/ip/hotspot/user/print':
                    if args['name'] in users:
                        send(self.request, ['!re'] + ['=' + k + '=' + v for k, v in users[args['name']].items()])
                    send(self.request, ['!done'])
                elif cmd == '/ip/hotspot/user/add':
                    time.sleep(delay)
                    if args['name'] in users:
                        send(self.request, ['!trap', '=message=already exists'])
                        send(self.request, ['!done'])
                        continue
                    users[args['name']] = args
                    adds += 1
                    if drop_next:
                        drop_next = False
                        return
                    send(self.request, ['!done', '=ret=*' + str(adds)])
                else:
                    raise AssertionError('Unexpected RouterOS command: ' + cmd)
        except (EOFError, ConnectionError):
            pass

with socketserver.ThreadingTCPServer(('127.0.0.1', 0), Router) as router, tempfile.TemporaryDirectory() as tmp:
    threading.Thread(target=router.serve_forever, daemon=True).start()
    doc = Path(tmp) / 'app'
    shutil.copytree(ROOT / 'mikhmonv3-upstream', doc)
    sessions = Path(tmp) / 'sessions'
    sessions.mkdir()
    host = '127.0.0.1:' + str(router.server_address[1])
    (doc / 'include/config.php').write_text("<?php $data['test'] = array(1=>'test!" + host + "',2=>'test@|@admin',3=>'test#|#aWNlbA==');")
    (doc / 'fixture.php').write_text("""<?php
session_start();
$_SESSION = array('mikhmon'=>'admin','timezone'=>'UTC','mikhmon_last_activity'=>time(),'voucher_batch_csrf'=>str_repeat('a',64));
echo 'ready';
""")
    (doc / 'result.php').write_text("""<?php
session_start(); chdir(__DIR__);
$session='test'; $currency='USD'; $cekindo=array('indo'=>array('Rp')); $_GET=array('hotspot-user'=>'generate','voucher-result'=>'1');
class NoCalls { function comm($cmd,$args=array()) { throw new Exception('Result called the router'); } }
$API=new NoCalls(); include './hotspot/generateuser.php';
""")
    with socket.socket() as probe:
        probe.bind(('127.0.0.1', 0)); port = probe.getsockname()[1]
    logs = tempfile.TemporaryFile()
    proc = subprocess.Popen([PHP, '-n', '-d', 'session.save_path=' + str(sessions), '-S', '127.0.0.1:' + str(port), '-t', str(doc)], stdout=logs, stderr=logs)
    base = 'http://127.0.0.1:' + str(port)
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    try:
        for _ in range(100):
            try:
                opener.open(base + '/fixture.php', timeout=1).read()
                break
            except urllib.error.URLError:
                time.sleep(0.05)
        else:
            raise RuntimeError('PHP did not start')
        def post(action, job, **fields):
            payload = dict(action=action, job=job, router='test', csrf='a'*64)
            payload.update(fields)
            req = urllib.request.Request(base + '/process/voucher_batch.php', data=urllib.parse.urlencode(payload).encode())
            try:
                response = opener.open(req, timeout=2)  # Much shorter than total batch duration.
            except urllib.error.HTTPError as e:
                response = e
            return response.code, json.loads(response.read())
        params = dict(qty=200, user='vc', userl=8, char='mix2', profile='daily', server='all', prefix='', timelimit='', datalimit='', mbgb=1048576, adcomment='http')
        assert post('start', '1'*32, csrf='wrong', **params)[0] == 403
        assert post('start', '1'*32, router='test', **params)[0] == 200
        assert adds == 0, 'Start request must not contact router'
        assert post('start', '1'*32, **params)[1]['completed'] == 0
        delay = 0.1
        began = time.monotonic()
        steps = 0
        while True:
            before = adds
            code, result = post('step', '1'*32)
            assert code == 200, result
            assert adds - before <= 3, 'Unbounded HTTP step'
            steps += 1
            if result['done']:
                break
        elapsed = time.monotonic() - began
        assert adds == 200 and len(users) == 200 and result['completed'] == 200
        assert elapsed > 2, 'Whole batch must exceed the per-request client deadline'
        before = commands
        html = opener.open(base + '/result.php', timeout=2).read().decode()
        assert '200 vouchers generated successfully.' in html
        assert commands == before, 'Result page reconnected to router'
        assert 'voucher-batch.js?v=1' in html
        assert post('step', '1'*32)[1]['done'] and adds == 200
        print(f'PASS: 200 vouchers in {steps} HTTP steps, {elapsed:.1f}s total, every response within 2s; offline success page')
        # A dropped RouterOS add acknowledgement is reconciled using the same name.
        params['qty'] = 2
        post('start', '2'*32, **params)
        drop_next = True
        code, result = post('step', '2'*32)
        assert code == 400 and result['retryable'] and adds == 201, result
        assert post('step', '2'*32)[1]['done'] and adds == 202
        # A completed start replay after a newer job must remain a receipt.
        assert post('start', '1'*32, **params)[1]['done'] and adds == 202
        assert post('step', '2'*32, router='other')[0] == 404
        print('PASS: CSRF, idempotent starts, completed receipts, cross-router rejection and lost add acknowledgement')
    finally:
        proc.terminate()
        proc.wait(timeout=5)
        router.shutdown()
        logs.close()
