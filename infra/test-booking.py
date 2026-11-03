"""Isolated end-to-end tests; no production data or accounts are used."""
import concurrent.futures
import datetime
import http.client
import json
import os
from pathlib import Path
import re
import socket
import sqlite3
import subprocess
import tempfile
import time
import urllib.parse

ROOT = Path(__file__).resolve().parents[1]

class Client:
    def __init__(self, port):
        self.port, self.cookie, self.token = port, '', ''

    def request(self, method='GET', path='/', data=None):
        c = http.client.HTTPConnection('127.0.0.1', self.port, timeout=15)
        headers = {'Cookie': self.cookie}
        if data is not None:
            headers['Content-Type'] = 'application/x-www-form-urlencoded'
            data = urllib.parse.urlencode(data)
        c.request(method, path, data, headers)
        r = c.getresponse()
        if r.getheader('Set-Cookie'):
            self.cookie = r.getheader('Set-Cookie').split(';')[0]
        body = r.read().decode()
        token = re.search(r'name="csrf" value="([a-f0-9]+)"', body)
        if token:
            self.token = token[1]
        result = r.status, body
        c.close()
        return result

    def post(self, data, admin=True):
        return self.request('POST', '/?admin=1' if admin else '/', dict(csrf=self.token, **data))

def check(condition, message):
    if not condition:
        raise AssertionError(message)

with tempfile.TemporaryDirectory(prefix='booking-test-', dir=ROOT / 'storage') as temp:
    env = dict(os.environ, TERYFOTO_STORAGE=temp)
    dbfile = Path(temp) / 'requests.sqlite'
    sql = sqlite3.connect(dbfile)
    sql.execute('CREATE TABLE requests (id INTEGER PRIMARY KEY,name TEXT NOT NULL,email TEXT NOT NULL,requested_date TEXT NOT NULL,type TEXT NOT NULL,note TEXT NOT NULL,created_at TEXT NOT NULL)')
    sql.execute("INSERT INTO requests VALUES (1,'Legacy','legacy@example.invalid','2027-01-01','Portrét','old request','2026-01-01T00:00:00Z')")
    sql.commit()
    subprocess.run(['php', 'infra/create-admin.php'], cwd=ROOT, env=env, check=True, capture_output=True)
    credentials = (Path(temp) / 'initial-admin.txt').read_text(encoding='utf-8')
    password = re.search(r'Heslo: (.+)', credentials)[1]
    with socket.socket() as free:
        free.bind(('127.0.0.1', 0))
        port = free.getsockname()[1]
    with open(Path(temp) / 'server.log', 'w') as log:
        server = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', 'public'], cwd=ROOT, env=env, stdout=log, stderr=log)
        try:
            for _ in range(100):
                try:
                    with socket.create_connection(('127.0.0.1', port), timeout=.2):
                        break
                except OSError:
                    time.sleep(.05)
            admin = Client(port)
            check(admin.request(path='/?admin=1')[0] == 200, 'login page')
            check(admin.post(dict(action='save_slot'))[0] == 401, 'unauthenticated mutation denied')
            check(admin.request('POST', '/?admin=1', dict(action='login', csrf='invalid'))[0] == 400, 'admin CSRF')
            check(admin.post(dict(action='login', username='fotografka', password='wrong'))[0] == 401, 'bad login')
            check(admin.post(dict(action='login', username='fotografka', password=password))[0] == 303, 'login')
            check('Všechno má' in admin.request(path='/?admin=1')[1], 'dashboard')
            day = datetime.date.today() + datetime.timedelta(days=30)
            def slot(hour, end, place='Park'):
                return dict(action='save_slot', starts_at=f'{day}T{hour}:00', ends_at=f'{day}T{end}:00', location=place)
            check(admin.post(slot('10', '11'))[0] == 303, 'create slot')
            check('překrývá' in admin.post(slot('10', '12'))[1], 'overlap denied')
            check(admin.post(slot('12', '13'))[0] == 303, 'second slot')
            check('Zadejte budoucí' in admin.post(dict(action='save_slot', starts_at='2020-01-01T12:00', ends_at='2020-01-01T13:00'))[1], 'past slot denied')
            public = Client(port)
            check('Park' in public.request()[1], 'available slot appears publicly')
            booking = dict(name='<script>alert(1)</script>', email='test@example.invalid', type='Portrét', slot_id=1, note='Test', consent='on')
            check(public.request('POST', '/', dict(booking, csrf='wrong'))[0] == 400, 'public CSRF')
            check(public.post(dict(booking, email='bad'), admin=False)[0] == 422, 'validation')
            check(public.post(booking, admin=False)[0] == 303, 'create booking')
            check('Rezervace je uložená' in public.request()[1], 'success notice')
            second = Client(port)
            second.request()
            check(second.post(booking, admin=False)[0] == 422, 'double booking prevented')
            check(public.post(booking, admin=False)[0] == 429, 'session throttle')
            check(sql.execute('SELECT status,slot_id FROM requests WHERE id=1').fetchone() == ('pending', None), 'legacy migration preserves data')
            check('Obsazený termín' in admin.post(dict(slot('10', '11'), slot_id=1))[1], 'occupied slot cannot be moved')
            check('Obsazený termín' in admin.post(dict(action='toggle_slot', slot_id=1))[1], 'occupied slot cannot be closed')
            html = admin.request(path='/?admin=1')[1]
            check('&lt;script&gt;' in html and '<script>alert(1)</script>' not in html, 'stored XSS escaped')
            check(admin.post(dict(action='status', request_id=2, status='confirmed', admin_note='Confirmed by phone'))[0] == 303, 'confirmation')
            check(sql.execute('SELECT status FROM requests WHERE id=2').fetchone()[0] == 'confirmed', 'confirmation persisted')
            check(admin.post(dict(action='status', request_id=2, status='rejected', admin_note=''))[0] == 303, 'rejection')
            check(second.post(booking, admin=False)[0] == 303, 'rejected slot becomes available')
            check('jinou rezervaci' in admin.post(dict(action='status', request_id=2, status='confirmed', admin_note=''))[1], 'cannot confirm over another booking')
            check(admin.post(dict(action='status', request_id=3, status='cancelled', admin_note=''))[0] == 303, 'cancellation')
            check(admin.post(dict(action='assign', request_id=1, slot_id=2))[0] == 303, 'legacy assignment')
            check(admin.post(dict(action='status', request_id=1, status='confirmed', admin_note=''))[0] == 303, 'legacy confirmation')
            check(admin.post(dict(action='toggle_slot', slot_id=1))[0] == 303, 'close free slot')
            check(not re.search(r'data-package="[^"]+" value="1"', Client(port).request()[1]), 'closed slot hidden')
            check(admin.post(dict(action='toggle_slot', slot_id=1))[0] == 303, 'reopen slot')
            # Race two independent PHP processes, using the same SQLite database.
            racer = Path(temp) / 'race.php'
            racer.write_text('<?php require ' + json.dumps(str(ROOT / 'app/bootstrap.php').replace('\\', '/')) + '; try {create_booking(["name"=>"Race test","email"=>"race@example.invalid","type"=>"Portrét","slot_id"=>1,"consent"=>1]);echo "booked";} catch (DomainException $e) {echo "unavailable";}', encoding='utf-8')
            with concurrent.futures.ThreadPoolExecutor(2) as pool:
                results = list(pool.map(lambda _: subprocess.run(['php', str(racer)], env=env, capture_output=True, text=True, check=True).stdout, range(2)))
            check(sorted(results) == ['booked', 'unavailable'], 'concurrent booking is exclusive')
            check(Client(port).request(path='/?mail_worker=1')[0] == 404, 'mail worker not publicly accessible')
            check(admin.post(dict(action='test_smtp'))[0] == 200, 'SMTP test without credentials stays in admin')
            check('Nejprve uložte SMTP' in admin.post(dict(action='test_smtp'))[1], 'SMTP test requires configured account')
            settings = dict(action='save_smtp', host='wes1-smtp.wedos.net', port=587, encryption='tls', username='sender@example.invalid', smtp_password='test-private-password', from_email='sender@example.invalid', from_name='Teryfoto', photographer_email='terezabradacova0@gmail.com', test_email='jan@rulc.eu', test_copy='on')
            check(admin.post(settings)[0] == 303, 'save SMTP settings through admin')
            smtp_page = admin.request(path='/?admin=1&section=smtp')[1]
            check('E-maily a SMTP' in smtp_page and 'test-private-password' not in smtp_page, 'SMTP password never rendered')
            check('test-private-password' not in (Path(temp)/'smtp.json').read_text(), 'SMTP secret encrypted on disk')
            check('Zkontrolujte SMTP' in admin.post(dict(settings,host='smtp.example.invalid;other.example.invalid'))[1], 'SMTP host injection rejected')
            anonymous = Client(port)
            anonymous.request(path='/?admin=1')
            check(anonymous.post(settings)[0] == 401, 'SMTP settings require authentication')
            subprocess.run(['php','infra/init-mail.php'],cwd=ROOT,env=env,check=True,capture_output=True)
            # Package-specific slots and individual enquiries.
            future = day + datetime.timedelta(days=1)
            check(admin.post(dict(action='save_slot', starts_at=f'{future}T10:00', package_id=1, count=2))[0] == 303, 'mini batch')
            mini = sql.execute('SELECT id,starts_at,ends_at FROM slots WHERE package_id=1 ORDER BY starts_at').fetchall()
            check(len(mini)==2 and mini[0][2].endswith('11:00:00') and mini[1][2].endswith('12:00:00'), 'mini has one-hour duration')
            check(admin.post(dict(action='save_slot', starts_at=f'{future}T12:00', package_id=2, count=2))[0]==303, 'large batch')
            large = sql.execute('SELECT id,ends_at FROM slots WHERE package_id=2 ORDER BY starts_at').fetchall()
            check(large[0][1].endswith('14:00:00') and large[1][1].endswith('16:00:00'), 'large has two-hour duration')
            check(admin.post(dict(action='save_slot', starts_at=f'{future}T16:00', count=1))[0]==303, 'unpackaged slot')
            check(sql.execute('SELECT ends_at FROM slots WHERE starts_at=?',(f'{future} 16:00:00',)).fetchone()[0].endswith('18:00:00'), 'unpackaged defaults to two hours')
            before = sql.execute('SELECT COUNT(*) FROM slots').fetchone()[0]
            check('překrývá' in admin.post(dict(action='save_slot',starts_at=f'{future}T08:00',package_id=1,count=4))[1], 'overlap in batch rejected')
            check(sql.execute('SELECT COUNT(*) FROM slots').fetchone()[0]==before, 'batch rollback is atomic')
            check('individuální' in admin.post(dict(action='save_slot',starts_at=f'{future}T20:00',package_id=3))[1], 'brand cannot have slots')
            customer=Client(port); customer.request()
            check(customer.post(dict(booking,package_id=1,slot_id=large[0][0]),admin=False)[0]==422,'package slot mismatch rejected')
            check(customer.post(dict(booking,package_id=1,slot_id=mini[0][0]),admin=False)[0]==303,'mini booking')
            mini_request=sql.execute('SELECT id,package_name FROM requests WHERE slot_id=?',(mini[0][0],)).fetchone()
            check(mini_request[1]=='Minibalíček','package name snapshot')
            brand=Client(port); brand.request()
            check(brand.post(dict(booking,package_id=3,slot_id='',note=''),admin=False)[0]==422,'brand requires description')
            check(brand.post(dict(booking,package_id=3,slot_id=mini[1][0],note='Brand photography'),admin=False)[0]==422,'brand must not reserve a timed slot')
            check(brand.post(dict(booking,package_id=3,slot_id='',note='Brand photography'),admin=False)[0]==303,'individual request without time')
            brand_id=sql.execute('SELECT id FROM requests WHERE is_individual=1').fetchone()[0]
            check(admin.post(dict(action='quick_status',request_id=brand_id,status='confirmed'))[0]==303,'brand quick confirmation')
            check(sql.execute('SELECT status FROM requests WHERE id=?',(brand_id,)).fetchone()[0]=='confirmed','brand confirmed without slot')
            dashboard=admin.request(path='/?admin=1')[1]
            check('žádostí čeká na vaše potvrzení' in dashboard,'prominent pending alert')
            check('name="host"' not in dashboard,'SMTP form separated from reservations')
            check('Minibalíček' in admin.request(path='/?admin=1&section=packages')[1],'package admin section')
            check('Počet navazujících termínů' in admin.request(path='/?admin=1&section=slots')[1],'slot admin section')
            check('délku ani způsob' in admin.post(dict(action='save_package',package_id=1,name='Minibalíček',duration_minutes=90,is_active='on'))[1],'cannot alter duration with future slots')
            check(admin.post(dict(action='save_package',name='Nový balíček',description='Custom',duration_minutes=90,is_active='on'))[0]==303,'create custom package')
            check(admin.post(dict(action='save_package',package_id=1,name='Mini nové jméno',duration_minutes=60))[0]==303,'hide package')
            hidden=Client(port); hidden.request()
            check(hidden.post(dict(booking,package_id=1,slot_id=mini[1][0]),admin=False)[0]==422,'hidden package not bookable')
            check(sql.execute('SELECT package_name FROM requests WHERE id=?',(mini_request[0],)).fetchone()[0]=='Minibalíček','saved booking keeps original package name')
            other = Client(port)
            other.request(path='/?admin=1')
            other.post(dict(action='login', username='fotografka', password=password))
            other.request(path='/?admin=1')
            check(admin.post(dict(action='password', current_password=password, new_password='A-new-test-password-2026', repeat_password='A-new-test-password-2026'))[0] == 303, 'password update')
            check('Přihlášení' in other.request(path='/?admin=1')[1], 'password change invalidates other sessions')
            admin.request(path='/?admin=1')
            check(admin.post(dict(action='logout'))[0] == 303, 'logout')
            check('Přihlášení' in admin.request(path='/?admin=1')[1], 'logged out')
            limiter = Client(port)
            limiter.request(path='/?admin=1')
            for _ in range(10):
                limiter.post(dict(action='login', username='fotografka', password='bad'))
            check(limiter.post(dict(action='login', username='fotografka', password='bad'))[0] == 429, 'persistent login throttling')
            print('PASS: migration, login, CSRF, slots, overlap, validation, booking, concurrency, confirmation, cancellation, legacy assignment, packages, durations, atomic batches, individual Brand, package validation, dashboard alerts, separate SMTP, XSS, SMTP administration, secret encryption, SMTP authorization, worker protection, password change, session invalidation, login throttling')
        finally:
            sql.close()
            server.terminate()
            server.wait(timeout=10)
