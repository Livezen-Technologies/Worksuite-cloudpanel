#!/usr/bin/env python3
"""Tiny HTTP pass-through between the agent and the API, used by agent.sh.

It forwards everything unchanged, except that on /agent/v1/heartbeat responses it can:
  * tamper: change the payload of the next delivered command (signature left as-is);
  * replay: re-inject the last delivered command (same id + signature) once more.
Control: write "tamper" or "replay" to the file given by --ctl; it is consumed on use.
Usage: mitm.py LISTEN_PORT UPSTREAM_URL CTL_FILE LOG_FILE
"""
import json, os, sys, urllib.request, urllib.error
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

PORT, UP, CTL, LOG = int(sys.argv[1]), sys.argv[2].rstrip('/'), sys.argv[3], sys.argv[4]
last = {'cmd': None}

def log(msg):
    with open(LOG, 'a') as f:
        f.write(msg + '\n')

def take_ctl():
    try:
        with open(CTL) as f:
            mode = f.read().strip()
        return mode
    except FileNotFoundError:
        return ''

def clear_ctl():
    try: os.remove(CTL)
    except FileNotFoundError: pass

class H(BaseHTTPRequestHandler):
    def log_message(self, *a): pass
    def _go(self):
        n = int(self.headers.get('Content-Length') or 0)
        body = self.rfile.read(n) if n else None
        req = urllib.request.Request(UP + self.path, data=body, method=self.command)
        for k, v in self.headers.items():
            if k.lower() not in ('host', 'content-length', 'connection'):
                req.add_header(k, v)
        try:
            r = urllib.request.urlopen(req, timeout=60); code, hdrs, data = r.status, r.headers, r.read()
        except urllib.error.HTTPError as e:
            code, hdrs, data = e.code, e.headers, e.read()
        if self.path.startswith('/agent/v1/heartbeat') and code == 200:
            try:
                doc = json.loads(data); cmds = doc.get('commands') or []
                mode = take_ctl()
                if mode == 'tamper' and cmds:
                    cmds[0]['payload']['tampered'] = True
                    log('tampered ' + cmds[0]['id']); clear_ctl()
                elif mode == 'replay' and not cmds and last['cmd']:
                    cmds = [last['cmd']]; log('replayed ' + last['cmd']['id']); clear_ctl()
                if cmds and mode != 'replay':
                    last['cmd'] = json.loads(json.dumps(cmds[-1]))
                for c in cmds:
                    log('deliver %s %s' % (c['id'], c['type']))
                doc['commands'] = cmds; data = json.dumps(doc).encode()
            except Exception as ex:  # never break the agent
                log('mitm error %r' % ex)
        self.send_response(code)
        for k, v in hdrs.items():
            if k.lower() not in ('content-length', 'transfer-encoding', 'connection'):
                self.send_header(k, v)
        self.send_header('Content-Length', str(len(data))); self.end_headers(); self.wfile.write(data)
    do_GET = do_POST = do_PUT = do_PATCH = do_DELETE = _go

ThreadingHTTPServer(('127.0.0.1', PORT), H).serve_forever()
