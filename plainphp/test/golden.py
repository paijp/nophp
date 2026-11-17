#!/usr/bin/python3 -I
"""Scenario test of a nophp application and of its rewrite (plainphp/README.md).

Runs every step of a scenario against a FRESH database under `php -S`, and
writes one file per step: status, Location, cookies set, the HTML, the mails
sent, all tables; times and random keys are masked. Run it on the nophp
version and on the plain PHP version: the two directories must be the same
(compare.py).

  golden.py <code dir> <work dir> <port> [<scenario dir>]

<code dir> holds index.php. <scenario dir> (default: the directory of this
file) holds config.py and scenario.py:

config.py defines
  ENV        text of the settings file; {db} {work} {outbox} are filled in.
             The code under test must load the file named by the environment
             variable ENVVAR (for nophp: put an env.php with
             `<?php require(getenv("APP_ENV"));` into a copy of the code).
  ENVVAR     name of that variable (default "APP_ENV")
  RUNAS      user that runs php (e.g. "apache"), or None
  TIMEKEYS   fields that hold a time: recorded as "T" when set
  SECRETKEYS fields that hold random keys / hashes: recorded as "(set)"
  DROPKEYS   fields that are not recorded
scenario.py is the scenario: Python, using get() post() submit() record()
dbval() mixed() and the variables work, db, outbox, BASE.
"""
import sys, os, re, subprocess, time, sqlite3, shutil, urllib.parse, http.client, html.parser, json, glob

code, work, port = sys.argv[1], sys.argv[2], int(sys.argv[3])
sdir = sys.argv[4] if len(sys.argv) > 4 else os.path.dirname(os.path.abspath(__file__))
ENVVAR = "APP_ENV"; RUNAS = None; BASE = "/index.php/nofmt/"
TIMEKEYS = ("created", "updated", "lastlogin", "lastlogout", "mailsent")
SECRETKEYS = ("salt", "pass", "sessionkey", "submitkey", "mailkey")
DROPKEYS = ("debuglogfn",)
exec(open(sdir + "/config.py").read())

out = work + "/out"
shutil.rmtree(work, ignore_errors=True)
os.makedirs(out)
db = work + "/db.sq3"
outbox = work + "/outbox"
os.makedirs(outbox)
open(work + "/env.php", "w").write(ENV.format(db=db, work=work, outbox=outbox))
cmd = ["env", ENVVAR + "=" + work + "/env.php", "OUTBOX=" + outbox,
    "php", "-d", "display_errors=0", "-d", "log_errors=1", "-d", "error_log=" + work + "/php.log",
    "-S", "127.0.0.1:%d" % port, "-t", code]
if RUNAS:
    subprocess.run(["chown", "-R", RUNAS, work], check=True)
    cmd = ["runuser", "-u", RUNAS, "--"] + cmd
srv = subprocess.Popen(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
time.sleep(1.0)

cookies = {}
step = [0]

def mask(s):
    s = re.sub(r"\d{4}/\d\d/\d\d \d\d:\d\d:\d\d", "DATETIME", s)
    s = re.sub(r"\b[0-9a-f]{40}\b", "RANDOM40", s)
    s = re.sub(r"\b[0-9a-f]{64}\b", "HASH64", s)
    s = s.replace("127.0.0.1:%d" % port, "HOST")
    return s

def request(method, path, fields=None, nocookie=False):
    c = http.client.HTTPConnection("127.0.0.1", port, timeout=60)
    h = {"Host": "127.0.0.1:%d" % port}
    if cookies and not nocookie:
        h["Cookie"] = "; ".join("%s=%s" % kv for kv in cookies.items())
    body = None
    if fields is not None:
        body = urllib.parse.urlencode([(k, v) for k, v in fields if k is not None])
        h["Content-Type"] = "application/x-www-form-urlencoded"
    c.request(method, path, body, h)
    r = c.getresponse()
    data = r.read().decode("utf-8", "replace")
    setc = []
    for k, v in r.getheaders():
        if k.lower() == "set-cookie":
            name, val = v.split(";", 1)[0].split("=", 1)
            s = name + ("=(deleted)" if val in ("", "deleted") else "=(set)")
            if s not in setc:        # newer nophp sets every cookie for two paths
                setc.append(s)
            if nocookie:
                pass
            elif val in ("", "deleted"):
                cookies.pop(name, None)
            else:
                cookies[name] = val
    return r.status, r.getheader("Location"), data, setc

class Forms(html.parser.HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.forms = []; self.cur = None; self.ta = None
    def handle_starttag(self, tag, a):
        a = dict(a)
        if tag == "form":
            self.cur = {"fields": [], "submits": []}; self.forms.append(self.cur)
        elif self.cur is None:
            return
        elif tag == "input":
            t = (a.get("type") or "text").lower()
            if t == "submit":
                self.cur["submits"].append((a.get("name"), a.get("value") or ""))
            elif t in ("button", "checkbox", "radio"):
                pass                 # set them with `values`
            elif a.get("name") is not None:
                self.cur["fields"].append([a.get("name"), a.get("value") or ""])
        elif tag == "textarea":
            self.ta = [a.get("name"), ""]; self.cur["fields"].append(self.ta)
    def handle_endtag(self, tag):
        if tag == "form": self.cur = None
        if tag == "textarea": self.ta = None
    def handle_data(self, d):
        if self.ta is not None: self.ta[1] += d

def dumpdb():
    if not os.path.exists(db):
        return "(no db)\n"
    con = sqlite3.connect("file:%s?mode=ro" % db, uri=True)
    res = []
    tables = [r[0] for r in con.execute("select name from sqlite_master where type = 'table' and name != 'simple' order by name")]
    for t in tables:
        cur = con.execute('select * from "%s" order by id' % t)
        cols = [d[0] for d in cur.description]
        for row in cur:
            d = {}
            for k, v in zip(cols, row):
                if k == "mixed":
                    for ch in (v or "").split("\t"):
                        if "=" in ch:
                            k2, v2 = ch.split("=", 1); d[k2] = urllib.parse.unquote(v2)
                elif v is not None:
                    d[k] = v
            for k in list(d):
                v = d[k]
                if k in TIMEKEYS:
                    d[k] = "T" if str(v) not in ("", "0") else str(v)
                elif k in SECRETKEYS:
                    d[k] = "(set)" if str(v) != "" else ""
                elif k in DROPKEYS:
                    del d[k]
                else:
                    d[k] = mask(str(v))
            res.append("%s %s" % (t, json.dumps(d, ensure_ascii=False, sort_keys=True)))
    con.close()
    return "\n".join(res) + "\n"

def record(name, method, path, st, loc, body, setc, note=""):
    step[0] += 1
    fn = "%s/%03d_%s.txt" % (out, step[0], name)
    mails = []
    for f in sorted(glob.glob(outbox + "/*")):
        mails.append(mask(open(f, "rb").read().decode("utf-8", "replace")))
        os.unlink(f)
    if str(st).startswith("3"):
        body = "(body of a redirect: not compared)"
    s = "%s %s%s\nstatus: %s\nlocation: %s\nset-cookie: %s\n---- body\n%s\n---- outbox (%d)\n%s\n---- db\n%s" % (
        method, mask(path), note, st, mask(loc or "-"), " ".join(setc), mask(body), len(mails),
        "\n====\n".join(re.sub(r"(?m)^(Date|Message-I[Dd]):.*$", r"\1: X", m) for m in mails), dumpdb())
    open(fn, "w").write(s)

def get(name, path, nocookie=False):
    st, loc, body, setc = request("GET", path, None, nocookie)
    record(name, "GET", path, st, loc, body, setc)
    return st, loc, body

def post(name, path, fields, nocookie=False):
    """POST the fields as they are (APIs, and forged requests: no submitkey, a wrong one)."""
    st, loc, body, setc = request("POST", path, fields, nocookie)
    record(name, "POST", path, st, loc, body, setc)
    return st, loc, body

def submit(name, path, button, values=None, nth=0):
    """GET path, find the form with a submit button whose VALUE is `button`
    (the nth one), fill in `values`, POST. Buttons are found by their text,
    so the same scenario works when the names of the buttons change.
    A key of `values` ending in "*" matches the fields that start with it."""
    st, loc, body, setc = request("GET", path)
    p = Forms(); p.feed(body)
    found = []
    for f in p.forms:
        for sn, sv in f["submits"]:
            if sv == button:
                found.append((f, sn, sv))
    if len(found) <= nth:
        record(name, "SUBMIT", path, "no button '%s' (#%d)" % (button, nth), None, body, setc)
        return None
    f, sn, sv = found[nth]
    fields = [list(x) for x in f["fields"]]
    values = dict(values or {})
    def match(fieldname, k):
        return fieldname == k or (k.endswith("*") and fieldname is not None and fieldname.startswith(k[:-1]))
    for x in fields:
        for k in values:
            if match(x[0], k):
                x[1] = values[k]
    for k in values:
        if not any(match(x[0], k) for x in fields):
            fields.append([k, values[k]])
    if sn is not None:
        fields.append([sn, sv])
    st, loc, body, setc = request("POST", path, fields)
    record(name, "POST(form)", path, st, loc, body, setc, "  button=%r values=%s" % (button, json.dumps(values, ensure_ascii=False, sort_keys=True)))
    return st, loc, body

def dbval(sql):
    con = sqlite3.connect("file:%s?mode=ro" % db, uri=True)
    r = con.execute(sql).fetchone(); con.close()
    return r[0] if r else None

def mixed(table, id, key):
    """A field of "mixed" (e.g. a key the scenario needs: mixed("login", 1, "apikey"))."""
    v = dbval("select mixed from %s where id = %d" % (table, id)) or ""
    for ch in v.split("\t"):
        if ch.startswith(key + "="):
            return urllib.parse.unquote(ch.split("=", 1)[1])
    return ""

try:
    exec(open(sdir + "/scenario.py").read())
finally:
    srv.terminate()
    log = work + "/php.log"
    n = 0
    if os.path.exists(log):
        n = len([l for l in open(log, errors="replace") if "PHP " in l])
    print("%s: %d steps, %d php log line(s)" % (work, step[0], n))
