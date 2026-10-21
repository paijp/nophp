#
#	nophp (python port)	https://github.com/paijp/nophp
#
#	PHP-compatible helpers.
#	Everything that behaves differently between PHP and Python is collected here,
#	so that the porting code can stay close to the original PHP.
#

import html
import math
import re
import urllib.parse


# ---- string <-> number (PHP 8 semantics) ----

_ws = " \t\n\r\v\f"
_numprefix = re.compile(r'[ \t\n\r\v\f]*[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?')
_numfull = re.compile(r'[ \t\n\r\v\f]*[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?[ \t\n\r\v\f]*\Z')


def is_numeric(v):
	if isinstance(v, bool):
		return False
	if isinstance(v, (int, float)):
		return True
	return isinstance(v, str) and _numfull.match(v) is not None


def to_float(v):
	"""(float)$v"""
	if v is None:
		return 0.0
	if isinstance(v, (bool, int, float)):
		return float(v)
	m = _numprefix.match(str(v))
	if m is None:
		return 0.0
	return float(m.group(0).strip(_ws))


def to_int(v):
	"""(int)$v  -- "12abc" -> 12, "1e3" -> 1000, "0x1A" -> 0, " 7" -> 7"""
	if v is None:
		return 0
	if isinstance(v, (bool, int)):
		return int(v)
	if isinstance(v, float):
		if math.isnan(v) or math.isinf(v):
			return 0
		return int(v)
	m = _numprefix.match(str(v))
	if m is None:
		return 0
	s = m.group(0).strip(_ws)
	if re.search(r'[.eE]', s):
		f = float(s)
		if math.isnan(f) or math.isinf(f):
			return 0
		return int(f)
	return int(s)


def float_str(f):
	"""string conversion of float (precision=14), e.g. 579.0 -> "579", 1e20 -> "1.0E+20" """
	if math.isnan(f):
		return "NAN"
	if math.isinf(f):
		return "INF" if f > 0 else "-INF"
	if f == 0:
		return "-0" if math.copysign(1, f) < 0 else "0"
	s = "%.14G" % f
	if "E" in s:
		mant, exp = s.split("E")
		if "." not in mant:
			mant += ".0"
		sign = exp[0]
		exp = exp[1:].lstrip("0") or "0"
		return "%sE%s%s" % (mant, sign, exp)
	return s


def to_str(v):
	"""$v."" """
	if v is None or v is False:
		return ""
	if v is True:
		return "1"
	if isinstance(v, float):
		return float_str(v)
	return str(v)


def _round_helper(f):
	"""PHP_ROUND_HALF_UP (keeps the sign of zero: round(-0.4) is -0)"""
	r = float(math.floor(f + 0.5) if f >= 0.0 else math.ceil(f - 0.5))
	return math.copysign(r, f) if r == 0.0 else r


def php_round(v, places=0):
	"""round() of PHP 8.0 -- rounds half away from zero, with "pre-rounding"
	(round(1.005, 2) is 1.01 in PHP, while Python's round() gives 1.0)."""
	value = to_float(v)
	if not math.isfinite(value) or value == 0.0:
		return value
	places = max(-4 * 15, min(places, 4 * 15))
	precision_places = 14 - int(math.floor(math.log10(abs(value))))
	f1 = 10.0 ** abs(places)
	if precision_places > places and precision_places - 15 < places:
		f2 = 10.0 ** abs(precision_places)
		tmp = value * f2 if precision_places >= 0 else value / f2
		tmp = _round_helper(tmp)
		use_precision = max(-4 * 15, places - precision_places)
		tmp = tmp / (10.0 ** abs(use_precision))
	else:
		tmp = value * f1 if places >= 0 else value / f1
		if abs(tmp) >= 1e15:
			return value
	tmp = _round_helper(tmp)
	if abs(places) < 23:
		tmp = tmp / f1 if places > 0 else tmp * f1
	else:
		tmp = float("%.15e" % tmp)
	return tmp


def to_bool(v):
	"""PHP truthiness: "", "0", 0, 0.0, null, empty array are false."""
	if v is None:
		return False
	if isinstance(v, str):
		return v != "" and v != "0"
	if isinstance(v, (list, dict, PhpArray)):
		return len(v) > 0
	return bool(v)


# ---- loose comparison (PHP 8) ----

def _cmp_key(v):
	if v is None:
		return ""
	if isinstance(v, bool):
		return int(v)
	return v


def loose_cmp(a, b):
	"""<=> with PHP 8 rules for scalars (string/int/float/null/bool)."""
	if isinstance(a, bool) or isinstance(b, bool) or a is None or b is None:
		x, y = to_bool(a), to_bool(b)
		if a is None and isinstance(b, str):
			x, y = "", b
		elif b is None and isinstance(a, str):
			x, y = a, ""
		return (x > y) - (x < y)
	if isinstance(a, str) and isinstance(b, str):
		if is_numeric(a) and is_numeric(b):
			x, y = to_float(a), to_float(b)
			if not (re.search(r'[.eE]', a) or re.search(r'[.eE]', b)):
				x, y = to_int(a), to_int(b)
			return (x > y) - (x < y)
		ab, bb = a.encode(), b.encode()
		return (ab > bb) - (ab < bb)
	if isinstance(a, str) and not is_numeric(a):
		b = to_str(b)
		return loose_cmp(a, b)
	if isinstance(b, str) and not is_numeric(b):
		a = to_str(a)
		return loose_cmp(a, b)
	x = to_float(a) if isinstance(a, str) else a
	y = to_float(b) if isinstance(b, str) else b
	return (x > y) - (x < y)


def loose_eq(a, b):
	"""$a == $b  -- "1" == "01" is true, "abc" == 0 is false (PHP 8)."""
	return loose_cmp(a, b) == 0


# ---- string functions ----

ENT_COMPAT = 2
ENT_QUOTES = 3


def htmlspecialchars(s, flags=ENT_COMPAT):
	"""PHP 8.0 default is ENT_COMPAT (single quote is not escaped)."""
	s = to_str(s)
	s = s.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;").replace('"', "&quot;")
	if flags & 1:
		s = s.replace("'", "&#039;")
	return s


def nl2br(s):
	return re.sub(r'(\r\n|\n\r|\n|\r)', r'<br />\1', to_str(s))


def rawurlencode(s):
	return urllib.parse.quote(to_str(s).encode("utf-8"), safe="-_.~")


def php_trim(s):
	return to_str(s).strip(" \t\n\r\0\x0b")


def escapeshellarg(s):
	return "'" + to_str(s).replace("'", "'\\''") + "'"


def explode(sep, s, limit=None):
	s = to_str(s)
	if limit is None:
		return s.split(sep)
	return s.split(sep, limit - 1)


def substr(s, start, length=None):
	s = to_str(s)
	if length is None:
		return s[start:]
	if length < 0:
		return s[start:length]
	if start < 0:
		start = len(s) + start
		if start < 0:
			start = 0
	return s[start:start + length]


# ---- associative array ----

def _normkey(k):
	"""PHP array key: "1" -> 1, "01" stays "01", True -> 1, 1.7 -> 1, None -> "" """
	if isinstance(k, bool):
		return int(k)
	if isinstance(k, float):
		return int(k)
	if k is None:
		return ""
	if isinstance(k, str) and re.fullmatch(r'-?(0|[1-9][0-9]*)', k) and k != "-0":
		i = int(k)
		if -(2 ** 63) <= i < 2 ** 63:
			return i
	return k


class PhpArray:
	"""Ordered map with PHP key normalization and PHP-style append ($a[] = v).
	Python dicts are passed by reference; use copy() where PHP copies on assignment."""

	__slots__ = ("_d", "_next")

	def __init__(self, src=None):
		self._d = {}
		self._next = 0
		if src is None:
			return
		if isinstance(src, PhpArray):
			self._d = dict(src._d)
			self._next = src._next
		elif isinstance(src, dict):
			for k, v in src.items():
				self[k] = v
		else:
			for v in src:
				self.append(v)

	def __getitem__(self, k):
		return self._d[_normkey(k)]

	def get(self, k, default=None):
		"""@$a[$k]"""
		return self._d.get(_normkey(k), default)

	def __setitem__(self, k, v):
		k = _normkey(k)
		self._d[k] = v
		if isinstance(k, int) and k >= self._next:
			self._next = k + 1

	def __delitem__(self, k):
		del self._d[_normkey(k)]

	def __contains__(self, k):
		return _normkey(k) in self._d

	def __len__(self):
		return len(self._d)

	def __iter__(self):
		return iter(self._d.values())

	def __eq__(self, o):
		return isinstance(o, PhpArray) and list(self._d.items()) == list(o._d.items())

	def __repr__(self):
		return "PhpArray(%r)" % self._d

	def append(self, v):
		self._d[self._next] = v
		self._next += 1

	def items(self):
		return self._d.items()

	def keys(self):
		return self._d.keys()

	def values(self):
		return self._d.values()

	def copy(self):
		return PhpArray(self)


# ---- dynamic class lookup ----

class ClassRegistry:
	"""Replacement for class_exists($name) / new $name(...).
	Classes register themselves by inheriting from Registered."""

	classes = {}

	@classmethod
	def exists(cls, name):
		return name.lower() in cls.classes

	@classmethod
	def get(cls, name):
		return cls.classes.get(name.lower())

	@classmethod
	def new(cls, name, *args):
		return cls.classes[name.lower()](*args)


class Registered:
	def __init_subclass__(cls, **kw):
		super().__init_subclass__(**kw)
		ClassRegistry.classes[cls.__name__.lower()] = cls


def get_class_methods(obj):
	"""Method names of an object (definition order is not guaranteed, like PHP)."""
	return [n for n in dir(obj) if not n.startswith("__") and callable(getattr(obj, n, None))]


def call_user_func_array(func, args):
	"""call_user_func_array(array($obj, "name"), $args) / ("name", $args)"""
	if isinstance(func, (list, tuple)):
		obj, name = func
		return getattr(obj, name)(*args)
	return func(*args)
