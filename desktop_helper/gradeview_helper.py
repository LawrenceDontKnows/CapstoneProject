#!/usr/bin/env python3
"""
GradeView Online - Desktop Sync Helper (Windows)
================================================
Runs in the system tray. When GradeView's "Open" button is clicked, the browser
calls this helper's local endpoint (http://127.0.0.1:8765/open?...). The helper:

  1. Downloads the latest server workbook from Railway (sync_api.php).
  2. Opens it in Microsoft Excel (editable local working copy).
  3. Watches the file; when it stops changing after a save, uploads it back.
  4. Shows Synced / Syncing / Error in the tray tooltip.

Only the Python standard library is required. The tray icon is optional and
uses `pystray` + `Pillow` if installed; otherwise it runs headless (console).

CONFIG
------
On first run a file `gradeview_helper.json` is created next to this script:

    {
      "server": "https://your-app.up.railway.app",
      "token":  "PASTE_DEVICE_TOKEN_FROM_GRADEVIEW",
      "port":   8765
    }

Get the token in GradeView: Manage Grade -> "Connect desktop helper".
"""

import json
import os
import sys
import time
import hashlib
import threading
import webbrowser
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib import request, parse
from urllib.error import URLError, HTTPError

APP_NAME = "GradeView Sync Helper"
CONFIG_PATH = os.path.join(os.path.dirname(os.path.abspath(__file__)), "gradeview_helper.json")
WORK_DIR = os.path.join(os.environ.get("LOCALAPPDATA", os.path.expanduser("~")), "GradeViewHelper", "working")
POLL_SECONDS = 2.0
STABLE_SECONDS = 3.0

DEFAULT_CONFIG = {"server": "https://your-app.up.railway.app", "token": "", "port": 8765}

# monitored files: stored -> dict(path, name, token, base_sha1, last_sig, stable_since, state)
MONITOR = {}
LOCK = threading.Lock()
TRAY = None  # pystray.Icon if available


def load_config():
    if not os.path.isfile(CONFIG_PATH):
        with open(CONFIG_PATH, "w", encoding="utf-8") as f:
            json.dump(DEFAULT_CONFIG, f, indent=2)
        print("Created config:", CONFIG_PATH)
        print("Edit it and set your server URL and device token, then restart.")
    try:
        with open(CONFIG_PATH, "r", encoding="utf-8") as f:
            cfg = json.load(f)
    except Exception:
        cfg = dict(DEFAULT_CONFIG)
    cfg.setdefault("server", DEFAULT_CONFIG["server"])
    cfg.setdefault("token", "")
    cfg.setdefault("port", 8765)
    return cfg


CFG = load_config()
SERVER = CFG["server"].rstrip("/")
TOKEN = CFG["token"]
PORT = int(CFG["port"])


def sha1_file(path):
    h = hashlib.sha1()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(65536), b""):
            h.update(chunk)
    return h.hexdigest()


def api_url(action, **params):
    q = {"action": action, "token": TOKEN}
    q.update({k: v for k, v in params.items() if v is not None})
    return SERVER + "/sync_api.php?" + parse.urlencode(q)


def set_state(stored, state):
    with LOCK:
        if stored in MONITOR:
            MONITOR[stored]["state"] = state
    refresh_tray()


def download(stored, name):
    os.makedirs(WORK_DIR, exist_ok=True)
    dest = os.path.join(WORK_DIR, stored)
    url = api_url("download", stored=stored)
    req = request.Request(url, headers={"X-GV-Token": TOKEN})
    with request.urlopen(req, timeout=60) as r, open(dest, "wb") as out:
        out.write(r.read())
    return dest


def upload(stored, path, base_sha1):
    url = api_url("upload")
    boundary = "----GVBoundary" + hashlib.sha1(str(time.time()).encode()).hexdigest()[:12]
    fname = os.path.basename(path)
    with open(path, "rb") as f:
        content = f.read()

    def field(name, value):
        return ("--%s\r\nContent-Disposition: form-data; name=\"%s\"\r\n\r\n%s\r\n" % (boundary, name, value)).encode()

    body = bytearray()
    body += field("stored", stored)
    body += field("base_sha1", base_sha1)
    body += ("--%s\r\nContent-Disposition: form-data; name=\"file\"; filename=\"%s\"\r\n"
             "Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet\r\n\r\n"
             % (boundary, fname)).encode()
    body += content
    body += ("\r\n--%s--\r\n" % boundary).encode()

    req = request.Request(url, data=bytes(body), method="POST", headers={
        "X-GV-Token": TOKEN,
        "Content-Type": "multipart/form-data; boundary=" + boundary,
    })
    with request.urlopen(req, timeout=90) as r:
        return json.loads(r.read().decode())


def open_workbook(stored, name):
    try:
        set_state(stored, "syncing")
        path = download(stored, name)
        base = sha1_file(path)
        with LOCK:
            MONITOR[stored] = {
                "path": path, "name": name, "token": TOKEN, "base_sha1": base,
                "last_sig": (base, os.path.getsize(path)), "stable_since": time.time(),
                "state": "editing",
            }
        os.startfile(path)  # opens in Excel (default app)
        set_state(stored, "editing")
        return True, "Opened in Excel"
    except HTTPError as e:
        set_state(stored, "error")
        return False, "Server error %s: %s" % (e.code, e.read().decode(errors="ignore")[:200])
    except (URLError, Exception) as e:
        set_state(stored, "error")
        return False, str(e)


def monitor_loop():
    while True:
        time.sleep(POLL_SECONDS)
        with LOCK:
            items = list(MONITOR.items())
        for stored, info in items:
            path = info["path"]
            if not os.path.isfile(path):
                continue
            try:
                sig = (sha1_file(path), os.path.getsize(path))
            except Exception:
                continue
            if sig != info["last_sig"]:
                info["last_sig"] = sig
                info["stable_since"] = time.time()
                if info["state"] != "error":
                    set_state(stored, "syncing")
            else:
                # unchanged since last poll; upload once stable and different from base
                stable_for = time.time() - info["stable_since"]
                if stable_for >= STABLE_SECONDS and sig[0] != info["base_sha1"] and info["state"] != "error":
                    info["state"] = "uploading"
                    refresh_tray()
                    try:
                        res = upload(stored, path, info["base_sha1"])
                        if res.get("ok"):
                            info["base_sha1"] = res.get("sha1", sig[0])
                            info["stable_since"] = time.time()
                            set_state(stored, "synced")
                        else:
                            set_state(stored, "error")
                    except HTTPError as e:
                        if e.code == 409:
                            # conflict: keep base, surface error; user can re-open
                            set_state(stored, "error")
                        else:
                            set_state(stored, "error")
                    except Exception:
                        set_state(stored, "error")


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def _send(self, code, body, ctype="application/json"):
        data = body.encode() if isinstance(body, str) else body
        self.send_response(code)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def do_GET(self):
        parsed = parse.urlparse(self.path)
        qs = parse.parse_qs(parsed.query)
        if parsed.path == "/ping":
            self._send(200, json.dumps({"ok": True, "app": APP_NAME}))
            return
        if parsed.path == "/open":
            stored = (qs.get("stored") or [""])[0]
            name = (qs.get("name") or [stored] or ["grade.xlsx"])[0]
            if not stored:
                self._send(400, json.dumps({"ok": False, "error": "missing stored"}))
                return
            ok, msg = open_workbook(stored, name)
            self._send(200 if ok else 500, json.dumps({"ok": ok, "message": msg}))
            return
        self._send(404, json.dumps({"ok": False, "error": "not found"}))


def refresh_tray():
    if TRAY is None:
        return
    states = []
    with LOCK:
        for stored, info in MONITOR.items():
            states.append("%s: %s" % (info["name"], info["state"]))
    tip = APP_NAME + ("\n" + "\n".join(states) if states else "\nIdle")
    try:
        TRAY.title = tip
    except Exception:
        pass


def run_tray():
    global TRAY
    try:
        import pystray
        from PIL import Image, ImageDraw
    except Exception:
        return
    img = Image.new("RGB", (64, 64), (79, 70, 229))
    d = ImageDraw.Draw(img)
    d.ellipse((16, 16, 48, 48), fill=(255, 255, 255))
    menu = pystray.Menu(
        pystray.MenuItem("Open GradeView", lambda: webbrowser.open(SERVER)),
        pystray.MenuItem("Quit", lambda icon, item: (icon.stop(), os._exit(0))),
    )
    TRAY = pystray.Icon("gradeview_helper", img, APP_NAME, menu)
    threading.Thread(target=TRAY.run, daemon=True).start()


def main():
    os.makedirs(WORK_DIR, exist_ok=True)
    if not TOKEN:
        print("No token configured. Edit gradeview_helper.json and set 'token'.")
    run_tray()
    threading.Thread(target=monitor_loop, daemon=True).start()
    httpd = ThreadingHTTPServer(("127.0.0.1", PORT), Handler)
    print("%s listening on http://127.0.0.1:%d  (server: %s)" % (APP_NAME, PORT, SERVER))
    try:
        httpd.serve_forever()
    except KeyboardInterrupt:
        pass


if __name__ == "__main__":
    main()