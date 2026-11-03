"""Read-only deployment checks: python infra/smoke.py 127.0.0.1 teryfoto"""
import http.client
import json
from pathlib import Path
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
    payload = response.read()
    status, body = response.status, ('' if response.getheader('Content-Type', '').startswith('image/') else payload.decode('utf-8'))
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
assert request('GET', '/?mail_worker=1')[0] == 404
portfolio_file = Path(__file__).resolve().parents[1] / 'app/data/portfolio.json'
if portfolio_file.exists():
    portfolio = json.loads(portfolio_file.read_text(encoding='utf-8'))
    for page in portfolio:
        query_key = 'page' if page['slug'] in ('o-me', 'contact', 'blog') else 'gallery'
        status, body = request('GET', '/?' + query_key + '=' + page['slug'])
        assert status == 200 and page['title'] in body
        if query_key == 'gallery':
            assert body.count(' data-photo=') == len(page['images'])
            assert re.findall(r'data-photo="([^"]+)"', body) == [photo['src'] for photo in page['images']]
            assert [index for row in page['rows'] for index in row['images']] == list(range(len(page['images'])))
            assert all(len(row['images']) == len(row['weights']) == len(row['ratios']) for row in page['rows'])
        for photo in page['images'] + [page['cover']]:
            assert (portfolio_file.parents[2] / 'public' / photo['src'].lstrip('/')).is_file()
        assert request('GET', page['cover']['src'])[0] == 200
    assert request('GET', '/?gallery=missing-gallery')[0] == 404
    assert request('GET', '/?page=missing-page')[0] == 404
for path in ['/storage/requests.sqlite', '/storage/admin.json', '/storage/initial-admin.txt', '/storage/smtp.json', '/storage/smtp.key', '/storage/mail-worker.token', '/vendor/autoload.php', '/app/bootstrap.php', '/.git/config']:
    assert request('GET', path)[0] in (403, 404)
print(host + ': OK (page, CSRF, validation, admin login, authorization, private files)')
