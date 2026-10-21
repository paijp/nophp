#
#	For the PHP/Python comparison test only.
#

from engine import recordholder, bqfunc


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
