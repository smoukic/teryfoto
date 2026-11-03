"""Cron-safe HTTP worker: no SMTP credentials or tokens in process arguments/logs."""
import http.client
from pathlib import Path
import sys

root, host = sys.argv[1:3]
token = (Path(root) / 'storage/mail-worker.token').read_text().strip()
connection = http.client.HTTPConnection('127.0.0.1', timeout=50)
connection.request('POST', '/?mail_worker=1', headers={'Host':host, 'Authorization':'Bearer '+token})
response = connection.getresponse()
response.read()
connection.close()
if response.status != 200:
    raise SystemExit('Teryfoto mail worker failed: HTTP ' + str(response.status))
