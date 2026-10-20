#!/usr/bin/env python3
import json
import socket
import sys
import xml.etree.ElementTree as ET
import requests

requests.packages.urllib3.disable_warnings()


def phone_service_available(ip, timeout=3):
    try:
        with socket.create_connection((ip, 443), timeout=timeout):
            return True
    except OSError:
        return False


def fetch_config(ip, username, password, timeout=10):
    base_url = f"https://{ip}"
    session = requests.Session()
    session.verify = False

    try:
        if not phone_service_available(ip):
            return {"success": False, "error": f"Phone unavailable at {ip}"}

        r = session.post(f"{base_url}/wbm/api/account", json={
            "method": "login",
            "param": {"username": username, "password": password, "token": None},
            "sid": 0
        }, timeout=timeout)
        auth = r.json()
        if not auth.get("success"):
            return {"success": False, "error": "Auth failed — check credentials"}
        sid = auth["sid"]

        r = session.post(f"{base_url}/wbm/api/setting-all.xml", json={
            "method": "download",
            "sid": sid
        }, timeout=timeout)

        config = r.text.strip()
        if not config.startswith("<?xml"):
            return {"success": False, "error": "Unexpected response — not XML"}
        try:
            ET.fromstring(config)
        except ET.ParseError:
            return {"success": False, "error": "Invalid Alcatel XML config"}
        if any(marker in config.lower() for marker in ("<html", "<head", "<body", "403 forbidden", "nginx")):
            return {"success": False, "error": "Received HTML/error response instead of Alcatel XML"}
        return {"success": True, "config": config}

    except requests.exceptions.ConnectionError:
        return {"success": False, "error": f"Could not connect to phone at {ip}"}
    except requests.exceptions.Timeout:
        return {"success": False, "error": f"Connection to phone at {ip} timed out"}
    except (KeyError, json.JSONDecodeError) as e:
        return {"success": False, "error": f"Unexpected auth response: {str(e)}"}
    except Exception as e:
        return {"success": False, "error": f"Unexpected error: {str(e)}"}


if __name__ == "__main__":
    if len(sys.argv) < 4:
        print(json.dumps({"success": False, "error": "Usage: fetch_alcatel_config.py <ip> <username> <password>"}))
        sys.exit(1)
    ip = sys.argv[1]
    username = sys.argv[2]
    password = sys.argv[3]
    result = fetch_config(ip, username, password)
    print(json.dumps(result))
    sys.exit(0 if result["success"] else 1)
