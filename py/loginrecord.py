#
#	nophp (python port)	https://github.com/paijp/nophp
#
#	Copyright (c) 2021-2023 paijp
#
#	This software is released under the MIT License.
#	http://opensource.org/licenses/mit-license.php
#
#	Port of loginrecord.php (v0.0.4).
#

import os
import re
import subprocess

from engine import (
	G, a_table, ispost, execsql, header, setcookie, log_die, myhash,
	phpfunction, function_exists, call_function,
)
from phpcompat import to_int, to_str, loose_eq


def _bq_login(type=None, r=None):
	g = G()
	sys = g.sys
	if type is None:
		return []
	if type == "emptypass":		# empty password
		header("Location: %s?mode=2mailsent" % sys.url)
		mailcmd = to_str(sys.mailcmd)
		if mailcmd == "":
			log_die("mailcmd empty.")
		mailbody = to_str(sys.mailbody)
		if mailbody == "":
			log_die("mailbody empty.")
		if to_int(sys.mailinterval) <= 0:
			log_die("mailinterval <= 0.")

		login = to_str(g._POST.get("login"))
		if login == "":
			log_die("login empty.")
		if not re.fullmatch(r'[0-9A-Za-z][-_.@+0-9A-Za-z]*\n?', login):
			log_die("invalid character in login.")
		lst = g.tablelist["login"].getrecordidlist("where login = ?", [login])
		if len(lst) < 1:
			log_die("no login found")
		uid = lst[0]
		r = g.tablelist["login"].getrecord(uid)

		if r.v_mailsent is not None and to_int(r.v_mailsent) > to_int(sys.now) - to_int(sys.mailinterval):
			log_die("mail interval too short.%s" % r.id)

		key = r.getrandom()
		r.setmailkey(key)

		url = "%s?mode=1login&uid=%s&key=%s&" % (sys.url, uid, key)
		mailcmd = mailcmd.replace("@addr@", login).replace("@url@", url)
		mailbody = mailbody.replace("@addr@", login).replace("@url@", url)
		try:
			subprocess.run(["/bin/sh", "-c", mailcmd.encode("utf-8", "surrogateescape")], input=mailbody.encode(), timeout=60)
		except (OSError, subprocess.SubprocessError):
			log_die("popen failed: " + mailcmd)
		log_die("mail sent.%s" % r.id)
	# "goodlogin", "badlogin", "logout", "changelogin", "changepass": nothing to do.
	return []


class a_login_table(a_table):
	def getconfig(self):
		return super().getconfig() + "login\ttext unique not null\npass\ttext\nsalt\ttext\nsessionkey\ttext\nlastlogin\tint\nlastlogout\tint\n"

	def createtable(self):
		sys = G().sys
		super().createtable()
		if to_str(sys.defaultuser) == "":
			pass
		elif len(self.getrecordidlist()) == 0:
			r = self.getrecord()
			r.v_login = sys.defaultuser
			if to_str(sys.defaultpass) != "":
				r.setpassword(sys.defaultpass, 1)
			else:
				r.update(1)

	def onload(self):
		sys = G().sys
		if not function_exists("bq_login"):
			_bq_login.__name__ = "bq_login"
			phpfunction(_bq_login)
			G().funclist = None

		if self.is_login() <= 0:
			sys.target = "index"
		elif sys.noredirectonlogin:
			pass
		elif sys.target == "index":
			header("Location: %s/index/%s.html" % (sys.urlbase, sys.rootpage))
			log_die()

		g = G()
		if g.loginrecord is None and g._POST.get(":login") is not None:
			self.check_loginform()
			log_die()
		if ispost() and g._POST.get(":logout") is not None:
			self.logout()
			log_die()

	def update(self, ignoreerror=0):
		sys = G().sys
		r = self.getrecord(self.id)
		if not loose_eq(self.v_login, r.v_login) and function_exists("bq_login"):
			call_function("bq_login", "changelogin", r)
		self.v_salt = r.v_salt
		self.v_pass = r.v_pass
		if sys.forcelogoutonupdate:
			self.v_sessionkey = ""
			self.v_mailkey = ""
		super().update(ignoreerror)

	def getrandom(self):
		return os.urandom(20).hex()

	def setmailkey(self, key):
		sys = G().sys
		self.v_mailkey = myhash(to_str(self.v_login) + key)
		self.v_mailsent = sys.now
		a_table.update(self)

	def setpassword(self, newpass, ignoreerror=0):
		if function_exists("bq_login"):
			call_function("bq_login", "changepass", self)
		self.v_salt = self.getrandom()
		self.v_pass = myhash(to_str(newpass) + self.v_salt)
		self.v_sessionkey = ""
		self.v_mailkey = ""
		a_table.update(self, ignoreerror)

	def login(self, pass_="", ismaillogin=0):
		g = G()
		sys = g.sys
		if ismaillogin:
			self.v_mailkey = ""
			self.v_salt = self.getrandom()
			self.v_pass = ""
		elif not loose_eq(myhash(to_str(pass_) + to_str(self.v_salt)), self.v_pass):
			if function_exists("bq_login"):
				call_function("bq_login", "badlogin", self)
			log_die("login fail.%s" % self.id)
		if function_exists("bq_login"):
			call_function("bq_login", "goodlogin", self)
		key = self.getrandom()
		self.v_sessionkey = myhash(to_str(self.v_salt) + key)
		self.v_submitkey = self.getrandom()
		self.v_lastlogin = sys.now
		a_table.update(self)
		https = g._SERVER.get("HTTPS") == "on"
		setcookie("sessionid", to_str(self.id), 0, g.cookiepath, "", https, True)
		setcookie("sessionid", to_str(self.id), 0, g.cookiepath + "/", "", https, True)
		setcookie("sessionkey", key, 0, g.cookiepath, "", https, True)
		setcookie("sessionkey", key, 0, g.cookiepath + "/", "", https, True)
		log_die("login success(%s).%s" % (to_str(ismaillogin), self.id))

	def check_loginform(self):
		g = G()
		sys = g.sys
		header("Location: " + sys.url)

		if loose_eq(g._GET.get("mode"), "1login"):
			uid = to_int(g._GET.get("uid"))
			key = to_str(g._GET.get("key"))
			r = self.getrecord(uid)
			if to_str(r.v_mailkey) == "":
				log_die("mailkey empty.%s" % r.id)
			if to_int(sys.mailexpire) <= 0:
				pass
			elif to_int(r.v_mailsent) < to_int(sys.now) - to_int(sys.mailexpire):
				log_die("mailexpire.%s" % r.id)
			if loose_eq(myhash(to_str(r.v_login) + key), r.v_mailkey):
				pass_ = g._POST.get("pass")
				if loose_eq(pass_, ""):
					r.login("", 2)
					log_die()
				r.setpassword(pass_)
				log_die("password change success.%s" % r.id)
			log_die("maillogin fail.%s" % r.id)

		login = g._POST.get("login")
		if login is None:
			if function_exists("bq_login"):
				call_function("bq_login", "badlogin")
			log_die("login null.")
		pass_ = g._POST.get("pass")
		if loose_eq(pass_, ""):
			if function_exists("bq_login"):
				call_function("bq_login", "emptypass")
			log_die("pass null.")
		lst = self.getrecordidlist("where login = ?", [login])
		if len(lst) < 1:
			if function_exists("bq_login"):
				call_function("bq_login", "badlogin")
			log_die("no login found")
		r = self.getrecord(lst[0])
		r.login(pass_)
		log_die("login fail.%s" % r.id)

	def is_login(self):
		g = G()
		g.loginrecord = None
		sessionid = g._COOKIE.get("sessionid")
		if sessionid is None:
			return 0
		key = g._COOKIE.get("sessionkey")
		if key is None:
			return 0
		g.loginrecord = self.getrecord(sessionid)
		if to_str(g.loginrecord.v_submitkey) == "":
			pass
		elif to_str(g.loginrecord.v_sessionkey) == "":
			pass
		elif loose_eq(g.loginrecord.v_sessionkey, myhash(to_str(g.loginrecord.v_salt) + key)):
			execsql("begin;")
			g.loginrecord = self.getrecord(sessionid)
			return 1
		g.loginrecord = None
		return 0

	def logout(self):
		g = G()
		sys = g.sys
		if g.loginrecord is not None:
			if function_exists("bq_login"):
				call_function("bq_login", "logout", g.loginrecord)
			g.loginrecord.v_lastlogout = sys.now
			g.loginrecord.v_sessionkey = ""
			g.loginrecord.mailkey = ""		# (sic) same as PHP
			g.loginrecord.update()
			execsql("commit;")
			g.loginrecord = None
		https = g._SERVER.get("HTTPS") == "on"
		setcookie("sessionid", "", 1, g.cookiepath, "", https, True)
		setcookie("sessionid", "", 1, g.cookiepath + "/", "", https, True)
		setcookie("sessionkey", "", 1, g.cookiepath, "", https, True)
		setcookie("sessionkey", "", 1, g.cookiepath + "/", "", https, True)
		header("Location: %s/logout/%s.html" % (sys.urlbase, sys.rootpage))
