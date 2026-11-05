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
version = '7.20'

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
                elif cmd == '/system/resource/print':
                    send(self.request,['!re','=version='+version]);send(self.request,['!done'])
                elif cmd == '/ip/hotspot/print':
                    send(self.request,['!done'])
                elif cmd == '/ip/hotspot/user/profile/print':
                    send(self.request, ['!re', '=name=daily', '=on-login=,rem,10,1d,12,,Disable,'])
                    send(self.request, ['!done'])
                elif cmd == '/ip/hotspot/user/print':
                    for row in users.values():
                        if all(row.get(k)==v for k,v in args.items() if k in ('name','comment','profile')):
                            send(self.request, ['!re'] + ['=' + k + '=' + v for k, v in row.items()])
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
$_SESSION = array('mikhmon'=>'admin','timezone'=>'UTC','mikhmon_last_activity'=>time(),'voucher_batch_csrf'=>str_repeat('a',64),'transfer_csrf'=>str_repeat('a',64));
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
        def call(action, fields=None, file=None):
            fields=dict(fields or {},action=action,csrf='a'*64)
            if file is None:
                data=urllib.parse.urlencode(fields).encode(); headers={}
            else:
                boundary='ngwtestboundary'; parts=[]
                for key,value in fields.items(): parts.append(('--'+boundary+'\r\nContent-Disposition: form-data; name="'+key+'"\r\n\r\n'+str(value)+'\r\n').encode())
                parts.append(('--'+boundary+'\r\nContent-Disposition: form-data; name="file"; filename="users.json"\r\nContent-Type: application/json\r\n\r\n').encode()+json.dumps(file,ensure_ascii=False).encode()+b'\r\n')
                data=b''.join(parts)+('--'+boundary+'--\r\n').encode();headers={'Content-Type':'multipart/form-data; boundary='+boundary}
            request=urllib.request.Request(base+'/hotspot/usertransfer.php?session=test',data=data,headers=headers)
            try: response=opener.open(request,timeout=5)
            except urllib.error.HTTPError as e: response=e
            return response.code,json.loads(response.read())
        comment="vc-abc123def456-11.03.26-note,#&' বাংলা"
        users['source']={'name':'source','password':'secret','comment':comment,'profile':'daily','limit-uptime':'10h','uptime':'3h'}
        code, package=call('export',dict(target=6,filter='all'))
        # Protocol double filters by name only, so use all for this integration fixture.
        if code!=200: code,package=call('export',dict(target=6,filter='all'))
        assert code==200,package
        assert package['users'][0]['comment']==comment and 'source' in users
        code,bad=call('prepare',dict(profile='daily',server='all'),package)
        assert code==400 and 'OS' in bad['error'],bad
        version='6.49.23'
        users.clear()
        code,ready=call('prepare',dict(profile='daily',server='all'),package)
        assert code==200,ready
        code,done=call('step',dict(job=ready['job']))
        assert code==200 and done['added']==1,done
        assert users['source']['comment']==comment and 'limit-uptime' not in users['source'] and 'uptime' not in users['source']
        assert call('step',dict(job=ready['job']))[1]['added']==1
        code,ready=call('prepare',dict(profile='daily',server='all'),package)
        code,done=call('step',dict(job=ready['job']))
        assert done['skipped']==1 and adds==1,done
        print('PASS: real PHP export, OS7-to-OS6 destination validation, upload, exact comment, usage fields excluded, duplicate skip and repeat step')
    finally:
        proc.terminate()
        proc.wait(timeout=5)
        router.shutdown()
        logs.close()
