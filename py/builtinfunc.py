#
#	nophp (python port)	https://github.com/paijp/nophp
#
#	Copyright (c) 2021-2023 paijp
#
#	This software is released under the MIT License.
#	http://opensource.org/licenses/mit-license.php
#
#	Port of builtinfunc.php (functions that do not need the database).
#	A function's name keeps the PHP convention: bq_NAME__ARG1__ARG2.
#

import math

from engine import bqfunc, G, ispost
from kana import mb_convert_kana
from phpcompat import to_int, to_float, to_str, loose_eq, loose_cmp, php_round
import phpdate


@bqfunc
def bq_dot():
	return ["."]


@bqfunc
def bq_col():
	return [":"]


@bqfunc
def bq_sp():
	return [" "]


@bqfunc
def bq_bq():
	return ["`"]


@bqfunc
def bq_null():
	return [""]


@bqfunc
def bq_hex__hex(hex):
	s = ""
	for i in range(0, len(hex), 2):
		try:
			s += chr(int(hex[i:i + 2], 16))
		except ValueError:		# filter_var() returns false -> chr(0)
			s += "\0"
	return [s]


@bqfunc
def bq_ispost():
	return ["1" if ispost() else ""]


@bqfunc
def bq_int__val(val):
	return [php_round(val)]


@bqfunc
def bq_isnull__val(val):
	return ["1" if loose_eq(val, "") else ""]


@bqfunc
def bq_h2z__hankaku(hankaku):
	return [mb_convert_kana(hankaku, "ASKV")]


@bqfunc
def bq_z2h__zenkaku(zenkaku):
	return [mb_convert_kana(zenkaku, "as")]


@bqfunc
def bq_sys__name(name):
	return [to_str(getattr(G().sys, "v_" + name, None))]


@bqfunc
def bq_now():
	return [G().sys.now]


@bqfunc
def bq_ymd2t__year__month__day(year, month, day):
	return [phpdate.mktime(0, 0, 0, to_int(month), to_int(day), to_int(year))]


@bqfunc
def bq_age2t__year(year):
	now = G().sys.now
	d = lambda f: int(phpdate.date(f, now))
	return [phpdate.mktime(d("H"), d("i"), d("s"), d("n"), d("j"), d("Y") - to_int(year))]


@bqfunc
def bq_todate__time__dateformat(time, dateformat):
	return [phpdate.date(dateformat, to_int(time))]


@bqfunc
def bq_cat__s__t(s, t):
	return [s + t]


@bqfunc
def bq_rcat__s__t(s, t):
	return [t + s]


@bqfunc
def bq_scat__s__t(s, t):
	return ["%s %s" % (s, t)]


@bqfunc
def bq_rscat__s__t(s, t):
	return ["%s %s" % (t, s)]


@bqfunc
def bq_ismatch__s__t(s, t):
	return [1 if t in s else ""]


@bqfunc
def bq_addzero__num__digits(num, digits):
	i = len(num.encode())		# strlen() counts bytes
	if i < to_int(digits):
		num = "0" * (to_int(digits) - i) + num
	return [num]


@bqfunc
def bq_add__i__j(i, j):
	return [to_float(i) + to_float(j)]


@bqfunc
def bq_sub__i__j(i, j):
	return [to_float(i) - to_float(j)]


@bqfunc
def bq_rsub__i__j(i, j):
	return [to_float(j) - to_float(i)]


@bqfunc
def bq_mul__i__j(i, j):
	return [to_float(i) * to_float(j)]


@bqfunc
def bq_div__i__j(i, j):
	if to_int(j) == 0:
		return [0]
	return [float(math.floor(to_int(i) / to_int(j)))]


@bqfunc
def bq_rdiv__i__j(i, j):
	if to_int(i) == 0:
		return [0]
	return [float(math.floor(to_int(j) / to_int(i)))]


def _intmod(a, b):
	"""PHP % keeps the sign of the dividend, Python % keeps the sign of the divisor."""
	r = abs(a) % abs(b)
	return -r if a < 0 else r


@bqfunc
def bq_mod__i__j(i, j):
	if to_int(j) == 0:
		return [0]
	return [_intmod(to_int(i), to_int(j))]


@bqfunc
def bq_rmod__i__j(i, j):
	if to_int(i) == 0:
		return [0]
	return [_intmod(to_int(j), to_int(i))]


@bqfunc
def bq_eq__i__j(i, j):
	return [1 if loose_eq(i, j) else ""]


@bqfunc
def bq_ieq__i__j(i, j):
	return [1 if to_int(i) == to_int(j) else ""]


@bqfunc
def bq_seq__s__t(s, t):
	return [1 if s == t else ""]


@bqfunc
def bq_ne__s__t(s, t):
	return [1 if not loose_eq(s, t) else ""]


@bqfunc
def bq_ine__i__j(i, j):
	return [1 if to_int(i) != to_int(j) else ""]


@bqfunc
def bq_sne__s__t(s, t):
	return [1 if s != t else ""]


@bqfunc
def bq_lt__i__j(i, j):
	return [1 if loose_cmp(i, j) < 0 else ""]


@bqfunc
def bq_ilt__i__j(i, j):
	return [1 if to_int(i) < to_int(j) else ""]


@bqfunc
def bq_slt__s__t(s, t):
	return [1 if loose_cmp("_" + s, "_" + t) < 0 else ""]


@bqfunc
def bq_gt__i__j(i, j):
	return [1 if loose_cmp(i, j) > 0 else ""]


@bqfunc
def bq_igt__i__j(i, j):
	return [1 if to_int(i) > to_int(j) else ""]


@bqfunc
def bq_sgt__s__t(s, t):
	return [1 if loose_cmp("_" + s, "_" + t) > 0 else ""]


@bqfunc
def bq_le__i__j(i, j):
	return [1 if loose_cmp(i, j) <= 0 else ""]


@bqfunc
def bq_ile__i__j(i, j):
	return [1 if to_int(i) <= to_int(j) else ""]


@bqfunc
def bq_sle__s__t(s, t):
	return [1 if loose_cmp("_" + s, "_" + t) <= 0 else ""]


@bqfunc
def bq_ge__i__j(i, j):
	return [1 if loose_cmp(i, j) >= 0 else ""]


@bqfunc
def bq_ige__i__j(i, j):
	return [1 if to_int(i) >= to_int(j) else ""]


@bqfunc
def bq_sge__s__t(s, t):
	return [1 if loose_cmp("_" + s, "_" + t) >= 0 else ""]


@bqfunc
def bq_dup__val(val):
	return [val, val]


@bqfunc
def bq_sor__s__t(s, t):
	if loose_eq(t, ""):
		return [s]
	return [t]


@bqfunc
def bq_sand__s__t(s, t):
	if not loose_eq(t, ""):
		return [s]
	return [t]


@bqfunc
def bq_ior__i__j(i, j):
	if to_int(j) == 0:
		return [to_int(i)]
	return [to_int(j)]


@bqfunc
def bq_iand__i__j(i, j):
	if to_int(j) != 0:
		return [to_int(i)]
	return [to_int(j)]


@bqfunc
def bq_loginrecord__field(field):
	g = G()
	if g.loginrecord is not None:
		return [to_str(g.loginrecord.getfield(field))]
	return [""]


@bqfunc
def bq2_curid(rh0, record):
	return [to_int(getattr(rh0.record, "id", None))]


@bqfunc
def bq2_curtable(rh0, record):
	return [to_str(getattr(rh0.record, "tablename", None))]


@bqfunc
def bq_curpage():
	return [G().sys.target]


@bqfunc
def bq_isvalid():
	return ["" if G().invalid else "1"]


@bqfunc
def bq_g__name(name):
	import re
	return [re.sub(r'[^,0-9]', "", to_str(G()._GET.get(name)))]


@bqfunc
def bq2_p__name(rh0, record, name):
	# PHP version (bq_p__name) refers $this->prefix inside a plain function,
	# and it is a fatal error on POST. Here the recordholder is used as intended.
	s = ""
	if ispost():
		postkey = rh0.prefix + name.replace(" ", "_").replace(".", "_")
		s = G()._POST.get(postkey)
	return [s]


@bqfunc
def bq2_r__field(rh0, record, field):
	return [to_str(record.getfield(field))]


@bqfunc
def bq2_set__val__field(rh0, record, val, field):
	record.setfield(field, val)
	return []


@bqfunc
def bq2_sqlisnull__field(rh0, record, field):
	rh0.whereargs["%s is null" % field] = []
	return []


@bqfunc
def bq2_sqlisnotnull__field(rh0, record, field):
	rh0.whereargs["%s is not null" % field] = []
	return []


@bqfunc
def bq2_sqlisempty__field(rh0, record, field):
	rh0.whereargs["(%s is null or %s = ?)" % (field, field)] = [""]
	return []


@bqfunc
def bq2_sqlisnotempty__field(rh0, record, field):
	rh0.whereargs["%s is not null" % field] = []
	rh0.whereargs["%s <> ?" % field] = [""]
	return []


@bqfunc
def bq2_sqllike__val__field(rh0, record, val, field):
	rh0.whereargs["%s like ?" % field] = ["%" + val + "%"]
	return []


@bqfunc
def bq2_sqllike2__val__field1__field2(rh0, record, val, field1, field2):
	rh0.whereargs["(%s like ? or %s like ?)" % (field1, field2)] = ["%" + val + "%"] * 2
	return []


@bqfunc
def bq2_sqllike3__val__field1__field2__field3(rh0, record, val, field1, field2, field3):
	rh0.whereargs["(%s like ? or %s like ? or %s like ?)" % (field1, field2, field3)] = ["%" + val + "%"] * 3
	return []


@bqfunc
def bq2_sqllike4__val__field1__field2__field3__field4(rh0, record, val, field1, field2, field3, field4):
	rh0.whereargs["(%s like ? or %s like ? or %s like ? or %s like ?)" % (field1, field2, field3, field4)] = ["%" + val + "%"] * 4
	return []


@bqfunc
def bq2_sqlnotlike__val__field(rh0, record, val, field):
	rh0.whereargs["%s not like ?" % field] = ["%" + val + "%"]
	return []


def _sqlcmp(op):
	def f(rh0, record, val, field):
		rh0.whereargs["? %s %s" % (op, field)] = [val]
		return []
	return f


for _name, _op in (("eq", "="), ("ne", "<>"), ("lt", "<"), ("le", "<="), ("gt", ">"), ("ge", ">=")):
	_f = _sqlcmp(_op)
	_f.__name__ = "bq2_sql%s__val__field" % _name
	bqfunc(_f)
