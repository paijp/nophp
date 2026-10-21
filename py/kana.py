#
#	mb_convert_kana() subset used by nophp ("ASKV" and "as").
#

_HKANA = "｡｢｣､･ｦｧｨｩｪｫｬｭｮｯｰｱｲｳｴｵｶｷｸｹｺｻｼｽｾｿﾀﾁﾂﾃﾄﾅﾆﾇﾈﾉﾊﾋﾌﾍﾎﾏﾐﾑﾒﾓﾔﾕﾖﾗﾘﾙﾚﾛﾜﾝﾞﾟ"
_ZKANA = "。「」、・ヲァィゥェォャュョッーアイウエオカキクケコサシスセソタチツテトナニヌネノハヒフヘホマミムメモヤユヨラリルレロワン゛゜"
_H2Z = dict(zip(_HKANA, _ZKANA))
_DAKU = {"ｳ": "ヴ"}
for _h, _z in zip("ｶｷｸｹｺｻｼｽｾｿﾀﾁﾂﾃﾄﾊﾋﾌﾍﾎ", "ガギグゲゴザジズゼゾダヂヅデドバビブベボ"):
	_DAKU[_h] = _z
_HANDAKU = dict(zip("ﾊﾋﾌﾍﾎ", "パピプペポ"))
_EXCLUDE = set('"\'\\~')


def _a2z(c):
	o = ord(c)
	if 0x21 <= o <= 0x7e and c not in _EXCLUDE:
		return chr(o + 0xfee0)
	return c


def _z2a(c):
	o = ord(c)
	if 0xff01 <= o <= 0xff5e and chr(o - 0xfee0) not in _EXCLUDE:
		return chr(o - 0xfee0)
	return c


def mb_convert_kana(s, mode):
	out = []
	i = 0
	n = len(s)
	while i < n:
		c = s[i]
		nx = s[i + 1] if i + 1 < n else ""
		if "K" in mode and c in _H2Z:
			if "V" in mode and nx == "ﾞ" and c in _DAKU:
				out.append(_DAKU[c])
				i += 2
				continue
			if "V" in mode and nx == "ﾟ" and c in _HANDAKU:
				out.append(_HANDAKU[c])
				i += 2
				continue
			out.append(_H2Z[c])
		elif "A" in mode:
			out.append("　" if (c == " " and "S" in mode) else _a2z(c))
		elif "a" in mode:
			out.append(" " if (c == "　" and "s" in mode) else _z2a(c))
		elif "S" in mode and c == " ":
			out.append("　")
		elif "s" in mode and c == "　":
			out.append(" ")
		else:
			out.append(c)
		i += 1
	return "".join(out)
