#!/usr/bin/env python3
import hashlib
import http.cookiejar
import json
import os
import sys
import tempfile
import time

import requests


SESSION_MAX_AGE = 86400


def md5_custom(text):
    return hashlib.md5(text.encode('latin-1')).hexdigest()


def ensure_session_jar(device_key):
    session_dir = os.path.join(tempfile.gettempdir(), f'provisioner-phone-sessions-{os.geteuid()}')
    os.makedirs(session_dir, mode=0o700, exist_ok=True)
    os.chmod(session_dir, 0o700)

    now = time.time()
    for name in os.listdir(session_dir):
        path = os.path.join(session_dir, name)
        try:
            if os.path.isfile(path) and now - os.path.getmtime(path) > SESSION_MAX_AGE:
                os.remove(path)
        except OSError:
            pass

    safe_key = ''.join(c for c in device_key.lower() if c in '0123456789abcdef')
    if len(safe_key) != 12:
        raise ValueError('Invalid device session key')
    jar = os.path.join(session_dir, f'digium_{safe_key}.cookies')
    with open(jar, 'a'):
        pass
    os.chmod(jar, 0o600)
    return jar


def load_session_jar(path):
    jar = http.cookiejar.MozillaCookieJar(path)
    if os.path.getsize(path) > 0:
        try:
            jar.load(ignore_discard=True, ignore_expires=True)
        except (OSError, http.cookiejar.LoadError):
            pass
    return jar


def save_session_jar(jar):
    jar.save(ignore_discard=True, ignore_expires=True)
    os.chmod(jar.filename, 0o600)


def get_auth_cookie(jar):
    for cookie in jar:
        if cookie.name == 'auth':
            return cookie.value
    return None


def response_text(response):
    return response.content.decode('latin-1')


def is_digium_config(content):
    return bool(content and len(content) >= 100 and '<<VOIP CONFIG FILE>>' in content and '<<END OF FILE>>' in content)


def response_error(content):
    lower = (content or '').lower()
    if 'server too busy' in lower:
        return 'Phone web interface is busy; wait before retrying'
    if '403 forbidden' in lower:
        return 'login_required'
    if '<center>nginx</center>' in lower:
        return 'IP belongs to an nginx web server, not the phone'
    if '<title>logon</title>' in lower or ('logon' in lower[:500] and 'password' in lower[:400]):
        return 'login_required'
    if any(marker in lower for marker in ('<html', '<head', '<body', '<title>')):
        return 'Phone returned an HTML page instead of its config'
    return None


def fetch_config(ip, username, password, device_key, timeout=8):
    session = None
    try:
        jar = load_session_jar(ensure_session_jar(device_key))
        session = requests.Session()
        session.cookies = jar
        session.headers.update({
            'User-Agent': 'Mozilla/5.0 (X11; Linux x86_64; rv:128.0) Gecko/20100101 Firefox/128.0',
            'Accept': 'text/plain, text/html, application/xhtml+xml, */*;q=0.8',
            'Accept-Language': 'en-US,en;q=0.5',
            'Accept-Encoding': 'gzip, deflate',
            'Connection': 'keep-alive',
            'Referer': f'http://{ip}/',
        })

        existing = session.get(f"http://{ip}/config.txt", timeout=timeout, allow_redirects=True)
        existing_config = response_text(existing)
        save_session_jar(jar)
        if existing.status_code == 200 and is_digium_config(existing_config):
            return {"success": True, "config": existing_config, "session_reused": True}

        # Digium phones may return an HTML busy page before authentication.
        # Only a complete config proves the cached session is usable.
        jar.clear()
        nonce_page = session.get(f"http://{ip}/config.txt", timeout=timeout, allow_redirects=True)
        save_session_jar(jar)
        nonce = get_auth_cookie(jar)
        if not nonce:
            nonce_response = session.get(f"http://{ip}/key==nonce", timeout=timeout, allow_redirects=True)
            nonce = response_text(nonce_response).strip()[:16]
        if not nonce or len(nonce) < 10:
            return {"success": False, "error": "Phone did not provide an authentication nonce"}

        md5_hash = md5_custom(f"{username}:{password}:{nonce}")
        session.post(
            f"http://{ip}/",
            data={'encoded': f"{username}:{md5_hash}", 'ReturnPage': '/'},
            timeout=timeout,
            allow_redirects=True,
        )
        save_session_jar(jar)

        response = session.get(f"http://{ip}/config.txt", timeout=timeout, allow_redirects=True)
        config = response_text(response)
        save_session_jar(jar)
        if response.status_code == 200 and is_digium_config(config):
            return {"success": True, "config": config, "session_reused": False}

        error = response_error(config)
        if error == 'login_required':
            jar.clear()
            save_session_jar(jar)
            return {"success": False, "error": "Auth failed — check credentials"}
        if error:
            return {"success": False, "error": error}
        if response.status_code != 200:
            return {"success": False, "error": f"Phone returned HTTP {response.status_code}"}
        return {"success": False, "error": "Invalid Digium config format"}
    except requests.exceptions.Timeout:
        return {"success": False, "error": f"Connection to phone at {ip} timed out"}
    except requests.exceptions.ConnectionError:
        return {"success": False, "error": f"Could not connect to phone at {ip}"}
    except Exception as e:
        return {"success": False, "error": f"Unexpected error: {str(e)}"}
    finally:
        if session is not None:
            session.close()


if __name__ == "__main__":
    if len(sys.argv) < 5:
        print(json.dumps({"success": False, "error": "Usage: fetch_digium_config.py <ip> <username> <password> <device_key>"}))
        sys.exit(1)
    ip = sys.argv[1]
    username = sys.argv[2]
    password = sys.argv[3]
    device_key = sys.argv[4]
    result = fetch_config(ip, username, password, device_key)
    print(json.dumps(result))
    sys.exit(0 if result["success"] else 1)
