#
#	Differential test: run the same scenario against the PHP and the Python version
#	and compare the responses.
#
#	python3 compare.py http://localhost/nophp/index.php http://localhost/nophp-py page...
#
#	Each side must use its own fresh database.
#

import html.parser
import http.cookiejar
import re
import sys
import urllib.error
import urllib.parse
import urllib.request


class NoRedirect(urllib.request.HTTPRedirectHandler):
	def redirect_request(self, *a, **k):
		return None


class Client:
	def __init__(self, base):
		self.base = base
		self.jar = http.cookiejar.CookieJar()
		self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), NoRedirect)
		self.submitkey = ""

	def req(self, path, data=None):
		url = self.base + path
		if data is not None and "submitkey" in data:
			data = dict(data, submitkey=self.submitkey)		# each side has its own key
		body = None if data is None else urllib.parse.urlencode(data).encode()
		try:
			r = self.opener.open(urllib.request.Request(url, body), timeout=30)
			status, headers, text = r.status, r.headers, r.read()
		except urllib.error.HTTPError as e:
			status, headers, text = e.code, e.headers, e.read()
		text = text.decode("utf-8", "replace")
		if (m := re.search(r'name=submitkey value="([0-9a-f]+)"', text)):
			self.submitkey = m.group(1)
		return status, headers.get("Location", ""), self.normalize(text), text

	def normalize(self, s):
		s = s.replace(self.base, "BASE")
		s = re.sub(r'(name=submitkey value=)"[0-9a-f]+"', r'\1"KEY"', s)
		s = re.sub(r'(name=&quot;submitkey&quot; value=&quot;|name=submitkey value=&quot;)[0-9a-f]+', r'\1KEY', s)
		s = re.sub(r'/\d{6}_\d{6}_\d{8}/', "/DEBUGFN/", s)
		s = re.sub(r'\b1[7-9]\d{8}\b', "NOW", s)
		return s


class FormParser(html.parser.HTMLParser):
	def __init__(self):
		super().__init__(convert_charrefs=True)
		self.forms = []
		self.cur = None
		self.select = None

	def handle_starttag(self, tag, attrs):
		a = dict(attrs)
		if tag == "form":
			self.cur = {"action": a.get("action", ""), "fields": [], "submits": []}
			self.forms.append(self.cur)
		if self.cur is None:
			return
		name = a.get("name")
		if tag == "input" and name:
			t = (a.get("type") or "text").lower()
			if t == "submit":
				self.cur["submits"].append((name, a.get("value") or ""))
			elif t in ("checkbox", "radio"):
				self.cur["fields"].append((name, a.get("value") or "on"))
			elif t == "hidden":
				self.cur["fields"].append((name, a.get("value") or ""))
			elif t != "password" or name == "pass":
				self.cur["fields"].append((name, "testpass" if t == "password" else "v_" + name))
		elif tag == "textarea" and name:
			self.cur["fields"].append((name, "text\n" + name))
		elif tag == "select" and name:
			self.select = name
		elif tag == "option" and self.select:
			self.cur["fields"].append((self.select, a.get("value", "")))
			self.select = None

	def handle_endtag(self, tag):
		if tag == "form":
			self.cur = None


def forms(raw):
	p = FormParser()
	p.feed(raw)
	return p.forms


def step(clients, title, path, data=None):
	results = [c.req(path, data) for c in clients]
	same = all(r[:3] == results[0][:3] for r in results)
	print("%s %s %s" % ("OK  " if same else "DIFF", title, path))
	if not same:
		import difflib
		for i, r in enumerate(results[1:], 1):
			if r[:2] != results[0][:2]:
				print("   status/location:", results[0][:2], r[:2])
			for line in difflib.unified_diff(results[0][2].splitlines(), r[2].splitlines(), "php", "py", lineterm="", n=1):
				print("   " + line)
	return same, results[0][3]


def main():
	php, py = sys.argv[1], sys.argv[2]
	pages = sys.argv[3:]
	clients = [Client(php), Client(py)]
	ok = True
	login = {"login": "nobody@something.com", "pass": "testpass", ":login": "Login"}
	ok &= step(clients, "login page", "/nofmt/g0000.html")[0]
	ok &= step(clients, "bad login", "/nofmt/g0000.html", dict(login, **{"pass": "x"}))[0]
	ok &= step(clients, "login", "/nofmt/g0000.html", login)[0]
	for page in pages:
		same, raw = step(clients, "get", "/nofmt/%s.html" % page)
		ok &= same
		for f in forms(raw):
			for sname, sval in f["submits"]:
				if sname in (":logout", ":login"):
					continue
				data = dict(f["fields"])
				data[sname] = sval
				ok &= step(clients, "post %s" % sname, "/nofmt/%s.html%s" % (page, f["action"]), data)[0]
				ok &= step(clients, "after", "/nofmt/%s.html" % page)[0]
	ok &= step(clients, "logout", "/nofmt/g0000.html", {":logout": "logout", "submitkey": ""})[0]
	print("ALL SAME" if ok else "DIFFERENCES FOUND")
	sys.exit(0 if ok else 1)


if __name__ == "__main__":
	main()
