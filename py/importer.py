#
#	nophp (python port)	https://github.com/paijp/nophp
#
#	Copyright (c) 2021-2023 paijp
#
#	This software is released under the MIT License.
#	http://opensource.org/licenses/mit-license.php
#
#	Port of importer.php (?mode=sql, ?mode=import).
#	Differences from PHP (bug fixes):
#	- It runs after all tables are registered, so "create" creates the tables.
#	- ?mode=import lists $sys->importlist (PHP refers an undefined global $importlist).
#

import base64
import re
import sqlite3
import time

from engine import (
	G, ClassRegistry, Registered, execsql, header, echo, log_die, myhash, innersimpletable,
)
from kana import mb_convert_kana
from phpcompat import PhpArray, to_int, to_str, to_float, loose_eq, htmlspecialchars, php_trim


_ascii2null = {i: None for i in range(128)}


class im_table(Registered):
	def __init__(self, name, id=0):
		self.r = G().tablelist[name].getrecord(id)

	def delete(self):
		execsql("delete from %s;" % self.r.tablename, None, 0, 1)

	def set(self, key, val):
		self.r.setfield(key, val)

	def get(self, key):
		if key != "id":
			return self.r.getfield(key)
		if self.r.id <= 0:
			self.r.update()
		return self.r.id

	def update(self):
		self.r.update()


class im_tables(Registered):
	def __init__(self, name):
		self.name = name
		self.list = PhpArray()

	def delete(self):
		G().tablelist["simple"].gettable(self.name).updatelist(PhpArray())

	def update(self):
		G().findtableidscache = {}		# clear cache.
		t = G().tablelist["simple"].gettable(self.name)
		lst = t.getlist().copy()
		lst.append(self.list)
		t.updatelist(lst)

	def set(self, key, val):
		self.list[key] = val

	def _cache(self, field):
		g = G()
		cachename = "%s|%s" % (self.name, field)
		cache = g.findtableidscache.get(cachename)
		if cache is None:
			cache = PhpArray()
			for key, a in G().tablelist["simple"].gettable(self.name).getlist().items():
				cache[a.get(field)] = key + 1
			g.findtableidscache[cachename] = cache
		return cachename, cache

	def findtableids(self, field, val):
		return to_int(self._cache(field)[1].get(val))

	def findtableidsa(self, field, val):
		cachename, cache = self._cache(field)
		if cache.get(val) is None:
			t = G().tablelist["simple"].gettable(self.name)
			lst = t.getlist().copy()
			lst.append(PhpArray({field: val}))
			t.updatelist(lst)
			cache[val] = len(lst)
		return to_int(cache.get(val))

	def findtableidsz(self, field, val):
		s0 = mb_convert_kana(to_str(val), "as").translate(_ascii2null)
		for key, a in G().tablelist["simple"].gettable(self.name).getlist().items():
			s1 = mb_convert_kana(to_str(a.get(field)), "as").translate(_ascii2null)
			if loose_eq(s0, s1):
				return key + 1
		return 0


_fmtre = re.compile(r"%%|%(?:([0-9]+)\$)?((?:[-+ 0]|'.)*)([0-9]*)(?:\.([0-9]+))?([bcdeEfFgGosuxX])")


def php_sprintf(fmt, *args):
	"""sprintf() with PHP conversions ("%d" of "12abc" is 12)."""
	pos = [0]

	def conv(m):
		if m.group(0) == "%%":
			return "%"
		argnum, flags, width, prec, spec = m.groups()
		if argnum:
			i = int(argnum) - 1
		else:
			i = pos[0]
			pos[0] += 1
		v = args[i] if i < len(args) else None
		pad = "0" if "0" in flags.replace("'0", "") else " "
		if (q := re.search(r"'(.)", flags)):
			pad = q.group(1)
		left = "-" in flags
		plus = "+" in flags
		if spec in "du":
			n = to_int(v)
			s = str(n if spec == "d" else n & 0xffffffffffffffff)
			if plus and n >= 0:
				s = "+" + s
		elif spec in "eEfFgG":
			p = 6 if prec is None else int(prec)
			s = ("%" + ("+" if plus else "") + "." + str(p) + spec.replace("F", "f")) % to_float(v)
			if spec in "eE":
				s = re.sub(r'([eE][+-])0*([0-9])', r'\1\2', s)
		elif spec in "xXo":
			s = ("%" + spec) % (to_int(v) & 0xffffffffffffffff)
		elif spec == "b":
			s = bin(to_int(v) & 0xffffffffffffffff)[2:]
		elif spec == "c":
			s = chr(to_int(v) % 256)
		else:
			s = to_str(v)
			if prec is not None:
				s = s[:int(prec)]
		w = int(width) if width else 0
		if len(s) < w:
			if left:
				s = s + (" " if pad == "0" else pad) * (w - len(s))
			elif pad == "0" and s[:1] in "+-" and spec not in "sc":
				s = s[0] + "0" * (w - len(s)) + s[1:]
			else:
				s = pad * (w - len(s)) + s
		return s

	return _fmtre.sub(conv, to_str(fmt))


def basicauth():
	g = G()
	sys = g.sys
	if sys.auth_user == "":
		return
	user, pw = None, None
	auth = g._SERVER.get("HTTP_AUTHORIZATION", "")
	if auth.lower().startswith("basic "):
		try:
			user, pw = base64.b64decode(auth[6:]).decode().split(":", 1)
		except (ValueError, UnicodeDecodeError):
			pass
	if not loose_eq(user, sys.auth_user):
		pass
	elif not loose_eq(myhash(to_str(sys.auth_salt) + to_str(pw)), sys.auth_pass):
		pass
	else:
		return
	header('WWW-Authenticate: Basic realm="www"')
	header("HTTP/1.0 401 Unauthorized")
	log_die()


def handle():
	g = G()
	sys = g.sys
	mode = g._GET.get("mode")
	if not (loose_eq(mode, "sql") or loose_eq(mode, "import")):
		return
	g.findtableidscache = {}
	if "simple" not in g.tablelist:
		innersimpletable()

	if loose_eq(mode, "sql"):
		basicauth()
		if g._POST.get("create") is not None:
			for table in list(g.tablelist.values()):
				table.createtable()
			log_die()
		while True:
			s = g._POST.get("query")
			if s is None:
				break
			execsql()
			try:
				cur = g.db0.execute(s)
			except sqlite3.Error as e:
				echo(htmlspecialchars(str(e)) + "<BR>\n")
				break
			lst = cur.fetchall()
			if len(lst) == 0:
				echo("changes: %d<BR>\n" % max(cur.rowcount, 0))
				break
			cols = [d[0] for d in cur.description]
			echo("<TABLE border=2>\n  <TR>\n")
			for key in cols:
				echo("    <TH>" + htmlspecialchars(key) + "</TH>\n")
			echo("  </TR>\n")
			for row in lst:
				echo("  <TR>\n")
				for val in row:
					echo("    <TD>" + htmlspecialchars(val) + "</TD>\n")
				echo("  </TR>\n")
			echo("</TABLE>")
			break
		echo('''<FORM method=POST>
<TEXTAREA name=query cols=40 rows=10></TEXTAREA>
<INPUT type=submit>
</FORM>
<HR>
<FORM method=POST>
<INPUT type=submit name=create value="create">
<A href="?mode=import">import</A>
</FORM>
</BODY></HTML>''')
		log_die()

	basicauth()
	echo("<FORM method=POST>\n<UL>\n")
	for val in (sys.importlist or PhpArray()).values():
		name = "import_" + to_str(val).encode().hex()
		echo('\t<LI><LABEL><INPUT type=checkbox name="%s" value=1>%s</LABEL>\n' % (name, htmlspecialchars(val)))
		if to_str(g._POST.get(name)) != "":
			_import(val)
	echo("</UL>\n<UL>\n\t<LI><INPUT type=submit value=import>\n</UL>\n</FORM>\n")
	log_die()


def _import(fn):
	g = G()
	try:
		fp = open(fn, encoding="utf-8")
	except OSError:
		log_die("fopen failed: %s(%s)" % (htmlspecialchars(fn), fn.encode().hex()))
	execsql("begin;")
	stack = []
	lst = PhpArray()
	with fp:
		for line in fp:
			a = line.rstrip().split("\t")
			if len(a) < 2:
				continue
			cmd = a[1]
			if cmd in ("", "#"):
				continue
			if cmd == "#message":
				a = line.split("\t", 2)
				echo("<P>" + htmlspecialchars(time.strftime("%Y/%m/%d %H:%M:%S ") + (a[2] if len(a) > 2 else "")) + "</P>\n")
				continue
			if cmd == "delete":
				im_table(php_trim(a[0])).delete()
				continue
			if cmd == "deletes":
				im_tables(php_trim(a[0])).delete()
				continue
			if cmd == "table{":
				s = php_trim(a[0])
				obj = im_table(s, to_int(a[2] if len(a) > 2 else None))
				lst[s] = obj
				stack.insert(0, obj)
				if to_int(a[2] if len(a) > 2 else None) == 0:
					continue
				if to_int(obj.get("id")) > 0:
					continue
				echo(htmlspecialchars("record not found : %s#%s.%s" % (obj.r.tablename, obj.r.id, a[0])) + "<BR>\n")
				log_die()
			if cmd == "}":
				stack[0].update()
				stack.pop(0)
				continue
			if cmd == "=":
				a = line.rstrip().split("\t", 2)
				stack[0].set(php_trim(a[0]), a[2] if len(a) > 2 else None)
				continue
			if cmd == "==":
				a = line.rstrip().split("\t", 2)
				s = stack[0].get(php_trim(a[0]))
				if loose_eq(s, a[2] if len(a) > 2 else None):
					continue
				r = stack[0].r
				echo(htmlspecialchars("%s#%s.%s" % (r.tablename, r.id, a[0])) + "<BR>\n")
				echo(htmlspecialchars("expected : " + to_str(a[2] if len(a) > 2 else None)) + "<BR>\n")
				echo(htmlspecialchars("found : " + to_str(s)) + "<BR>\n")
				log_die()
			if cmd != "=sprintf":
				if not ClassRegistry.exists(s := "im_" + cmd):
					log_die("unknown command: %s(%s)" % (htmlspecialchars(cmd), cmd.encode().hex()))
				stack.insert(0, ClassRegistry.new(s, php_trim(a[0])))
				continue
			a0 = [to_str(a[2] if len(a) > 2 else None)]
			for i in range(3, len(a)):
				a1 = a[i].split(".")
				if a1[0] != "":
					a0.append(lst[a1[0]].get(a1[1] if len(a1) > 1 else None))
					continue
				s = a1[1] if len(a1) > 1 else ""
				if s not in ("findtableids", "findtableidsa", "findtableidsz"):
					log_die("unknown command: %s(%s)" % (s, s.encode().hex()))
				a1 = a[i].split(".", 4)
				a1 += [None] * (5 - len(a1))
				obj = im_tables(a1[2])
				a0.append(getattr(obj, s)(a1[3], a1[4]))
			stack[0].set(php_trim(a[0]), php_sprintf(*a0))
	execsql("commit;")
