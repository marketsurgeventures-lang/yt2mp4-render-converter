#!/usr/bin/env python3
"""Own nginx, PHP-FPM and one conversion daemon in a single free web container."""
import os
from pathlib import Path
import signal
import subprocess
import time

BASE = Path(__file__).resolve().parent.parent

def stop_all(children):
    for process in children:
        if process.poll() is None:
            try:
                os.killpg(process.pid, signal.SIGTERM)
            except ProcessLookupError:
                pass
    deadline = time.monotonic() + 10
    while any(process.poll() is None for process in children) and time.monotonic() < deadline:
        time.sleep(.1)
    for process in children:
        if process.poll() is None:
            try:
                os.killpg(process.pid, signal.SIGKILL)
            except ProcessLookupError:
                pass
        process.wait()

def main():
    raw_port = os.environ.get('PORT', '10000')
    if not raw_port.isascii() or not raw_port.isdecimal() or not 1024 <= int(raw_port) <= 65535 or int(raw_port) in [18012, 18013, 19099]:
        raise SystemExit('PORT must be an unreserved TCP port between 1024 and 65535.')
    os.umask(0o077)
    (Path('/tmp')/'nginx').mkdir(mode=0o700,exist_ok=True)
    template=(BASE/'deploy/nginx.conf.template').read_text()
    config=Path('/tmp/nginx/converter.conf')
    config.write_text(template.replace('__PORT__',str(int(raw_port))))
    configured=subprocess.run(['php',str(BASE/'deploy/configure.php')])
    if configured.returncode not in [0,10]:
        raise SystemExit(configured.returncode)
    stopped=False
    def interrupt(signum, frame):
        nonlocal stopped
        stopped=True
    signal.signal(signal.SIGTERM,interrupt)
    signal.signal(signal.SIGINT,interrupt)
    commands=[]
    if configured.returncode==0:
        commands.append(['php',str(BASE/'backend/worker.php')])
    commands += [['php-fpm','-F'],['nginx','-c',str(config),'-g','daemon off;']]
    children=[]
    failure=False
    try:
        for command in commands:
            children.append(subprocess.Popen(command,start_new_session=True))
        while not stopped:
            if any(process.poll() is not None for process in children):
                print('A required service process stopped; shutting down the container.',flush=True)
                failure=True
                break
            time.sleep(.25)
    finally:
        stop_all(children)
    raise SystemExit(1 if failure else 0)

if __name__ == '__main__':
    main()
