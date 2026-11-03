"""Create a consistent, private SQLite backup before updating the application."""
import datetime
import os
from pathlib import Path
import sqlite3
import sys

storage = Path(sys.argv[1] if len(sys.argv) > 1 else Path(__file__).resolve().parents[1] / 'storage').resolve()
source = storage / 'requests.sqlite'
if not source.is_file():
    raise SystemExit('Database does not exist; no backup required.')
target_dir = storage / 'backups'
target_dir.mkdir(mode=0o700, exist_ok=True)
target = target_dir / ('requests-' + datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%S%fZ') + '.sqlite')
with sqlite3.connect(source.as_uri() + '?mode=ro', uri=True) as origin:
    with sqlite3.connect(target) as backup:
        origin.backup(backup)
os.chmod(target, 0o600)
print('SQLite backup created: ' + target.name)
