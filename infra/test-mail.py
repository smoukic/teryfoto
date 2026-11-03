"""Real SMTP/TLS integration tests, confined to localhost and temporary data."""
import base64
import datetime
import email
from email.policy import default
import http.client
import json
import os
from pathlib import Path
import re
import shutil
import socket
import socketserver
import sqlite3
import ssl
import subprocess
import tempfile
import threading
import time
import urllib.parse

ROOT = Path(__file__).resolve().parents[1]
OPENSSL = shutil.which('openssl') or 'C:/xampp/apache/bin/openssl.exe'

class FakeSMTP(socketserver.StreamRequestHandler):
    def handle(self):
        self.request = self.server.context.wrap_socket(self.request, server_side=True)
        self.rfile = self.request.makefile('rb')
        self.wfile = self.request.makefile('wb')
        def reply(text):
            self.wfile.write((text + '\r\n').encode()); self.wfile.flush()
        reply('220 localhost Test SMTP')
        recipients = []
        authenticated = False
        while True:
            line = self.rfile.readline().decode().strip()
            if not line:
                break
            verb = line.split()[0].upper()
            if verb in ('EHLO', 'HELO'):
                reply('250-localhost\r\n250 AUTH PLAIN LOGIN')
            elif verb == 'AUTH':
                parts = line.split()
                if parts[1] == 'PLAIN':
                    if len(parts) < 3:
                        reply('334 '); token = self.rfile.readline().strip()
                    else:
                        token = parts[2]
                    decoded = base64.b64decode(token).split(b'\x00')
                    authenticated = decoded[-2:] == [b'sender@example.invalid', b'smtp-test-secret']
                else:
                    reply('334 VXNlcm5hbWU6'); username = base64.b64decode(self.rfile.readline().strip())
                    reply('334 UGFzc3dvcmQ6'); password = base64.b64decode(self.rfile.readline().strip())
                    authenticated = username == b'sender@example.invalid' and password == b'smtp-test-secret'
                reply('235 Authenticated' if authenticated else '535 Authentication failed')
            elif verb == 'MAIL':
                recipients = []; reply('250 OK' if authenticated else '530 Authenticate first')
            elif verb == 'RCPT':
                recipients.append(line); reply('250 OK')
            elif verb == 'DATA':
                reply('354 End with dot')
                data = b''
                while True:
                    raw = self.rfile.readline()
                    if raw in (b'.\r\n', b''):
                        break
                    data += raw[1:] if raw.startswith(b'..') else raw
                with self.server.guard:
                    if self.server.fail_next:
                        self.server.fail_next = False
                        reply('451 Temporary test failure')
                    else:
                        self.server.messages.append((recipients, email.message_from_bytes(data, policy=default)))
                        reply('250 Message accepted')
            elif verb == 'QUIT':
                reply('221 Bye'); break
            elif verb in ('RSET', 'NOOP'):
                reply('250 OK')
            else:
                reply('500 Unknown command')

class Server(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True

def check(condition, message):
    if not condition:
        raise AssertionError(message)

with tempfile.TemporaryDirectory(prefix='mail-test-', dir=ROOT / 'storage') as temp:
    temp = Path(temp)
    cert, key = temp / 'cert.pem', temp / 'key.pem'
    conf = temp / 'openssl.cnf'
    conf.write_text('[req]\ndistinguished_name=dn\n[dn]\n', encoding='ascii')
    subprocess.run([OPENSSL,'req','-x509','-newkey','rsa:2048','-nodes','-keyout',str(key),'-out',str(cert),'-days','1','-subj','/CN=localhost','-addext','subjectAltName=DNS:localhost,IP:127.0.0.1','-config',str(conf)], check=True, capture_output=True)
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.load_cert_chain(cert, key)
    env = dict(os.environ, TERYFOTO_STORAGE=str(temp))
    php = ['php','-d','openssl.cafile='+str(cert)]
    helper = temp / 'helper.php'
    helper.write_text('<?php require '+json.dumps(str(ROOT / 'app/bootstrap.php').replace('\\','/'))+''';
    $d=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
    $result=null;
    switch($d['op']) {
        case 'slot': save_slot($d['data']); break;
        case 'book': $result=create_booking($d['data']); break;
        case 'status': set_booking_status($d['id'],$d['status'],$d['note']??''); break;
        case 'process': $result=process_mail_queue(10); break;
        case 'smtp': save_smtp($d['data']); break;
    }
    echo json_encode($result);
    ''', encoding='utf-8')
    def run(op, **data):
        result = subprocess.run(php+[str(helper)],input=json.dumps(dict(op=op,**data)),env=env,capture_output=True,text=True)
        check(result.returncode == 0, 'PHP helper: '+result.stderr)
        return json.loads(result.stdout)
    with Server(('127.0.0.1',0), FakeSMTP) as smtp:
        smtp.context, smtp.messages, smtp.fail_next, smtp.guard = context, [], False, threading.Lock()
        thread = threading.Thread(target=smtp.serve_forever,daemon=True); thread.start()
        day = datetime.date.today()+datetime.timedelta(days=60)
        run('slot',data=dict(starts_at=f'{day}T10:00', ends_at=f'{day}T11:00', location='Testovací park'))
        booking = dict(name='Testovací klient',email='client@example.invalid',type='Portrét',slot_id=1,note='Zpráva klienta',consent='on')
        id = run('book',data=booking)
        db = sqlite3.connect(temp / 'requests.sqlite')
        check(db.execute('SELECT COUNT(*) FROM mail_outbox').fetchone()[0] == 4, 'photographer notifications and client receipts queued atomically')
        check(run('process') == {'sent':0,'failed':0} and not smtp.messages, 'disabled SMTP sends nothing')
        settings = dict(host='localhost',port=smtp.server_address[1],encryption='ssl',username='sender@example.invalid',smtp_password='smtp-test-secret',from_email='sender@example.invalid',from_name='Teryfoto',photographer_email='photographer@example.invalid',test_email='tester@example.invalid',test_copy='on',enabled='on')
        run('smtp',data=settings)
        check('smtp-test-secret' not in (temp/'smtp.json').read_text(), 'stored SMTP password encrypted')
        # Existing messages keep the recipients chosen when their event occurred.
        check(run('process') == {'sent':4,'failed':0}, 'TLS SMTP delivery with authentication')
        check([str(m['To']) for _,m in smtp.messages] == ['terezabradacova0@gmail.com','jan@rulc.eu','client@example.invalid','jan@rulc.eu'], 'requested default recipients')
        check('nikoli závazné potvrzení' in smtp.messages[2][1].get_content(), 'receipt clearly differs from final confirmation')
        check(all(len(r) == 1 for r,m in smtp.messages), 'recipients sent separately')
        check('Testovací klient' in smtp.messages[0][1].get_content(), 'UTF-8 body')
        run('status',id=id,status='confirmed',note='PRIVATE internal note')
        check(run('process')['sent'] == 2, 'client confirmation and test copy')
        check([str(m['To']) for _,m in smtp.messages[-2:]] == ['client@example.invalid','tester@example.invalid'], 'client recipients')
        check('PRIVATE' not in smtp.messages[-1][1].get_content(), 'internal note never sent')
        before = db.execute('SELECT COUNT(*) FROM mail_outbox').fetchone()[0]
        run('status',id=id,status='confirmed',note='updated internal note')
        check(db.execute('SELECT COUNT(*) FROM mail_outbox').fetchone()[0] == before, 'same status does not duplicate email')
        run('status',id=id,status='rejected')
        smtp.fail_next=True
        check(run('process') == {'sent':1,'failed':1}, 'SMTP failure isolated per recipient')
        check(db.execute('SELECT status FROM requests WHERE id=?',(id,)).fetchone()[0] == 'rejected', 'SMTP failure does not roll back reservation')
        check(run('process') == {'sent':0,'failed':0}, 'retry backoff')
        db.execute("UPDATE mail_outbox SET next_attempt=0 WHERE status='failed'"); db.commit()
        check(run('process')['sent'] == 1, 'failed message retried')
        check(all('smtp-test-secret' not in str(v) for row in db.execute('SELECT last_error FROM mail_outbox') for v in row), 'errors contain no SMTP password')
        run('status',id=id,status='confirmed')
        run('status',id=id,status='rejected')
        check(db.execute("SELECT COUNT(*) FROM mail_outbox WHERE event_kind='confirmed' AND status='cancelled'").fetchone()[0] == 2, 'obsolete unsent confirmation cancelled')
        # Updating with an empty password preserves the encrypted secret.
        run('smtp',data=dict(settings,smtp_password='',test_copy=''))
        check(run('process')['sent'] == 2, 'blank password retains saved secret')
        run('status',id=id,status='confirmed')
        check(run('process')['sent'] == 1, 'test-copy switch stops new copies')
        # Wrong credentials fail, with TLS validation left enabled.
        run('smtp',data=dict(settings,smtp_password='wrong-secret'))
        run('status',id=id,status='rejected')
        check(run('process') == {'sent':0,'failed':2}, 'authentication failure tracked')
        check(db.execute('PRAGMA integrity_check').fetchone()[0]=='ok','database integrity')
        db.close(); smtp.shutdown(); thread.join(timeout=5)
        print('PASS: atomic outbox, default recipients, encrypted secret, real verified TLS SMTP, authentication, UTF-8, isolated recipients, decision messages, no internal notes, no duplicates, retry/backoff, superseded messages, password preservation, test-copy switch, SMTP failure isolation')
