#
#	Port of tables.php.
#	Classes are found by name (class_exists / new $s) through phpcompat.ClassRegistry,
#	so simply defining a subclass here is enough, as in PHP.
#	Statements at the top level of tables.php are written in tables(), which runs on every request.
#

from env import env
from engine import commandparser, a_table
import builtinfunc		# noqa: F401
from loginrecord import a_login_table
import sendmail		# noqa: F401
from phpcompat import htmlspecialchars, nl2br, ENT_QUOTES


class login_table(a_login_table):
	pass


class commandparser_htmlsrc(commandparser):
	def output(self, s="", htmlhighlight=""):
		super().output(nl2br(htmlspecialchars(s, ENT_QUOTES)))


class message0_table(a_table):
	def getconfig(self):
		return super().getconfig() + "\n"


class message1_table(a_table):
	def getconfig(self):
		return super().getconfig() + "\n"

	def tv_mail(self, par, s):
		if "@" not in (s or ""):
			return 1
		return 0


class message2_table(a_table):
	def getconfig(self):
		return super().getconfig() + "owner\tinteger\t/*indexed*/\nbody\ttext\n\n"


class test2_table(a_table):
	def getconfig(self):
		return super().getconfig() + "\n"


class master0_table(a_table):
	def getconfig(self):
		return super().getconfig() + "\n\n"


class test3_table(a_table):
	def getconfig(self):
		return super().getconfig() + "\n"


def tables(sys):
	env(sys)
	sys.rootpage = "g9999"
	login_table()
	message0_table()
	message1_table()
	message2_table()
	test2_table()
	master0_table()
	sys.mid = "master0"
	test3_table()
