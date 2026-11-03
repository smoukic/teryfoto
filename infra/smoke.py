"""Read-only deployment checks: python infra/smoke.py 127.0.0.1 teryfoto"""
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
status, body = request('GET', '/?admin=1')
assert status == 200 and 'Přihlášení' in body and 'reservation-list' not in body
assert request('POST', '/?admin=1', {'csrf': token, 'action': 'save_slot'})[0] == 401
for path in ['/storage/requests.sqlite', '/storage/admin.json', '/storage/initial-admin.txt', '/app/bootstrap.php', '/.git/config']:
    assert request('GET', path)[0] in (403, 404)
print(host + ': OK (page, CSRF, validation, admin login, authorization, private files)')
