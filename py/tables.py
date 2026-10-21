#
#	Port of tables.php (user definitions).
#	Classes are found by name (class_exists / new $s) through phpcompat.ClassRegistry,
#	so simply defining a subclass here is enough, as in PHP.
#

from engine import commandparser, recordholder, bqfunc
from phpcompat import htmlspecialchars, nl2br, ENT_QUOTES


class commandparser_htmlsrc(commandparser):
	def output(self, s="", htmlhighlight=""):
		super().output(nl2br(htmlspecialchars(s, ENT_QUOTES)))


# ---- for the PHP/Python comparison test only ----

class recordholder_demo(recordholder):
	def __init__(self, rh=None, record=None, tablename="", prefix="", par=""):
		super().__init__(rh, record, tablename, prefix, par)
		self.record.v_name = "demo:" + tablename + ":" + par
		self.record.id = 7

	def h_greet__who(self, rh, record, who):
		return ["hello " + who + " from " + self.prefix]


@bqfunc
def bq_twice__s(s):
	return [s + s]
