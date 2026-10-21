#
#	nophp (python port)	https://github.com/paijp/nophp
#
#	Copyright (c) 2021-2023 paijp
#
#	This software is released under the MIT License.
#	http://opensource.org/licenses/mit-license.php
#
#	Proof of concept: template engine core of index.php
#	(commandparser*, recordholder::parsebq and friends).
#	Database tables, login, debuglog files and coverage are not ported yet.
#

import contextvars
import re

from phpcompat import (
	ClassRegistry, Registered, PhpArray, ENT_QUOTES,
	to_int, to_float, to_str, htmlspecialchars, nl2br, rawurlencode,
	php_round, get_class_methods, call_user_func_array,
)


class Exit(Exception):
	"""log_die() / header("Location: ...") + exit."""
	def __init__(self, status="200 OK", headers=None, body=""):
		super().__init__(status)
		self.status = status
		self.headers = headers or []
		self.body = body


def redirect(url):
	return Exit("302 Found", [("Location", url)])


class Sys:
	pass


class Request:
	"""All PHP globals that live for one request.
	mod_wsgi keeps the module loaded between requests, so these must not be module globals."""
	def __init__(self, sys, get=None, post=None):
		self.sys = sys
		self._GET = PhpArray(get or {})
		self._POST = PhpArray(post or {})
		self.debuglog = ""
		self.htmloutput = ""
		self.phase = 0
		self.invalid = 0
		self.loginrecord = None
		self.tablelist = PhpArray()
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


def ispost():
	g = G()
	if g.loginrecord is None:
		return 0
	if to_str(getattr(g.loginrecord, "v_submitkey", "")) == "":
		return 0
	if to_str(g._POST.get("submitkey")) == to_str(g.loginrecord.v_submitkey):
		return 1
	return 0


# ---- bq functions (get_defined_functions()["user"] replacement) ----

_bqfunctions = {}


def bqfunc(f):
	"""Register bq_*/bq2_* function. Later registration (tables.py) overrides builtin,
	like the function_exists() guard in builtinfunc.php."""
	_bqfunctions[f.__name__] = f
	return f


def _getfunclist():
	g = G()
	if g.funclist is None:
		g.funclist = {}
		for s in _bqfunctions:
			m = re.match(r'^bq2?_(.*)', s)
			if m is None:
				continue
			s0 = m.group(1).split("__", 1)[0]
			g.funclist[s0] = s
	return g.funclist


# ---- records ----

class rootrecord(Registered):
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
		pass


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

	def _call(self, obj, fn, cmd, record):
		pars = self.popstack(cmd, " ".join(fn.split("__")[1:]))
		self.pushstack(call_user_func_array([obj, fn], [self, record] + pars))

	def callfunc(self, rh, cmd, record=None, issubmit=0):
		g = G()
		if rh is None:
			s1, = self.popstack(cmd, "recordholder")
			rh = g.recordholderlist.get(s1)
			if rh is None:
				self.pushstack([])
				s = "no recordholder(%s)" % s1
				trigger_error(s)
				g.debuglog += "<h3>" + htmlspecialchars(s) + "</h3>"
				self.pushstack([])
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
		s = "no recordholder function(%s in %s)" % (cmd, type(self).__name__)
		trigger_error(s)
		g.debuglog += "<h3>" + htmlspecialchars(s) + "</h3>"
		self.pushstack([])

	def callfunctable(self, t, cmd, record=None, issubmit=0):
		g = G()
		if t is None:
			s1, = self.popstack(cmd, "table:t")
			t = g.tablelist.get(s1)
			if t is None:
				self.pushstack([])
				s = "no table(%s)" % s1
				trigger_error(s)
				g.debuglog += "<h3>" + htmlspecialchars(s) + "</h3>"
				self.pushstack([])
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
		s = "no table function(%s in %s)" % (cmd, type(self).__name__)
		trigger_error(s)
		g.debuglog += "<h3>" + htmlspecialchars(s) + "</h3>"
		self.pushstack([])

	def flush_coverage(self):
		if G().coverage_list is None:
			return
		# coverage log is not ported yet.

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
					self.pushstack(["HOME"])
					self.flush_coverage()
					raise redirect("%s/nofmt/%s.html" % (sys.urlbase, sys.rootpage))
				elif cmd in ("andhome", "iandhome", "orhome", "iorhome"):
					s1, = self.popstack(cmd, "val")
					if (to_int(s1) != 0) == cmd.endswith("andhome"):
						self.pushstack(["HOME"])
						self.flush_coverage()
						raise redirect("%s/nofmt/%s.html" % (sys.urlbase, sys.rootpage))
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
					raise redirect(s)
				elif cmd == "logout":
					g.tablelist["login"].logout()
					raise Exit()
				elif re.match(r'^h_', cmd):
					self.callfunc(None, cmd, record, issubmit)
				elif re.match(r'^H_', cmd):
					self.callfunc(self, cmd, record, issubmit)
				elif re.match(r'^t_', cmd):
					self.callfunctable(None, cmd, record, issubmit)
				elif re.match(r'^T_', cmd):
					self.callfunctable(self.record, cmd, record, issubmit)
				elif re.match(r'^s_', cmd):
					raise NotImplementedError("stable is not ported yet")
				elif (m := re.match(r'^([a-z]id)([_0-9A-Za-z]*)$', cmd)):
					s = m.group(1)
					tablename2 = getattr(sys, s, None)
					if tablename2 is None:
						s = "no table shortcut(%s in %s)" % (cmd, type(self).__name__)
						trigger_error(s)
						g.debuglog += "<h3>" + htmlspecialchars(s) + "</h3>"
						self.pushstack([])
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
						s = "unknown command(%s in %s)" % (cmd, type(self).__name__)
						trigger_error(s)
						g.debuglog += "<h3>" + htmlspecialchars(s) + "</h3>"
						self.pushstack([])
						continue
					a0 = self.popstack(cmd, " ".join(s.split("__")[1:]))
					if re.match(r'^bq2_', s):
						a0 = [self, record] + a0
					a = _bqfunctions[s](*a0)
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
		elif to_str(checkval) == to_str(val):
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
				elif g.loginrecord is None and a.group(1) == ":login":
					raise NotImplementedError("login is not ported yet")
			elif tagtype == "input" and type == "checkbox" and (a := re.search(r'name="([^"]+)', tag)) and (a2 := re.search(r'value="([^"]+)', tag)):
				taghighlight = self._prefixname(taghighlight)
				self.postname(a.group(1), a2.group(1))
				if to_str(self.parsename(a.group(1))) == a2.group(1):
					taghighlight += "`| checked`|"
			elif tagtype == "input" and type == "radio" and (a := re.search(r'name="([^"]+)', tag)) and (a2 := re.search(r'value="([^"]+)', tag)):
				taghighlight = self._prefixname(taghighlight)
				self.postname(a.group(1), a2.group(1), 1)
				if self.parsenamehtmlhighlight(a.group(1)) == a2.group(1):
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
				if to_str(self.parsename(g.beforename)) == a.group(1):
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
		G().coverage_id = self.index
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
		for key, val in enumerate(highlight.split("`")):
			if key == 0:
				html += val
				continue
			s = "`" if val[:1] == "!" else ""
			html += s + val[1:]
		self.output(html)


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
			h = ClassRegistry.new("recordholder_" + self.name, rh, record, tablename, prefix, (a[1] if len(a) > 1 else "").strip())
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


# ---- page ----

def renderpage(targethtml, g):
	"""Main loop of index.php (from "$rootparser = new commandparser();")."""
	token = _req.set(g)
	try:
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
						s = "commandparser__" + a2[0][1:]
						cls = ClassRegistry.get(s) or commandparser
						parserstack.insert(0, cls(a2[1] if len(a2) > 1 else "", parserstack[0], beforeparser, a2[0][1:]))
					commandparserhtml(rest, parserstack[0])
			lastparser.parsehtml(recordholder())
		if g.actionrecordholder is not None:
			g.actionrecordholder.action()
		return g.htmloutput
	finally:
		_req.reset(token)


import builtinfunc  # noqa: E402,F401  (registers bq_* functions)
