#
#	date() / mktime() subset (timezone Asia/Tokyo, as env.php).
#

import calendar
import datetime
import zoneinfo

TZ = zoneinfo.ZoneInfo("Asia/Tokyo")

_D = ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"]
_L = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"]
_M = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"]
_F = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"]


def date(fmt, t):
	d = datetime.datetime.fromtimestamp(int(t), TZ)
	out = []
	esc = False
	for c in fmt:
		if esc:
			out.append(c)
			esc = False
			continue
		if c == "\\":
			esc = True
			continue
		out.append({
			"d": "%02d" % d.day, "j": str(d.day),
			"D": _D[d.weekday()], "l": _L[d.weekday()],
			"N": str(d.isoweekday()), "w": str(d.isoweekday() % 7),
			"m": "%02d" % d.month, "n": str(d.month),
			"M": _M[d.month - 1], "F": _F[d.month - 1],
			"t": str(calendar.monthrange(d.year, d.month)[1]),
			"L": "1" if calendar.isleap(d.year) else "0",
			"Y": str(d.year), "y": "%02d" % (d.year % 100),
			"a": "am" if d.hour < 12 else "pm", "A": "AM" if d.hour < 12 else "PM",
			"G": str(d.hour), "H": "%02d" % d.hour,
			"g": str((d.hour + 11) % 12 + 1), "h": "%02d" % ((d.hour + 11) % 12 + 1),
			"i": "%02d" % d.minute, "s": "%02d" % d.second,
			"U": str(int(t)),
			"W": "%02d" % d.isocalendar()[1],
		}.get(c, c))
	return "".join(out)


def mktime(hour, minute, second, month, day, year):
	"""Out-of-range values overflow into the next unit, like PHP."""
	y = year + (month - 1) // 12
	m = (month - 1) % 12 + 1
	base = datetime.datetime(y, m, 1, tzinfo=TZ)
	delta = datetime.timedelta(days=day - 1, hours=hour, minutes=minute, seconds=second)
	return int((base + delta).timestamp())
