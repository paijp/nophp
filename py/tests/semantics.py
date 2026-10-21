#
#	Compare phpcompat (and friends) with real PHP.
#
#	python3 semantics.py [php]
#

import itertools
import json
import os
import subprocess
import sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), ".."))

import engine		# noqa: E402,F401
from phpcompat import (		# noqa: E402
	to_int, to_float, to_str, loose_eq, loose_cmp, php_round, htmlspecialchars, nl2br, rawurlencode, ENT_QUOTES,
)
from importer import php_sprintf		# noqa: E402
from kana import mb_convert_kana		# noqa: E402
import phpdate		# noqa: E402

STRS = ["", "0", "1", "01", "1.0", "1e3", " 7", "7 ", "12abc", "abc", "0x1A", "-0", "-5", "+3", ".5", "5.",
	"1e", "9223372036854775807", "9223372036854775808", "3.8", "2.5", "-2.5", "NAN", "null", " ", "\n1"]
NUMS = [0.5, 1.5, 2.5, -2.5, 1.005, 0.285, 1.955, 5.055, 1234.5678, -0.4, 1e15, 0.1 + 0.2, 1e20, 123456789012345.678, 3.0]
FMTS = [("%d", "12abc"), ("%05d", "-42"), ("%.2f", "1.005"), ("%.1f", "2.25"), ("%5.1f", "0.05"), ("%e", "12345"),
	("%x", "255"), ("%'*8s", "ab"), ("%-6s|", "ab"), ("%u", "-1"), ("%b", "10"), ("%010.3f", "-3.14159"), ("%s%%", "a")]
TEXT = ["<a href=\"x\">'q'&amp;</a>", "a b/あ?=&+~._-", "l1\nl2\r\nl3\rl4\n\r", "ﾊﾟﾝ ｶﾞｷﾞ ABC abc 123 !\"#'\\~", "ＡＢＣ　１２３！＂＃＇＼～ﾞ"]
TIMES = [0, 1700000000, 951782400, 1709251199, 2000000000]
DATEFMT = "Y y m n d j H G h g i s a A D l N w t L W U \\Y"


def cases():
	for s in STRS:
		yield ("int", s), to_int(s)
		yield ("float", s), to_str(to_float(s))
	for a, b in itertools.product(STRS[:16], repeat=2):
		yield ("eq", a, b), loose_eq(a, b)
		yield ("cmp", a, b), loose_cmp(a, b)
	for v in NUMS:
		yield ("str", v), to_str(v)
		for p in (0, 1, 2, -1):
			yield ("round", v, p), to_str(php_round(v, p))
	for f, a in FMTS:
		yield ("sprintf", f, a), php_sprintf(f, a)
	for t in TEXT:
		yield ("hsc", t), htmlspecialchars(t)
		yield ("hscq", t), htmlspecialchars(t, ENT_QUOTES)
		yield ("nl2br", t), nl2br(t)
		yield ("url", t), rawurlencode(t)
		yield ("h2z", t), mb_convert_kana(t, "ASKV")
		yield ("z2h", t), mb_convert_kana(t, "as")
	for t in TIMES:
		yield ("date", t), phpdate.date(DATEFMT, t)
	for y, m, d in ((2024, 2, 30), (2024, 13, 1), (2024, 0, 0), (1999, 12, 31)):
		yield ("mktime", y, m, d), phpdate.mktime(0, 0, 0, m, d, y)


PHP = r'''<?php
date_default_timezone_set("Asia/Tokyo");
$out = array();
foreach (json_decode(file_get_contents("php://stdin"), true) as $c) {
	switch ($c[0]) {
	case "int": $r = (int)$c[1]; break;
	case "float": $r = (float)$c[1].""; break;
	case "eq": $r = ($c[1] == $c[2]); break;
	case "cmp": $r = ($c[1] <=> $c[2]); break;
	case "str": $r = $c[1].""; break;
	case "round": $r = round($c[1], $c[2]).""; break;
	case "sprintf": $r = sprintf($c[1], $c[2]); break;
	case "hsc": $r = htmlspecialchars($c[1]); break;
	case "hscq": $r = htmlspecialchars($c[1], ENT_QUOTES); break;
	case "nl2br": $r = nl2br($c[1]); break;
	case "url": $r = rawurlencode($c[1]); break;
	case "h2z": $r = mb_convert_kana($c[1], "ASKV", "UTF-8"); break;
	case "z2h": $r = mb_convert_kana($c[1], "as", "UTF-8"); break;
	case "date": $r = date("''' + DATEFMT.replace("\\", "\\\\") + r'''", $c[1]); break;
	case "mktime": $r = mktime(0, 0, 0, $c[2], $c[3], $c[1]); break;
	}
	$out[] = $r;
}
echo json_encode($out);
'''


def main():
	php = sys.argv[1] if len(sys.argv) > 1 else "php"
	lst = list(cases())
	inputs = [list(c) for c, _ in lst]
	p = subprocess.run([php, "-d", "error_reporting=0", "-r", "?>" + PHP], input=json.dumps(inputs).encode(), capture_output=True)
	res = json.loads(p.stdout or b"null")
	if res is None:
		print(p.stderr.decode())
		sys.exit(2)
	ng = 0
	for (c, mine), theirs in zip(lst, res):
		if mine != theirs:
			ng += 1
			print("NG", c, "php:", repr(theirs), "py:", repr(mine))
	print("%d cases, %d differences" % (len(lst), ng))
	sys.exit(1 if ng else 0)


if __name__ == "__main__":
	main()
