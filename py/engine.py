#
#	nophp (python port)	https://github.com/paijp/nophp
#
#	Copyright (c) 2021-2023 paijp
#
#	This software is released under the MIT License.
#	http://opensource.org/licenses/mit-license.php
#
#	Port of index.php.
#	Not ported yet: debuglog files, coverage and the log viewer (see debuglog.py for the hook points).
#

import contextvars
import sys as _sys
import hashlib
import os
import re
import sqlite3
import time
import urllib.parse
import urllib.request

from phpcompat import (
	ClassRegistry, Registered, PhpArray, ENT_QUOTES,
	to_int, to_float, to_str, loose_eq, htmlspecialchars, nl2br, rawurlencode,
	php_round, php_trim, json_encode, get_class_methods, call_user_func_array,
)
import phpdate
import debuglog


class Exit(Exception):
	"""die() / log_die(). The response is built from Request.headers and the message."""
	def __init__(self, message=""):
		super().__init__(message)
		self.message = to_str(message)


class Sys:
	htmlbase = "./res/nofmt/"
	rootpage = "g0000"
	debugdir = None
	debugmaxlogrecords = 500
	debugchunksize = 4000
	debuggz = 1
	debugdiff = 1

	mailinterval = 60
	mailexpire = 1800
	forcelogoutonupdate = 1
	noredirectonlogin = 0

	urlbase = None
	target = None

	def __init__(self):
		self.importlist = PhpArray()
		t = time.time()
		self.debugfn = time.strftime("%y%m%d_%H%M%S_", time.localtime(int(t))) + "%08d" % int((t - int(t)) * 1e8)
		self.now = str(int(t))

	def __getattr__(self, name):
		"""@$sys->undefined is null."""
		if name.startswith("__"):
			raise AttributeError(name)
		return None


class Request:
	"""All PHP globals that live for one request.
	mod_wsgi keeps the module loaded between requests, so these must not be module globals."""
	def __init__(self, sys, get=None, post=None, cookie=None, server=None):
		self.sys = sys
		self._GET = PhpArray(get or {})
		self._POST = PhpArray(post or {})
		self._COOKIE = PhpArray(cookie or {})
		self._SERVER = server or {}
		self.headers = []
		self.status = None
		self.printbuf = ""
		self.db0 = None
		self.cookiepath = ""
		self.orgdebugfn = None
		self.debuglog = ""
		self.htmloutput = ""
		self.phase = 0
		self.invalid = 0
		self.loginrecord = None
		self.tablelist = PhpArray()
		self.debugtablelist = PhpArray()
		self.recordholderlist = PhpArray()
		self.actionrecordholder = None
		self.beforename = ""
		self.beforenopost = None
		self.rootparser = None
		self.commandparserindex = 0
		self.coverage_list = None
		self.coverage_id = 0
		self.coverage_title = PhpArray()
		self.coverage_count = PhpArray()
		self.coverage_actionlist = PhpArray()
		self.funclist = None


_req = contextvars.ContextVar("nophp_request")


def G():
	return _req.get()


def trigger_error(s):
	pass


def echo(s):
	"""print / echo"""
	G().printbuf += to_str(s)


def header(s):
	"""header("Name: value") replaces the same header."""
	g = G()
	if (m := re.match(r'^HTTP/[0-9.]+ ([0-9]{3}.*)', s)):
		g.status = m.group(1)
		return
	name, val = s.split(":", 1)
	g.headers = [h for h in g.headers if h[0].lower() != name.strip().lower()]
	g.headers.append((name.strip(), val.strip()))


def setcookie(name, value, expires, path, domain, secure, httponly):
	s = "%s=%s" % (name, rawurlencode(value) if value != "" else "deleted")
	if expires or value == "":
		e = 1 if value == "" else expires
		s += "; expires=" + time.strftime("%a, %d %b %Y %H:%M:%S GMT", time.gmtime(e)) + "; Max-Age=0"
	if path:
		s += "; path=" + path
	if domain:
		s += "; domain=" + domain
	if secure:
		s += "; secure"
	if httponly:
		s += "; HttpOnly"
	G().headers.append(("Set-Cookie", s))


def error_log(message):
	_sys.stderr.write(to_str(message) + "\n")


def report(body, link=""):
	"""POST {"text": ...} to $sys->reportjsonurl (slack / zulip)."""
	sys = G().sys
	if to_str(sys.reportjsonurl) == "":
		return
	lst = {}
	for key, s in (sys.reportjsonbase or PhpArray()).items():
		lst[key] = to_str(s).replace("@body@", body).replace("@link@", link)
	req = urllib.request.Request(sys.reportjsonurl, json_encode(lst).encode(),
		{"Content-Type": "application/json", "Connection": "close"})
	try:
		urllib.request.urlopen(req, timeout=30).read()
	except OSError as e:
		error_log("report failed: %s" % e)


def log_die(message=""):
	"""The message is sent to the error log and the report, not to the browser."""
	g = G()
	sys = g.sys
	if g.loginrecord:
		execsql("commit;", None, 0, 1)
	if to_str(message) != "":
		link = ""
		if to_str(sys.urlbaseparent) != "":
			link = "%s/%s/%s.php" % (sys.urlbaseparent, to_str(sys.debugdir), to_str(g.orgdebugfn))
		report(to_str(message), link)
	if debuglog.enabled(g):
		debuglog.on_die(g, message)
	if to_str(message) != "":
		error_log(message)
	raise Exit()


def ispost():
	g = G()
	if g.loginrecord is None:
		return 0
	if to_str(g.loginrecord.v_submitkey) == "":
		return 0
	if loose_eq(to_str(g._POST.get("submitkey")), to_str(g.loginrecord.v_submitkey)):
		return 1
	return 0


# ---- user functions (get_defined_functions()["user"] replacement) ----

_functions = {}


def phpfunction(f):
	"""Register a global function that tables.py may override (bq_*, bq2_*, bq_login, myhash).
	Later registration overrides, like the function_exists() guard in builtinfunc.php."""
	_functions[f.__name__] = f
	return f


bqfunc = phpfunction


def function_exists(name):
	return name in _functions


def call_function(name, *args):
	return _functions[name](*args)


def _getfunclist():
	g = G()
	if g.funclist is None:
		g.funclist = {}
		for s in _functions:
			m = re.match(r'^bq2?_(.*)', s)
			if m is None:
				continue
			s0 = m.group(1).split("__", 1)[0]
			if g.funclist.get(s0) is not None:
				trigger_error("duplexed function(%s)" % s)
			g.funclist[s0] = s
	return g.funclist


def myhash(s):
	return _functions["myhash"](s)


def _myhash(s):
	return hashlib.sha256(to_str(s).encode()).hexdigest()


_functions["myhash"] = _myhash		# tables.py may override it with @phpfunction def myhash


# ---- database ----

def _dbparam(v):
	"""PDO binds every value as a string."""
	return None if v is None else to_str(v)


def _dbvalue(v):
	"""PDO sqlite (PHP 8.0) returns every value as a string."""
	if v is None:
		return None
	if isinstance(v, float):
		s = repr(v)
		return s[:-2] if s.endswith(".0") else s
	if isinstance(v, bytes):
		return v.decode("utf-8", "replace")
	return str(v)


class PDOException(Exception):
	pass


class PDO:
	"""The part of PDO (sqlite) used by nophp and the bq_db* hooks.
	Like PDO, beginTransaction()/commit()/rollback() keep their own "in transaction" flag,
	so "rollback;;" or "begin immediate;" through execsql() do not change it."""

	def __init__(self, path):
		self.conn = sqlite3.connect(path, timeout=60, isolation_level=None)		# PDO sqlite: 60s
		self.in_txn = False
		self.error = None

	def execute(self, sql, params=()):
		return self.conn.execute(sql, params)

	def _txn(self, sql):
		try:
			self.conn.execute(sql)
		except sqlite3.Error as e:
			self.error = str(e)
			return False
		self.error = None
		return True

	def beginTransaction(self):
		if self.in_txn:
			raise PDOException("There is already an active transaction")
		if not self._txn("BEGIN"):
			return False
		self.in_txn = True
		return True

	def commit(self):
		if not self.in_txn:
			raise PDOException("There is no active transaction")
		if not self._txn("COMMIT"):
			return False		# still in transaction, as PDO
		self.in_txn = False
		return True

	def rollback(self):
		if not self.in_txn:
			raise PDOException("There is no active transaction")
		if not self._txn("ROLLBACK"):
			return False
		self.in_txn = False
		return True

	rollBack = rollback

	def inTransaction(self):
		return self.in_txn

	def errorInfo(self):
		return ["HY000" if self.error else "00000", None, self.error]

	def close(self):
		self.conn.close()


def _nophp_bq_dbtxn(method, db, returnid, ignoreerror):
	if not getattr(db, method)():
		a = db.errorInfo()
		if not ignoreerror:
			log_die(to_str(a[2]) + " : ")		# (sic) $sql is undefined in PHP
		if returnid:
			return 0
		return []
	if ignoreerror < 0:
		return 1		# success.
	if returnid:
		return 0
	return []


def nophp_bq_dbbegin(db, returnid=0, ignoreerror=0):
	return _nophp_bq_dbtxn("beginTransaction", db, returnid, ignoreerror)


def nophp_bq_dbcommit(db, returnid=0, ignoreerror=0):
	return _nophp_bq_dbtxn("commit", db, returnid, ignoreerror)


def nophp_bq_dbrollback(db, returnid=0, ignoreerror=0):
	return _nophp_bq_dbtxn("rollback", db, returnid, ignoreerror)


# tables.py may override them with @phpfunction def bq_dbbegin(db, returnid=0, ignoreerror=0)
_functions["bq_dbbegin"] = nophp_bq_dbbegin
_functions["bq_dbcommit"] = nophp_bq_dbcommit
_functions["bq_dbrollback"] = nophp_bq_dbrollback


def execsql(sql=None, array=None, returnid=0, ignoreerror=0):
	g = G()
	sys = g.sys
	if g.db0 is None:
		g.db0 = PDO(re.sub(r'^sqlite:', "", sys.sqlpath))
	if sql is None:
		return None
	if array is None:
		array = []
	if debuglog.enabled(g):
		debuglog.on_sql(g, sql, array)
	if sql in ("begin", "begin;"):
		return call_function("bq_dbbegin", g.db0, returnid, ignoreerror)
	if sql in ("commit", "commit;"):
		return call_function("bq_dbcommit", g.db0, returnid, ignoreerror)
	if sql in ("rollback", "rollback;"):
		return call_function("bq_dbrollback", g.db0, returnid, ignoreerror)
	try:
		cur = g.db0.execute(sql, [_dbparam(v) for v in (array.values() if isinstance(array, PhpArray) else array)])
	except sqlite3.Error as e:
		if debuglog.enabled(g):
			debuglog.on_sqlresult(g, error=str(e))
		if not ignoreerror:
			log_die("%s : %s" % (e, sql))
		if returnid:
			return 0
		return []
	if ignoreerror < 0:
		return 1		# success.
	if returnid:
		if debuglog.enabled(g):
			debuglog.on_sqlresult(g, lastinsertid=cur.lastrowid)
		return str(cur.lastrowid)
	cols = [d[0] for d in cur.description] if cur.description else []
	rows = [PhpArray(dict(zip(cols, [_dbvalue(v) for v in row]))) for row in cur.fetchall()]
	if debuglog.enabled(g):
		debuglog.on_sqlresult(g, rows=rows)
	return rows


def object_vars(obj):
	"""get_object_vars(): properties in the order they were set."""
	return list(vars(obj).items())


class _undef_v_:
	"""Reading an undefined $this->v_xxx is null in PHP (with a warning)."""
	def __getattr__(self, name):
		if name.startswith("v_"):
			return None
		raise AttributeError(name)


class table(_undef_v_, Registered):
	tablename = None
	id = -1
	methodlist = None

	def __init__(self, recordid=None, configlist=None):
		g = G()
		self.configlist = configlist
		if self.configlist is None:
			self.configlist = re.split(r'\r|\n|\r\n', php_trim(self.getconfig()))
		if self.tablename is None:
			s = type(self).__name__
			m = re.match(r'^([_0-9A-Za-z]+)_table$', s)
			if m:
				s = m.group(1)
			self.tablename = s
		if recordid is None:
			g.tablelist[self.tablename] = self
			return			# debugtablelist is not ported yet.
		self.id = 0
		if to_int(recordid) <= 0:
			return
		sql = "select * from %s where id = ?;" % self.tablename
		lst = execsql(sql, [recordid], 0, 1)
		if len(lst) <= 0:
			return
		self.id = recordid
		mixed = None
		for key, val in lst[0].items():
			if key == "id":
				self.id = to_int(val)
			elif key == "mixed":
				mixed = val
			else:
				self.setfield(key, val)
		if mixed is not None:
			for chunk in mixed.split("\t"):
				a = chunk.split("=", 1)
				if len(a) < 2:
					continue
				self.setfield(a[0], urllib.parse.unquote(a[1]))

	def getconfig(self):
		return "created\tint\nupdated\tint\ndeleted\tint\n"

	def onload(self):
		pass

	def getrecord(self, id=0):
		return type(self)(id, self.configlist)

	def getrecordidlist(self, cond="", lst=None):
		sql = "select %s.id as id from %s %s;" % (self.tablename, self.tablename, cond)
		lst = execsql(sql, lst, 0, 1)
		return [to_int(r["id"]) for r in lst]

	def getlist(self):
		lst = PhpArray()
		for varname, varval in object_vars(self):
			m = re.match(r'^v_(.*)', varname)
			if m:
				lst[m.group(1)] = varval
		return lst

	def getfield(self, field):
		if field != "id":
			field = "v_" + field
		return getattr(self, field, None)

	def setfield(self, field, val):
		if field != "id":
			field = "v_" + field
		setattr(self, field, val)

	def update(self, ignoreerror=0):
		sys = G().sys
		if self.id < 0:
			log_die("update called.")
		if self.id <= 0:
			self.v_updated = sys.now
			self.v_created = sys.now
		else:
			self.v_updated = sys.now

		if sys.debugfn is not None:
			self.v_debuglogfn = sys.debugfn
		elif "v_debuglogfn" in vars(self):
			del self.v_debuglogfn

		fieldlist = PhpArray()
		mixedlist = PhpArray()
		for line in self.configlist:
			name = re.split(r'[ \t]+', line, maxsplit=1)[0]
			fieldlist[name] = to_str(self.getfield(name))
		for varname, val in object_vars(self):
			m = re.match(r'^v_(.*)', varname)
			if not m:
				continue
			name = m.group(1)
			if fieldlist.get(name) is not None:
				continue
			if re.match(r'^:', name) or "__" in name:
				continue
			if re.match(r'^[0-9]', name):
				return
			mixedlist[name] = name + "=" + rawurlencode(val)
		fieldlist["mixed"] = "\t".join(mixedlist.values())

		if self.id <= 0:
			skeys = ", ".join(str(k) for k in fieldlist.keys())
			svals = ", ".join(["?"] * len(fieldlist))
			sql = "insert into %s(%s) values(%s);" % (self.tablename, skeys, svals)
			self.id = to_int(execsql(sql, list(fieldlist.values()), 1))
		else:
			s = ", ".join("%s = ?" % k for k in fieldlist.keys())
			sql = "update %s set %s where id = ?" % (self.tablename, s)
			execsql(sql, list(fieldlist.values()) + [self.id], 0, ignoreerror)

	def delete(self):
		if self.id <= 0:
			return
		execsql("delete from %s where id = ?;" % self.tablename, [self.id])
		self.id = 0

	def createtable(self):
		s = ",".join(self.configlist)
		execsql()
		sql = "create table %s(\n\tid\tinteger primary key, \n\tmixed\ttext, \n\t%s\n);" % (self.tablename, s)
		execsql(sql, None, 0, 1)

		updated = 0
		for line in self.configlist:
			fieldname = re.split(r'[ \t]+', php_trim(line), maxsplit=1)[0]
			if execsql("alter table %s add %s;" % (self.tablename, php_trim(line)), None, 1, -1):
				updated = 1
			if "/*indexed*/" not in line:
				continue
			execsql("create index %s%s_index on %s(%s);" % (self.tablename, fieldname, self.tablename, fieldname), None, 0, 1)
		if updated == 0:
			return
		execsql("begin;")
		for recordid in self.getrecordidlist():
			self.getrecord(recordid).update()
		execsql("commit;")

	def dumpfields(self, title=""):
		if debuglog.enabled(G()):
			debuglog.on_fields(G(), self, title)


class a_table(table):
	def t_id__id__field(self, rh0, record, s1, s3):
		r = self.getrecord(to_int(s1))
		return [to_str(r.getfield(s3))]

	def t_find__val__field(self, rh0, record, val, field):
		for id in self.getrecordidlist():
			r = self.getrecord(id)
			if loose_eq(r.getfield(field), val):
				return [id]
		return [""]

	def h_set__val__field(self, rh0, record, val, field):
		self.setfield(field, val)
		return []

	def hs_update(self, rh0, record=None):
		s = ""
		if to_int(self.update()) == 0:
			s = self.id
		return [s]

	def hs_delete(self, rh0, record=None):
		self.delete()
		return [""]

	def tv_len(self, par, s):
		m = re.match(r'^(-?[0-9]*)-(-?[0-9]*)', par)
		if not m:
			return 0
		s = to_str(s)
		if m.group(1) != "" and len(s.encode()) < to_int(m.group(1)):
			return 1
		if m.group(2) != "" and len(s.encode()) > to_int(m.group(2)):
			return 1
		return 0

	def tv_lenopt(self, par, s):
		if loose_eq(s, ""):
			return 0
		return self.tv_len(par, s)

	def tv_num(self, par, s):
		m = re.match(r'^(-?[0-9]*)-(-?[0-9]*)', par)
		if not m:
			return 0
		if not re.fullmatch(r'-?[0-9]+\n?', to_str(s)):		# PHP '$' also matches before a final newline
			return 1
		if m.group(1) != "" and to_int(s) < to_int(m.group(1)):
			return 1
		if m.group(2) != "" and to_int(s) > to_int(m.group(2)):
			return 1
		return 0

	def tv_numopt(self, par, s):
		if loose_eq(s, ""):
			return 0
		return self.tv_num(par, s)


def explodetab(s, opt="asKV"):
	from kana import mb_convert_kana
	return ["" if v == "NULL" else mb_convert_kana(v, opt) for v in s.split("\t")]


class simpletable(_undef_v_, Registered):
	methodlist = None

	def __init__(self, record, tablename="", lst=None, id=0):
		self.record = record
		self.tablename = tablename
		self.id = id
		if lst is not None:
			self.list = lst
			for key, val in lst.items():
				self.setfield(key, val)
			return
		self.list = PhpArray()
		s = self.record.v_contents
		if s is None:
			return
		for key, line in enumerate(s.split("\n")):
			self.list[key] = PhpArray()
			for chunk in line.split("\t"):
				a = chunk.split("=", 1)
				if len(a) < 2:
					continue
				self.list[key][a[0]] = urllib.parse.unquote(a[1])

	def getfield(self, field):
		if field != "id":
			field = "v_" + field
		return getattr(self, field, None)

	def setfield(self, field, val):
		if field != "id":
			field = "v_" + field
		setattr(self, field, val)

	def getrecord(self, id=0):
		a = self.list.get(to_int(id) - 1)
		if a is None:
			return type(self)(None, self.tablename, PhpArray(), 0)
		return type(self)(None, self.tablename, a, id)

	def getrecordidlist(self, dummy0="", dummy1=None):
		return [key + 1 for key in self.list.keys()]

	def getlist(self):
		return self.list

	def updatelist(self, lst=None):
		if self.record is None:
			log_die("updatelist called.")
		self.list = lst
		rowlist = []
		for fields in self.list.values():
			rowlist.append("\t".join("%s=%s" % (key, rawurlencode(val)) for key, val in fields.items()))
		self.record.v_contents = "\n".join(rowlist)
		self.record.update()

	def update(self):
		log_die("update called.")

	def dumpfields(self, title=""):
		if debuglog.enabled(G()):
			debuglog.on_fields(G(), self, title)

	def h_set__val__field(self, rh0, record, val, field):
		self.setfield(field, val)
		return []

	def hs_update(self, rh0, record=None):
		t = G().tablelist["simple"].gettable(self.tablename)
		lst = t.getlist().copy()		# PHP arrays are copied on assignment
		fields = PhpArray()
		for varname, varval in object_vars(self):
			m = re.match(r'^v_(.*)', varname)
			if m:
				fields[m.group(1)] = varval
		if lst.get(to_int(self.id) - 1) is None:
			lst.append(fields)
		else:
			lst[to_int(self.id) - 1] = fields
		t.updatelist(lst)
		return []

	def s_id__id__field(self, rh0, record, s1, s3):
		r = self.getrecord(to_int(s1))
		return [to_str(r.getfield(s3))]

	def s_find__val__field(self, rh0, record, s1, s3):
		for k3, v3 in self.getlist().items():
			if loose_eq(v3.get(s3), s1):
				return [k3 + 1]
		return [""]


class innersimpletable(table):
	tablename = "simple"

	def getconfig(self):
		return super().getconfig() + "name\ttext unique not null\ncontents\ttext\n"

	def gettable(self, tablename="test"):
		a = self.getrecordidlist("where name = ?", [tablename])
		if len(a) > 0:
			r = self.getrecord(a[0])
		else:
			r = self.getrecord()
		r.v_name = tablename
		if ClassRegistry.exists(s := "simpletable_" + tablename):
			return ClassRegistry.new(s, r, tablename)
		return simpletable(r, tablename)


# ---- records ----

class rootrecord(_undef_v_, Registered):
	tablename = "root"
	methodlist = None

	def getfield(self, field):
		if field != "id":
			field = "v_" + field
		return getattr(self, field, None)

	def setfield(self, field, val):
		if field != "id":
			field = "v_" + field
		setattr(self, field, val)

	def dumpfields(self, title=""):
		if debuglog.enabled(G()):
			debuglog.on_fields(G(), self, title)


def _methodlist(obj):
	if obj.methodlist is None:
		obj.methodlist = {}
		for name in get_class_methods(obj):
			if not re.match(r'^[a-z]s?_', name):
				continue
			a = name.split("__")
			if obj.methodlist.get(a[0]) is not None:
				trigger_error("duplexed method(%s in %s)" % (name, type(obj).__name__))
			obj.methodlist[a[0]] = name
	return obj.methodlist


class recordholder(Registered):
	def __init__(self, rh=None, record=None, tablename="", prefix="", par=""):
		self.remainblocks = []
		self.remaincmds = []
		self.stack = []
		self.record = rootrecord()
		self.actioncommand = ""
		self.whereargs = PhpArray()
		self.prefix = prefix
		self.methodlist = None
		self.coveragelist = None
		self.debugpoplog = ""
		self.debugpoplist = None

	def popstack(self, cmd, par):
		if par == "":
			return []
		ret = []
		for val in reversed(par.split(" ")):
			s0 = to_str(self.stack.pop() if self.stack else None)
			ret.append(s0)
			self.debugpoplog = htmlspecialchars(s0) + '<span style="color:#aaa;">: ' + htmlspecialchars(val) + "</span><br />" + self.debugpoplog
		ret.reverse()
		return ret

	def pushstack(self, array, tocoverage=1):
		if tocoverage:
			self.debugpoplist.append(self.debugpoplog)
			self.coveragelist.append("\n".join(to_str(v) for v in array))
		self.debugpoplog = ""
		self.stack.extend(array)

	def parsewhere(self, sql):
		args = []
		a = re.split(r'where ', sql, maxsplit=1, flags=re.I)
		if len(a) < 2:
			return [sql, []]
		sql0 = a[0] + "where "
		sql1 = a[1]
		for key, vals in self.whereargs.items():
			sql0 += "%s and " % key
			args.extend(vals)
		return [sql0 + sql1, args]

	def _call(self, obj, fn, cmd, record):
		pars = self.popstack(cmd, " ".join(fn.split("__")[1:]))
		self.pushstack(call_user_func_array([obj, fn], [self, record] + pars))

	def _nofunc(self, s):
		g = G()
		trigger_error(s)
		g.debuglog += "<h3>" + htmlspecialchars(s) + "</h3>"
		self.pushstack([])

	def callfunc(self, rh, cmd, record=None, issubmit=0):
		g = G()
		if rh is None:
			s1, = self.popstack(cmd, "recordholder")
			rh = g.recordholderlist.get(s1)
			if rh is None:
				self.pushstack([])
				self._nofunc("no recordholder(%s)" % s1)
				return
		_methodlist(rh)
		if rh.record is not None:
			_methodlist(rh.record)
		cmd = "h" + cmd[1:]
		if issubmit:
			cmds = "hs" + cmd[1:]
			fn = rh.methodlist.get(cmds)
			if fn is not None:
				return self._call(rh, fn, cmds, record)
			if rh.record is not None and (fn := rh.record.methodlist.get(cmds)) is not None:
				return self._call(rh.record, fn, cmds, record)
		fn = rh.methodlist.get(cmd)
		if fn is not None:
			return self._call(rh, fn, cmd, record)
		if rh.record is not None and (fn := rh.record.methodlist.get(cmd)) is not None:
			return self._call(rh.record, fn, cmd, record)
		self._nofunc("no recordholder function(%s in %s)" % (cmd, type(self).__name__))

	def callfunctable(self, t, cmd, record=None, issubmit=0):
		g = G()
		if t is None:
			s1, = self.popstack(cmd, "table:t")
			t = g.tablelist.get(s1)
			if t is None:
				self.pushstack([])
				self._nofunc("no table(%s)" % s1)
				return
		_methodlist(t)
		cmd = "t" + cmd[1:]
		if issubmit:
			cmds = "ts" + cmd[1:]
			fn = t.methodlist.get(cmds)
			if fn is not None:
				return self._call(t, fn, cmds, record)
		fn = t.methodlist.get(cmd)
		if fn is not None:
			return self._call(t, fn, cmd, record)
		self._nofunc("no table function(%s in %s)" % (cmd, type(self).__name__))

	def callfuncstable(self, cmd, record=None, issubmit=0):
		g = G()
		s1, = self.popstack(cmd, "stable")
		t = g.tablelist["simple"].gettable(s1)
		_methodlist(t)
		if issubmit:
			cmds = "ss" + cmd[1:]
			fn = t.methodlist.get(cmds)
			if fn is not None:
				return self._call(t, fn, cmds, record)
		fn = t.methodlist.get(cmd)
		if fn is not None:
			return self._call(t, fn, cmd, record)
		self._nofunc("no stable function(%s in %s)" % (cmd, type(self).__name__))

	def flush_coverage(self):
		if G().coverage_list is None:
			return
		debuglog.on_coverage(G(), self)

	def _gohome(self):
		sys = G().sys
		self.pushstack(["HOME"])
		self.flush_coverage()
		header("Location: %s/nofmt/%s.html" % (sys.urlbase, sys.rootpage))
		log_die()

	def parsebq(self, text="", record=None, issubmit=0, initstack=None):
		g = G()
		sys = g.sys
		if record is None:
			record = self.record
		self.stack = list(initstack) if initstack is not None else []
		self.coveragelist = [text]
		self.debugpoplog = ""
		self.debugpoplist = [None]

		outputmode = ""
		self.remainblocks = text.split("__")
		self.remaincmds = []
		while self.remainblocks:
			block = self.remainblocks.pop(0)
			if not re.match(r'^:', block):
				self.pushstack([block], 0)
				continue
			self.remaincmds = block.split(":")
			self.remaincmds.pop(0)
			while self.remaincmds:
				cmd = self.remaincmds.pop(0)
				if cmd in ("nl2br", "html", "url", "js", "raw"):
					outputmode = cmd
					self.pushstack([])
				elif cmd in ("andbreak", "iandbreak"):
					s1, = self.popstack(cmd, "val")
					if to_int(s1) != 0:
						self.pushstack(["BREAK"])
						self.flush_coverage()
						return ""
					self.pushstack([])
				elif cmd in ("orbreak", "iorbreak"):
					s1, = self.popstack(cmd, "val")
					if to_int(s1) == 0:
						self.pushstack(["BREAK"])
						self.flush_coverage()
						return ""
					self.pushstack([s1] if cmd == "iorbreak" else [])
				elif cmd == "break":
					self.popstack(cmd, "")
					self.pushstack(["BREAK"])
					self.flush_coverage()
					return ""
				elif re.fullmatch(r'andreturn[0-4]', cmd):
					s1, = self.popstack(cmd, "val")
					if to_int(s1) != 0:
						val = int(cmd[-1])
						self.pushstack([val])
						self.flush_coverage()
						return val
					self.pushstack([])
				elif re.fullmatch(r'orreturn[0-4]', cmd):
					s1, = self.popstack(cmd, "val")
					if to_int(s1) == 0:
						val = int(cmd[-1])
						self.pushstack([val])
						self.flush_coverage()
						return val
					self.pushstack([])
				elif cmd == "home":
					self.popstack(cmd, "")
					self._gohome()
				elif cmd in ("andhome", "iandhome", "orhome", "iorhome"):
					s1, = self.popstack(cmd, "val")
					if (to_int(s1) != 0) == cmd.endswith("andhome"):
						self._gohome()
					self.pushstack([s1] if cmd.startswith("i") else [])
				elif cmd == "pop":
					self.popstack(cmd, "drop")
					self.pushstack([])
				elif cmd in ("authwithpass", "reportbody", "popall"):
					self.popstack(cmd, "")
					self.stack = []
					self.pushstack([])
				elif cmd == "jump":
					self.popstack(cmd, "")
					s = ""
					for k3, v3 in enumerate(self.stack):
						v3 = to_str(v3)
						if k3 == 0:
							s += "%s/%s/%s.html" % (sys.urlbase, sys.debugfn, v3)
						elif k3 == 1:
							s += "?" + v3
						elif k3 & 1:
							s += "&" + v3
						else:
							s += "=" + v3
					self.pushstack([s])
					self.flush_coverage()
					header("Location: " + s)
					log_die()
				# "logout" moved to loginrecord (a_login_table.onload).
				elif re.match(r'^h_', cmd):
					self.callfunc(None, cmd, record, issubmit)
				elif re.match(r'^H_', cmd):
					self.callfunc(self, cmd, record, issubmit)
				elif re.match(r'^t_', cmd):
					self.callfunctable(None, cmd, record, issubmit)
				elif re.match(r'^T_', cmd):
					self.callfunctable(self.record, cmd, record, issubmit)
				elif re.match(r'^s_', cmd):
					self.callfuncstable(cmd, record, issubmit)
				elif (m := re.match(r'^([a-z]id)([_0-9A-Za-z]*)$', cmd)):
					tablename2 = getattr(sys, m.group(1))
					if tablename2 is None:
						self._nofunc("no table shortcut(%s in %s)" % (cmd, type(self).__name__))
						continue
					tablename, = self.popstack(cmd, "table")
					id2 = 0
					if tablename == "r":
						id2 = record.getfield(cmd)
					elif tablename == "g":
						id2 = g._GET.get(cmd)
					elif tablename == "p":
						id2 = g._POST.get(cmd)
					elif tablename == "l":
						if g.loginrecord is not None:
							id2 = g.loginrecord.getfield(cmd)
					else:
						id, = self.popstack(cmd, "id")
						t = g.tablelist.get(tablename)
						if t is not None:
							id2 = t.getrecord(id).getfield(cmd)
					self.pushstack([php_round(id2), tablename2])
				elif (m := re.match(r'^r_([_0-9A-Za-z]+)$', cmd)):
					tablename, = self.popstack(cmd, "table")
					s = ""
					if tablename == "r":
						s = record.getfield(m.group(1))
					elif tablename == "l":
						if g.loginrecord is not None:
							s = g.loginrecord.getfield(m.group(1))
					else:
						id, = self.popstack(cmd, "id")
						t = g.tablelist.get(tablename)
						if t is not None:
							s = t.getrecord(id).getfield(m.group(1))
					self.pushstack([s])
				else:
					s = _getfunclist().get(cmd)
					if s is None:
						self._nofunc("unknown command(%s in %s)" % (cmd, type(self).__name__))
						continue
					a0 = self.popstack(cmd, " ".join(s.split("__")[1:]))
					if re.match(r'^bq2_', s):
						a0 = [self, record] + a0
					a = _functions[s](*a0)
					if not isinstance(a, list):
						return a		# avoid debuglog
					self.pushstack(a)
		self.flush_coverage()
		s = to_str(self.stack[0] if self.stack else None)
		if outputmode == "nl2br":
			return nl2br(htmlspecialchars(s, ENT_QUOTES))
		if outputmode == "url":
			return rawurlencode(s)
		if outputmode == "js":
			s2 = rawurlencode(s)
			if s2 == s:
				return '"' + s + '"'
			return 'decodeURIComponent("' + s2 + '")'
		if outputmode == "raw":
			return s
		return htmlspecialchars(s, ENT_QUOTES)

	def parsewithbq(self, text, record=None):
		output = ""
		for key, chunk in enumerate(text.split("`")):
			output += chunk if (key & 1) == 0 else to_str(self.parsebq(chunk, record))
		return output

	def parsewithbqhighlight(self, text, record=None):
		output = ""
		for key, chunk in enumerate(text.split("`")):
			if (key & 1) == 0:
				output += chunk
				continue
			output += "`?" + to_str(self.parsebq(chunk, record)).replace("`", "`!") + "`?"
		return output

	def parsewithbqinsql(self, text, record=None):
		output = ""
		for key, chunk in enumerate(text.split("`")):
			if (key & 1) == 0:
				output += chunk
				continue
			output += re.sub(r'[^,0-9]', "", to_str(self.parsebq(chunk, record)))
		return output

	def parsename(self, text):
		return self.record.getfield(text)

	def parsenamehtmlhighlight(self, text):
		return to_str(self.parsename(text)).replace("`", "`!")

	def postname(self, name, checkval=None, noclear=0, nopost=None):
		g = G()
		postkey = self.prefix + name.replace(" ", "_").replace(".", "_")
		if not ispost():
			val = nopost
			if val is None:
				return
		else:
			val = g._POST.get(postkey)
			if val is None:
				val = nopost
		if name[:1] == ":" or "__" in name:
			self.parsebq(name, self.record, 0, [val])
		if re.match(r'^[0-9]', name):
			return
		if checkval is None:
			pass
		elif loose_eq(checkval, val):
			pass
		elif noclear:
			return
		else:
			val = ""
		self.record.setfield(name, val)

	def striphighlight(self, highlight=""):
		ret = ""
		for key, val in enumerate(highlight.split("`")):
			if key <= 0:
				ret += val
				continue
			if val[:1] == "!":
				ret += "`"
			ret += val[1:]
		return ret

	def _prefixname(self, taghighlight):
		if self.prefix != "":
			return re.sub(r'name="', lambda m: 'name="`|' + self.prefix.replace("`", "`!") + "`|", taghighlight, count=1)
		return taghighlight

	def parsehtmlhighlight(self, htmlhighlight=""):
		g = G()
		output = ""
		for key, chunk in enumerate(htmlhighlight.split("<")):
			if key == 0:
				taghighlight = ""
				body = chunk
			else:
				a = chunk.split(">", 1)
				if len(a) < 2:
					taghighlight = ""
					body = "<" + chunk
				else:
					taghighlight, body = a

			tag = self.striphighlight(taghighlight)
			tagtype = re.match(r'^(/?[A-Za-z]*)', tag).group(1).lower()
			type = ""
			if (m := re.search(r'type="?([a-zA-Z]+)', tag)):
				type = m.group(1).lower()
			if tagtype == "form" and not re.search(r'action=', tag, re.I):
				taghighlight += '`| action="?' + to_str(g.sys.urlquery).replace("`", "`!") + '"`|'
			elif tagtype == "/form" and g.loginrecord is not None:
				output += '`|<INPUT type=hidden name=submitkey value="' + to_str(g.loginrecord.v_submitkey).replace("`", "`!") + '">`|' + "\n"
			elif tagtype == "input" and type == "submit" and (a := re.search(r'name="([^"]+)"', tag)):
				taghighlight = self._prefixname(taghighlight)
				g.coverage_actionlist[a.group(1)] = 1
				postkey = self.prefix + a.group(1).replace(" ", "_").replace(".", "_")
				if ispost() and g._POST.get(postkey) is not None:
					g.actionrecordholder = self
					self.actioncommand = a.group(1)
			elif tagtype == "input" and type == "checkbox" and (a := re.search(r'name="([^"]+)', tag)) and (a2 := re.search(r'value="([^"]+)', tag)):
				taghighlight = self._prefixname(taghighlight)
				self.postname(a.group(1), a2.group(1))
				if loose_eq(self.parsename(a.group(1)), a2.group(1)):
					taghighlight += "`| checked`|"
			elif tagtype == "input" and type == "radio" and (a := re.search(r'name="([^"]+)', tag)) and (a2 := re.search(r'value="([^"]+)', tag)):
				taghighlight = self._prefixname(taghighlight)
				self.postname(a.group(1), a2.group(1), 1)
				if loose_eq(self.parsenamehtmlhighlight(a.group(1)), a2.group(1)):
					taghighlight += "`| checked`|"
			elif tagtype == "input" and (a := re.search(r'name="([^"]+)"', tag)) and (a2 := re.search(r'x-value="([^"]*)"', tag)):
				taghighlight = self._prefixname(taghighlight)
				self.postname(a.group(1), None, 0, a2.group(1))
				taghighlight += ' `|value="' + htmlspecialchars(self.parsenamehtmlhighlight(a.group(1)), ENT_QUOTES) + '"`|'
			elif tagtype == "input" and (a := re.search(r'name="([^"]+)"', tag)) and re.search(r'value=', tag):
				taghighlight = self._prefixname(taghighlight)
				self.postname(a.group(1))
			elif tagtype == "input" and (a := re.search(r'name="([^"]+)"', tag)):
				taghighlight = self._prefixname(taghighlight)
				self.postname(a.group(1))
				taghighlight += ' `|value="' + htmlspecialchars(self.parsenamehtmlhighlight(a.group(1)), ENT_QUOTES) + '"`|'
			elif tagtype == "select" and (a := re.search(r'name="([^"]+)"', tag)) and (a2 := re.search(r'x-value="([^"]*)"', tag)):
				taghighlight = self._prefixname(taghighlight)
				g.beforename = a.group(1)
				g.beforenopost = a2.group(1)
			elif tagtype == "select" and (a := re.search(r'name="([^"]+)"', tag)):
				taghighlight = self._prefixname(taghighlight)
				g.beforename = a.group(1)
				g.beforenopost = None
			elif tagtype == "option" and (a := re.search(r'value="([^"]*)"', tag)):
				self.postname(g.beforename, a.group(1), 1, g.beforenopost)
				if loose_eq(self.parsename(g.beforename), a.group(1)):
					taghighlight += " `|selected`|"
			elif tagtype == "textarea" and (a := re.search(r'name="([_0-9A-Za-z]+)"', tag)) and (a2 := re.search(r'x-value="([^"]*)"', tag)):
				taghighlight = self._prefixname(taghighlight)
				self.postname(a.group(1), None, 0, a2.group(1))
				if body == "":
					body = "`|" + htmlspecialchars(self.parsenamehtmlhighlight(a.group(1)), ENT_QUOTES) + "`|"
			elif tagtype == "textarea" and (a := re.search(r'name="([_0-9A-Za-z]+)"', tag)):
				taghighlight = self._prefixname(taghighlight)
				self.postname(a.group(1))
				if body == "":
					body = "`|" + htmlspecialchars(self.parsenamehtmlhighlight(a.group(1)), ENT_QUOTES) + "`|"
			if taghighlight != "":
				output += "<" + taghighlight + ">"
			output += body
		return output

	def action(self):
		self.parsebq(self.actioncommand, self.record, 1)


class recordholder_tableid(recordholder):
	def __init__(self, rh=None, record=None, tablename="", prefix="", par=""):
		super().__init__(rh, record, tablename, prefix, par)
		t = G().tablelist.get(tablename)
		if t is None:
			return
		self.record = t.getrecord(to_int(rh.parsewithbq(par, record)))


class recordholder_stableid(recordholder):
	def __init__(self, rh=None, record=None, tablename="", prefix="", par=""):
		super().__init__(rh, record, tablename, prefix, par)
		t = G().tablelist["simple"].gettable(tablename)
		self.record = t.getrecord(to_int(rh.parsewithbq(par, record)))


class selectrecord(Registered):
	tablename = "select"

	def __init__(self, fields):
		self.fields = PhpArray()
		for key, val in fields.items():
			m = re.match(r'^mixed(.*)', key)
			if not m:
				self.fields[key] = val
				continue
			for chunk in to_str(val).split("\t"):
				a2 = chunk.split("=", 1)
				if len(a2) < 2:
					continue
				self.fields[a2[0] + urllib.parse.unquote(m.group(1))] = urllib.parse.unquote(a2[1])

	def getfield(self, field):
		return self.fields.get(field)

	def dumpfields(self, title=""):
		if debuglog.enabled(G()):
			debuglog.on_fields(G(), self, title)


# ---- command parsers ----

class commandparser(Registered):		# volatile object
	def __init__(self, par="", parent=None, before=None, name=""):
		g = G()
		self.cond = 1
		self.par = par
		self.parent = parent
		self.before = before
		self.name = name
		self.children = []
		self.index = g.commandparserindex
		g.commandparserindex += 1
		if self.parent is not None:
			self.parent.addchild(self)

	def addchild(self, commandparser=None):
		self.children.append(commandparser)

	def parsehtml(self, rh=None, record=None, index=1):
		g = G()
		g.coverage_id = self.index
		if debuglog.enabled(g):
			debuglog.on_parse(g, self)
		self.parsehtmlinner(rh, record)

	def parsehtmlinner(self, rh=None, record=None):
		g = G()
		g.coverage_id = self.index
		g.coverage_count[g.coverage_id] = to_int(g.coverage_count.get(g.coverage_id)) + 1
		for index, child in enumerate(self.children):
			child.parsehtml(rh, record, index)

	def output(self, html="", htmlhighlight=""):
		if html == "":
			return
		if self.parent is not None:
			self.parent.output(html, htmlhighlight)
			return
		G().htmloutput += html


class commandparserhtml(commandparser):
	def parsehtml(self, rh=None, record=None, index=1):
		highlight = rh.parsehtmlhighlight(rh.parsewithbqhighlight(self.par, record))
		html = ""
		htmlhighlight = ""
		flag = 0
		for key, val in enumerate(highlight.split("`")):
			if key == 0:
				html += val
				htmlhighlight += htmlspecialchars(val)
				continue
			s = ""
			if val[:1] == "!":
				s = "`"
			elif val[:1] == "?":
				flag ^= 1
			elif val[:1] == "|":
				flag ^= 2
			sclose = ""
			if flag in (1, 3):
				htmlhighlight += '<b style="color: #ff0000;">'
				sclose = "</b>"
			elif flag == 2:
				htmlhighlight += '<span style="color: #0000ff;">'
				sclose = "</span>"
			html += s + val[1:]
			htmlhighlight += htmlspecialchars(s + val[1:]) + sclose
		if debuglog.enabled(G()):
			debuglog.on_html(G(), self, html, htmlhighlight)
		self.output(html, htmlhighlight)


class commandparserrecordholder(commandparser):
	def parsehtmlinner(self, rh=None, record=None):
		g = G()
		a = self.par.split(" ", 1)
		s0 = rh.parsewithbq(a[0], record)
		a2 = s0.split("=", 1)
		if len(a2) == 1:
			tablename = alias = s0
			prefix = ""
		else:
			alias, tablename = a2
			prefix = alias + "_"
		h = g.recordholderlist.get(alias)
		if h is None:
			h = ClassRegistry.new("recordholder_" + self.name, rh, record, tablename, prefix, php_trim(a[1] if len(a) > 1 else ""))
			g.recordholderlist[alias] = h
		super().parsehtmlinner(h)


class commandparser_if(commandparser):
	def parsehtmlinner(self, rh=None, record=None):
		self.cond = 0 if to_int(rh.parsewithbq(self.par, record)) == 0 else 1
		if self.cond < 1:
			return
		super().parsehtmlinner(rh, record)


class commandparser__elseif(commandparser):
	def parsehtmlinner(self, rh=None, record=None):
		if self.before is None or self.before.cond == 0:
			pass
		else:
			self.cond = -1
			return
		self.cond = 0 if to_int(rh.parsewithbq(self.par, record)) == 0 else 1
		if self.cond < 1:
			return
		super().parsehtmlinner(rh, record)


class commandparser__else(commandparser):
	def parsehtmlinner(self, rh=None, record=None):
		if self.before is None or self.before.cond == 0:
			pass
		else:
			self.cond = -1
			return
		self.cond = 1
		super().parsehtmlinner(rh, record)


class commandparser_selectrows(commandparser):
	def parsehtmlinner(self, rh=None, record=None):
		if record is None and rh is not None:
			record = rh.record
		rh2 = recordholder()
		sql = "select * " + rh2.parsewithbqinsql(self.par, record)
		s, lst = rh2.parsewhere(sql)
		self.cond = 0
		for val in execsql(s, lst, 0, 1):
			self.cond = 1
			super().parsehtmlinner(rh, selectrecord(val))


class commandparser_stablerows(commandparser):
	def parsehtmlinner(self, rh=None, record=None):
		self.cond = 0
		stable = G().tablelist["simple"].gettable(php_trim(self.par))
		if stable is None:
			return ""
		for recordid in stable.getrecordidlist():
			self.cond = 1
			super().parsehtmlinner(rh, stable.getrecord(recordid))


class daterecord(rootrecord):
	tablename = "date"

	def __init__(self, t, s=""):
		self.v_t = t
		self.v_s = phpdate.date(to_str(s), t)
		self.v_Y = phpdate.date("Y", t)
		self.v_y = phpdate.date("y", t)
		self.v_m = phpdate.date("m", t)
		self.v_n = phpdate.date("n", t)
		self.v_w = phpdate.date("w", t)
		self.v_W = phpdate.date("W", t)
		self.v_d = phpdate.date("d", t)
		self.v_j = phpdate.date("j", t)


class commandparser_dayrows(commandparser):
	def parsehtmlinner(self, rh=None, record=None):
		a = rh.parsewithbq(self.par, record).split(" ", 2)
		t = to_int(a[0])
		count = to_int(a[1] if len(a) > 1 else None)
		if count == 0:
			count = 1
		fmt = a[2] if len(a) > 2 else None
		while count > 0:
			super().parsehtmlinner(rh, daterecord(t, fmt))
			t += 86400
			count -= 1
		while count < 0:
			super().parsehtmlinner(rh, daterecord(t, fmt))
			t -= 86400
			count += 1


class commandparser_wdayrows(commandparser):
	def parsehtmlinner(self, rh=None, record=None):
		a = rh.parsewithbq(self.par, record).split(" ", 3)
		t = to_int(a[0])
		count = to_int(a[1] if len(a) > 1 else None)
		if count == 0:
			count = 1
		start = to_int(a[2] if len(a) > 2 else None)
		fmt = a[3] if len(a) > 3 else None

		for i in range(7):
			r = daterecord(t)
			if loose_eq(r.v_w, start):
				break
			t -= 86400

		while count > 0:
			super().parsehtmlinner(rh, daterecord(t, fmt))
			t += 86400
			count -= 1
		while count < 0:
			for i in range(7):
				super().parsehtmlinner(rh, daterecord(t, fmt))
				t += 86400
				count += 1
			t -= 86400 * 14


class commandparser_valid(commandparser):
	def parsehtmlinner(self, rh=None, record=None):
		g = G()
		self.cond = 0
		if not ispost():
			return
		a = self.par.split(" ", 1)
		s0 = a[1] if len(a) > 1 else ""
		if s0 == "":
			s0 = g.beforename
		s = rh.record.getfield(s0)
		m = re.match(r'^([_A-Za-z]+)(.*)', a[0])
		if not m:
			return
		cmd = "tv_" + m.group(1)
		fn = getattr(rh.record, cmd, None)
		if not callable(fn):
			return
		if not fn(m.group(2), s):
			return
		g.invalid = self.cond = 1
		super().parsehtmlinner(rh, record)


# ---- request ----

def _phpkey(k):
	"""PHP converts " ", "." and "[" in incoming variable names to "_"."""
	k = k.lstrip(" ")
	return re.sub(r'[ .\[]', "_", k)


def _parsequery(s):
	ret = PhpArray()
	for k, v in urllib.parse.parse_qsl(s, keep_blank_values=True):
		ret[_phpkey(k)] = v
	return ret


def _parsecookie(s):
	ret = PhpArray()
	for chunk in s.split(";"):
		a = chunk.split("=", 1)
		if len(a) < 2:
			continue
		k = _phpkey(a[0].strip())
		if k not in ret:
			ret[k] = urllib.parse.unquote_plus(a[1].strip())
	return ret


def main(environ, tables, basedir):
	"""Top level of index.php. Returns (status, headers, body)."""
	sys = Sys()
	get = _parsequery(environ.get("QUERY_STRING", ""))
	post = PhpArray()
	if environ.get("REQUEST_METHOD") == "POST" and environ.get("CONTENT_TYPE", "").startswith("application/x-www-form-urlencoded"):
		n = to_int(environ.get("CONTENT_LENGTH"))
		post = _parsequery(environ["wsgi.input"].read(n).decode("utf-8", "replace"))
	g = Request(sys, get, post, _parsecookie(environ.get("HTTP_COOKIE", "")), environ)
	token = _req.set(g)
	try:
		try:
			_main(g, environ, tables, basedir)
		except Exit as e:
			body = g.printbuf + e.message
		else:
			body = g.printbuf
		status = g.status or "200 OK"
		if g.status is None and any(h[0].lower() == "location" for h in g.headers):
			status = "302 Found"
		return status, [("Content-Type", "text/html; charset=UTF-8")] + g.headers, body
	finally:
		if g.db0 is not None:
			g.db0.close()
		_req.reset(token)


def _main(g, environ, tables, basedir):
	sys = g.sys
	sys.url = "https://" if environ.get("HTTPS") == "on" else "http://"
	sys.url += environ.get("HTTP_HOST", "") + environ.get("SCRIPT_NAME", "") + environ.get("PATH_INFO", "")
	sys.urlquery = environ.get("QUERY_STRING") or None

	tables.tables(sys)		# require("tables.php");
	if sys.useimporter:		# include("importer.php") in env.php
		import importer
		importer.handle()

	if php_trim(myhash("")) == "":
		raise Exit("hash not work")

	# Same as PHP when mounted as ".../index.php", so that PHP and Python share the cookies.
	if not environ.get("SCRIPT_NAME", "").endswith("/index.php"):
		g.cookiepath = environ.get("SCRIPT_NAME", "")
	elif (m := re.search(r'://[^/]+/(.*)/index[.]php', sys.url)):
		g.cookiepath = "/" + m.group(1)
	else:
		g.cookiepath = ""
	while g.cookiepath.endswith("/"):
		g.cookiepath = g.cookiepath[:-1]

	sys.urlbase = sys.url[:len(sys.url) - len(environ.get("PATH_INFO", ""))]
	sys.urlbaseparent = re.sub(r'/index[.]php$', "", sys.urlbase) if sys.urlbase.endswith("/index.php") else sys.urlbase.rsplit("/", 1)[0]
	m = re.match(r'^/([^/]*)/([0-9A-Za-z]+)[.]html$', environ.get("PATH_INFO", ""))
	if not m:
		header("Location: %s/nofmt/%s.html" % (sys.urlbase, sys.rootpage))
		log_die()
	g.orgdebugfn = orgdebugfn = m.group(1)
	sys.target = m.group(2)

	if orgdebugfn == "nopost":
		raise Exit("<h1>view only.</h1>")

	if (s := g._POST.get(":reportbody")) is not None:
		body = to_str(s)
		link = ""
		if sys.debugdir is not None and to_str(sys.urlbase) != "":
			link = "%s/%s/%s.php" % (sys.urlbaseparent, sys.debugdir, orgdebugfn)
		if to_str(sys.reportjsonurl) != "":
			report(body, link)
			echo('<HEAD><META http-equiv=refresh content="2; %s"></HEAD>\n<H2>Report sent! Thank you!</H2>\n' % sys.url)
			log_die()
		log_die()

	innersimpletable()

	t = g.tablelist["simple"].gettable("__dbinfo")
	a = t.getlist()
	updated = 0
	row0 = a.get(0)
	for key, obj in g.tablelist.items():
		if not loose_eq(row0.get(key) if row0 is not None else None, obj.getconfig()):
			updated = 1
			break
	if updated:
		for key, obj in g.tablelist.items():
			obj.createtable()
		t = g.tablelist["simple"].gettable("__dbinfo")
		a = PhpArray()
		for key, obj in g.tablelist.items():
			a[key] = obj.getconfig()
		t.updatelist(PhpArray([a]))

	for obj in list(g.tablelist.values()):
		obj.onload()

	try:
		with open(os.path.join(basedir, sys.htmlbase, sys.target + ".html"), encoding="utf-8") as f:
			targethtml = f.read()
	except (OSError, UnicodeDecodeError):
		header("Location: %s/nopage/%s.html" % (sys.urlbase, sys.rootpage))
		log_die()

	if debuglog.enabled(g):
		debuglog.on_request(g, targethtml)

	g.recordholderlist = PhpArray()
	g.actionrecordholder = None

	g.rootparser = commandparser()
	for phase in range(2):
		g.phase = phase
		g.beforename = ""
		g.beforenopost = None
		g.invalid = 0
		lastparser = commandparser("", g.rootparser, None, "(phase%d)" % phase)
		parserstack = [lastparser]
		g.htmloutput = ""
		for key, chunk in enumerate(targethtml.split("<!--{")):
			if key == 0:
				commandparserhtml(chunk, parserstack[0])
				continue
			for key2, chunk2 in enumerate(chunk.split("<!--}")):
				if key2 == 0:
					a = chunk2.split("-->", 1)
					a2 = a[0].split(" ", 1)
					par = a2[1] if len(a2) > 1 else ""
					if ClassRegistry.exists(s := "commandparser_" + a2[0]):
						obj = ClassRegistry.new(s, par, parserstack[0], None, a2[0])
					elif ClassRegistry.exists("recordholder_" + a2[0]):
						obj = commandparserrecordholder(par, parserstack[0], None, a2[0])
					else:
						obj = commandparser(par, parserstack[0], None)
					parserstack.insert(0, obj)
					commandparserhtml(a[1] if len(a) > 1 else "", parserstack[0])
					continue
				if len(parserstack) > 1:
					beforeparser = parserstack.pop(0)
				else:
					trigger_error("stack empty.")
					beforeparser = parserstack[0]
				a = chunk2.split("-->", 1)
				rest = a[1] if len(a) > 1 else ""
				if a[0][:1] == "{":
					a2 = a[0].split(" ", 1)
					cls = ClassRegistry.get("commandparser__" + a2[0][1:]) or commandparser
					parserstack.insert(0, cls(a2[1] if len(a2) > 1 else "", parserstack[0], beforeparser, a2[0][1:]))
				commandparserhtml(rest, parserstack[0])
		lastparser.parsehtml(recordholder())

	if g.actionrecordholder is not None:
		g.actionrecordholder.action()
	echo(g.htmloutput)
	log_die()


import builtinfunc  # noqa: E402,F401  (registers bq_* functions)
