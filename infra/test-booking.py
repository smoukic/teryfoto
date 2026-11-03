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
            check(not re.search(r'<option value="1"', Client(port).request()[1]), 'closed slot hidden')
            check(admin.post(dict(action='toggle_slot', slot_id=1))[0] == 303, 'reopen slot')
            # Race two independent PHP processes, using the same SQLite database.
            racer = Path(temp) / 'race.php'
            racer.write_text('<?php require ' + json.dumps(str(ROOT / 'app/bootstrap.php').replace('\\', '/')) + '; try {create_booking(["name"=>"Race test","email"=>"race@example.invalid","type"=>"Portrét","slot_id"=>1,"consent"=>1]);echo "booked";} catch (DomainException $e) {echo "unavailable";}', encoding='utf-8')
            with concurrent.futures.ThreadPoolExecutor(2) as pool:
                results = list(pool.map(lambda _: subprocess.run(['php', str(racer)], env=env, capture_output=True, text=True, check=True).stdout, range(2)))
            check(sorted(results) == ['booked', 'unavailable'], 'concurrent booking is exclusive')
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
            print('PASS: migration, login, CSRF, slots, overlap, validation, booking, concurrency, confirmation, cancellation, legacy assignment, XSS, password change, session invalidation, login throttling')
        finally:
            sql.close()
            server.terminate()
            server.wait(timeout=10)
