#!/usr/bin/env python3
"""Opt-in integration tests in a new network namespace and private MariaDB.

Run: unshare --net -- python3 tests/run_mariadb.py
Never reads host MariaDB configuration or connects to its default socket.
"""
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import time


def main():
    if os.readlink('/proc/self/ns/net') == os.readlink('/proc/1/ns/net'):
        raise SystemExit('Refusing to run outside a separate network namespace. Use unshare --net.')
    root = Path(__file__).resolve().parent.parent
    directory = Path(tempfile.mkdtemp(prefix='provisioner-mariadb-', dir='/tmp/opencode'))
    data = directory / 'data'
    data.mkdir(mode=0o700)
    socket = directory / 'db.sock'
    print('Isolated integration directory:', directory, flush=True)
    env = dict(os.environ, TMPDIR=str(directory),
               PROVISIONER_DISCOVERY_DIRECTORY=str(directory / 'discovery'),
               PROVISIONER_MARIADB_TEST_DIRECTORY=str(directory))
    server = None
    try:
        initializer = shutil.which('mariadb-install-db') or shutil.which('mysql_install_db')
        daemon = shutil.which('mariadbd') or shutil.which('mysqld')
        if not daemon and Path('/usr/libexec/mysqld').is_file():
            daemon = '/usr/libexec/mysqld'
        if not initializer or not daemon:
            raise RuntimeError('Existing MariaDB initializer/daemon required; no packages are installed by this test.')
        initialize = [initializer, '--no-defaults', '--user=root', '--datadir=' + str(data)]
        if Path(initializer).name == 'mariadb-install-db':
            initialize += ['--auth-root-authentication-method=normal', '--skip-test-db']
        with (directory / 'initialize.log').open('w') as log:
            subprocess.run(initialize, stdout=log, stderr=subprocess.STDOUT,
                           check=True, timeout=60, env=env)
        with (directory / 'server-output.log').open('w') as log:
            server = subprocess.Popen([daemon, '--no-defaults', '--user=root',
                '--datadir=' + str(data), '--socket=' + str(socket),
                '--pid-file=' + str(directory / 'db.pid'), '--skip-networking',
                '--skip-log-bin', '--innodb-buffer-pool-size=32M',
                '--innodb-log-file-size=8M', '--max-connections=12',
                '--performance-schema=OFF', '--tmpdir=' + str(directory),
                '--log-error=' + str(directory / 'server.log')],
                stdout=log, stderr=subprocess.STDOUT, env=env)
        deadline = time.monotonic() + 30
        while not socket.exists():
            if server.poll() is not None or time.monotonic() >= deadline:
                raise RuntimeError('Private MariaDB failed to start; see ' + str(directory / 'server.log'))
            time.sleep(0.1)
        with (directory / 'tests.log').open('w') as log:
            result = subprocess.run(['php', str(root / 'tests/test_mariadb.php')],
                stdout=log, stderr=subprocess.STDOUT, env=env, timeout=240)
        print((directory / 'tests.log').read_text(), end='', flush=True)
        if result.returncode:
            raise SystemExit(result.returncode)
    finally:
        if server is not None and server.poll() is None:
            server.terminate()
            try:
                server.wait(timeout=20)
            except subprocess.TimeoutExpired:
                server.kill()
                server.wait()
        # Only discard this runner's fresh throwaway database; keep test logs.
        shutil.rmtree(data)
        print('Private database stopped; logs retained in', directory, flush=True)


if __name__ == '__main__':
    main()
