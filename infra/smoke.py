"""HTTP checks: python infra/smoke.py 127.0.0.1 teryfoto"""
import datetime
import http.client
import re
import sys
import urllib.parse

address, host = sys.argv[1:3]
cookie = ''
def request(method, path, data=None):
    global cookie
    connection = http.client.HTTPConnection(address, timeout=15)
    headers = {'Host': host, 'Cookie': cookie}
    if data is not None:
        data = urllib.parse.urlencode(data)
        headers['Content-Type'] = 'application/x-www-form-urlencoded'
    connection.request(method, path, body=data, headers=headers)
    response = connection.getresponse()
    if response.getheader('Set-Cookie'):
        cookie = response.getheader('Set-Cookie').split(';')[0]
    status, body = response.status, response.read().decode('utf-8')
    connection.close()
    return status, body

status, body = request('GET', '/')
assert status == 200 and 'Teryfoto' in body
token = re.search(r'name="csrf" value="([a-f0-9]+)"', body)[1]
assert request('POST', '/', {'csrf': 'wrong'})[0] == 400
assert request('POST', '/', {'csrf': token})[0] == 422
data = dict(csrf=token, name='Automated smoke test', email='smoke@example.invalid',
            date=str(datetime.date.today() + datetime.timedelta(days=30)),
            type='Portrét', note='Testovací poptávka – kontrola nasazení.', consent='on')
assert request('POST', '/', data)[0] == 303
assert 'Poptávka je uložená' in request('GET', '/?sent=1')[1]
assert request('POST', '/', data)[0] == 429
for path in ['/storage/requests.sqlite', '/.git/config']:
    assert request('GET', path)[0] in (403, 404)
print(host + ': OK (page, CSRF, validation, SQLite insert, confirmation, throttle, private files)')
