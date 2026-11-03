"""Install this site's one-minute worker without changing other cron entries."""
from pathlib import Path
import shlex
import subprocess
import sys

root = Path(__file__).resolve().parents[1]
host = sys.argv[1]
if host not in ('test.teryfoto.com', 'teryfoto.com'):
    raise SystemExit('Unsupported domain')
previous = subprocess.run(['crontab', '-l'], capture_output=True, text=True)
if previous.returncode and 'no crontab' not in previous.stderr.lower():
    raise SystemExit('Cannot read existing crontab')
marker = '# teryfoto-mail:' + host
lines = [line for line in previous.stdout.splitlines() if marker not in line]
command = ' '.join(shlex.quote(part) for part in ['/usr/bin/python3', str(root / 'infra/mail-worker.py'), str(root), host])
lines.append('* * * * * ' + command + ' >> ' + shlex.quote(str(root / 'storage/mail-worker.log')) + ' 2>&1 ' + marker)
subprocess.run(['crontab', '-'], input='\n'.join(lines) + '\n', text=True, check=True)
print('One-minute mail queue worker installed for ' + host)
