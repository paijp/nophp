<?php
#
#	nophp	https://github.com/paijp/nophp
#	
#	Copyright (c) 2021-2023 paijp
#
#	This software is released under the MIT License.
#	http://opensource.org/licenses/mit-license.php
#


#* bq

if (!function_exists("bq_dot")) {
## "." on the stack.
## For example, `index__:dot__html__:cat:cat` becomes "index.html".
## Normally, this is not necessary.
	function	bq_dot()
	{
		return array(".");
	}
}


if (!function_exists("bq_col")) {
## Stack the ":" on the stack.
## For example, `12__:col__00__:cat:cat` would be "12:00".
## Use when you want to enter ":" as a character, since a description prefixed with ":" is considered a command.
	function	bq_col()
	{
		return array(":");
	}
}


if (!function_exists("bq_sp")) {
## " " on the stack.
## For example, `abc__:sp__def__:cat:cat` becomes "abc def".
## Use when spaces are not allowed, such as during the name of the input tag.
	function	bq_sp()
	{
		return array(" ");
	}
}


if (!function_exists("bq_bq")) {
## "`" on the stack.
## For example, `:bq` becomes "`".
	function	bq_bq()
	{
		return array("`");
	}
}


if (!function_exists("bq_null")) {
## Empty string (zero-length string) on stack.
	function	bq_null()
	{
		return array("");
	}
}


if (!function_exists("bq_hex__hex")) {
## Take one string from the stack, consider it as a sequence of hexadecimal numbers, convert it to a string, and stack it on the stack.
## For example, `414243__:hex` would be "ABC".
## Use to enter special characters that cannot be entered using the above methods.
	function	bq_hex__hex($hex)
	{
		$s = "";
		for ($i=0; $i<strlen($hex); $i+=2)
			$s .= chr(filter_var("0x".substr($hex, $i, 2), FILTER_VALIDATE_INT, FILTER_FLAG_ALLOW_HEX));
		return array($s);
	}
}


if (!function_exists("bq_ispost")) {
## Stack 1 if the current request is a POST, or empty string if it is not a POST.
	function	bq_ispost()
	{
		return array((ispost())? "1" : "");
	}
}


if (!function_exists("bq_int__val")) {
## Take one string from the stack, round it off as a number, and stack it on the stack.
## For example, `3.8__:int` is "4".
	function	bq_int__val($val)
	{
		return array(round((float)$val));
	}
}


if (!function_exists("bq_isnull__val")) {
## Take one string from the stack and stack "1" if it is an empty string, otherwise empty string on the stack.
## `id__:g:isnull:andbreak` will break if there is no specification such as "?id=1".
## It can also be used for logic inversion, in the form `:isvalid:isnull`.
	function	bq_isnull__val($val)
	{
		return array(($val == "")? "1" : "");
	}
}


if (!function_exists("bq_h2z__hankaku")) {
## Take a string from the stack, convert half-width alphanumeric characters to full-width alphanumeric characters, and stack them on the stack.
	function	bq_h2z__hankaku($hankaku)
	{
		return array(mb_convert_kana($hankaku, "ASKV", "UTF-8"));
	}
}


if (!function_exists("bq_z2h__zenkaku")) {
## Take a string from the stack, convert full-width alphanumeric characters to half-width alphanumeric characters, and stack them on the stack.
	function	bq_z2h__zenkaku($zenkaku)
	{
		return array(mb_convert_kana($zenkaku, "as", "UTF-8"));
	}
}


if (!function_exists("bq_sys__name")) {
## Take a string from the stack, consider it a system variable name, and stack the variable value on the stack.
## For example, if there is a statement in env.php that $sys->v_limit = 100;, then `limit__:sys` will be "100".
	function	bq_sys__name($name)
	{
		global	$sys;
		
		$s ="v_{$name}";
		return array(@$sys->$s."");
	}
}


if (!function_exists("bq_now")) {
## Place the current time value (in seconds since January 1, 1970 0:00:00 GMT) on the stack.
## Use :todate to convert to a generic date and time.
## For example, `:now__y/m/d H:i:s__:todate` would be "19/02/10 19:52:13", etc.
	function	bq_now()
	{
		global	$sys;
		
		return array($sys->now);
	}
}


if (!function_exists("bq_ymd2t__year__month__day")) {
## Take three strings from the stack and stack the current time value (seconds since 0:00:00 GMT on Jan 1, 1970), considering them as year, month, and day, respectively.
## Use :todate to convert to a generic date and time.
	function	bq_ymd2t__year__month__day($year, $month, $day)
	{
		return array(mktime(0, 0, 0, $month, $day, $year));
	}
}


if (!function_exists("bq_age2t__year")) {
## Take a string from the stack, consider it as a number of years, and add it to the stack as the current time value (seconds since 0:00:00 GMT, Jan 1, 1970) minus the number of years.
## Use :todate to convert to a generic date and time.
	function	bq_age2t__year($year)
	{
		global	$sys;
		
		return array(mktime(date("H", $sys->now), date("i", $sys->now), date("s", $sys->now), date("n", $sys->now), date("j", $sys->now), date("Y", $sys->now) - $year));
	}
}


if (!function_exists("bq_todate__time__dateformat")) {
## Take a time value (seconds since January 1, 1970 0:00:00 GMT) and a format string from the stack, and put the string with the time in the format on the stack.
## The format string can be from the php date() function.
## Use :now to get the current time value.
## For example, `:now__y/m/d H:i:s__:todate` would be "19/02/10 19:52:13", etc.
	function	bq_todate__time__dateformat($time, $dateformat)
	{
		return array(date($dateformat, (int)$time));
	}
}


if (!function_exists("bq_cat__s__t")) {
## Take two strings from the stack, concatenate them, and stack them on the stack.
## For example, `a__b__:cat` would be "ab".
	function	bq_cat__s__t($s, $t)
	{
		return array($s.$t);
	}
}


if (!function_exists("bq_rcat__s__t")) {
## Take two strings from the stack, combine them in reverse order, and stack them on the stack.
## For example, `a__b__:rcat` would be "ba".
	function	bq_rcat__s__t($s, $t)
	{
		return array($t.$s);
	}
}


if (!function_exists("bq_scat__s__t")) {
## Take two strings from the stack, concatenate them, and stack them on the stack.
## For example, `a__b__:scat` becomes "a b".
	function	bq_scat__s__t($s, $t)
	{
		return array("{$s} {$t}");
	}
}


if (!function_exists("bq_rscat__s__t")) {
## Take two strings from the stack, combine them in reverse order, and stack them on the stack.
## For example, `a__b__:rscat` becomes "b a".
	function	bq_rscat__s__t($s, $t)
	{
		return array("{$t} {$s}");
	}
}


if (!function_exists("bq_ismatch__s__t")) {
## Take two strings from the stack and stack "1" if the second string is in the first string, otherwise empty string on the stack.
## For example, `abc__b__:match` is "1" because there is a "b" in "abc".
## Conversely, `abc__cb__:match` is an empty string because there is no "cb" in "abc".
	function	bq_ismatch__s__t($s, $t)
	{
		return array((strpos($s, $t) !== FALSE)? 1 : "");
	}
}


if (!function_exists("bq_addzero__num__digits")) {
## Take two strings from the stack and stack them on the stack with the first string prefixed with "0" until the length specified by the second character count is reached.
## For example, `123__6__:addzero` would be "000123".
## Also, `123__2__:addzero`.
## Note that `-123__6__:addzero` will be "00-123" since it is not treated as a number.
	function	bq_addzero__num__digits($num, $digits)
	{
		if (($i = strlen($num)) < (int)$digits)
			$num = str_repeat("0", $digits - $i).$num;
		return array($num);
	}
}


if (!function_exists("bq_add__i__j")) {
## Take two strings from the stack, consider each to be a number, and add them to the stack.
## For example, `123__456__:add` would be "579".
	function	bq_add__i__j($i, $j)
	{
		return array($i + $j);
	}
}


if (!function_exists("bq_sub__i__j")) {
## Take two strings from the stack and subtract each as a number and stack them on the stack.
## For example, `123__456__:sub` would be "-333".
	function	bq_sub__i__j($i, $j)
	{
		return array($i - $j);
	}
}


if (!function_exists("bq_rsub__i__j")) {
## Take two strings from the stack, consider each as a number and subtract them in reverse order, and stack them on the stack.
## For example, `123__456__:rsub` would be "333".
	function	bq_rsub__i__j($i, $j)
	{
		return array($j - $i);
	}
}


if (!function_exists("bq_mul__i__j")) {
## Take two strings from the stack, consider each to be a number, and stack them on the stack, adding them up.
## For example, `123__456__:mul` would be "56088".
	function	bq_mul__i__j($i, $j)
	{
		return array($i * $j);
	}
}


if (!function_exists("bq_div__i__j")) {
## Take two strings from the stack, divide each of them as a number, and stack them on the stack rounded down.
## For example, `123__456__:div` would be "0".
## If the divisor is 0, the divisor is 0.
	function	bq_div__i__j($i, $j)
	{
		if ((int)$j == 0)
			return array(0);
		return array(floor($i / $j));
	}
}


if (!function_exists("bq_rdiv__i__j")) {
## Take two strings from the stack, consider each as a number, divide them in reverse order, and round down to the nearest whole number, and stack them on the stack.
## For example, `123__456__:rdiv` would be "3".
## If the divisor is 0, the divisor is 0.
	function	bq_rdiv__i__j($i, $j)
	{
		if ((int)$i == 0)
			return array(0);
		return array(floor($j / $i));
	}
}


if (!function_exists("bq_mod__i__j")) {
## Take two strings from the stack, consider each to be a number, and stack the remainder of the division on the stack.
## For example, `123__456__:mod` would be "123".
## If the divisor is 0, the divisor is 0.
	function	bq_mod__i__j($i, $j)
	{
		if ((int)$j == 0)
			return array(0);
		return array($i % $j);
	}
}


if (!function_exists("bq_rmod__i__j")) {
## Take two strings from the stack, consider each to be a number, divide them in reverse order, and stack the remainder on the stack.
## For example, `123__456__:rmod` would be "87".
## If the divisor is 0, the divisor is 0.
	function	bq_rmod__i__j($i, $j)
	{
		if ((int)$i == 0)
			return array(0);
		return array($j % $i);
	}
}


if (!function_exists("bq_eq__i__j")) {
	function	bq_eq__i__j($i, $j)
	{
		return array(($i == $j)? 1 : "");
	}
}


if (!function_exists("bq_ieq__i__j")) {
## Take two strings from the stack and regard each as a number, stacking them on the stack with a "1" if they are equal or an empty string if they are not.
## For example, `1__1__:ieq` or `1__01__:ieq` or `0__ __:ieq` or `0x1__1__:ieq` is "1".
	function	bq_ieq__i__j($i, $j)
	{
		return array(((int)$i == (int)$j)? 1 : "");
	}
}


if (!function_exists("bq_seq__s__t")) {
## Take two strings from the stack, consider each as a string, and pile "1" on the stack if they are equal, or an empty string if they are not equal.
## For example, `1__1__:seq` would be "1".
## The `1__01__:seq` or `0__ __:seq` will be an empty string.
	function	bq_seq__s__t($s, $t)
	{
		return array(($s."" === $t."")? 1 : "");
	}
}


if (!function_exists("bq_ne__s__t")) {
	function	bq_ne__s__t($s, $t)
	{
		return array(($s != $t)? 1 : "");
	}
}


if (!function_exists("bq_ine__i__j")) {
## Take two strings from the stack and regard each as a number, stacking them on the stack with a "1" if they are not equal, or an empty string if they are equal.
## For example, `1__1__:ieq` or `1__01__:ieq` or `0__ __:ieq` or `0x1__1__:ieq` is an empty string.
	function	bq_ine__i__j($i, $j)
	{
		return array(((int)$i != (int)$j)? 1 : "");
	}
}


if (!function_exists("bq_sne__s__t")) {
## Take two strings from the stack and consider each to be a string, stacking them on the stack with a "1" if they are not equal and an empty string if they are.
## For example, `1__1__:seq` will be an empty string.
## 1__01__:seq` or `0__ __:seq` will be "1".
	function	bq_sne__s__t($s, $t)
	{
		return array(($s."" !== $t."")? 1 : "");
	}
}


if (!function_exists("bq_lt__i__j")) {
	function	bq_lt__i__j($i, $j)
	{
		return array(($i < $j)? 1 : "");
	}
}


if (!function_exists("bq_ilt__i__j")) {
## Take two strings from the stack, consider each as a number, and pile "1" on the stack if the second one is larger, otherwise empty string.
## For example, `1__2__:ilt` would be "1".
## `1__1__:ilt` or `1__0__:ilt` will be an empty string.
	function	bq_ilt__i__j($i, $j)
	{
		return array(((int)$i < (int)$j)? 1 : "");
	}
}


if (!function_exists("bq_slt__s__t")) {
## Take two strings from the stack, consider each as a string, and pile "1" on the stack if the second one is larger, otherwise empty string.
## For example, `1__2__:slt` would be "1".
## `1__1__:slt` and `1__0__:slt` will be empty strings.
	function	bq_slt__s__t($s, $t)
	{
		return array(("_".$s < "_".$t)? 1 : "");
	}
}


if (!function_exists("bq_gt__i__j")) {
	function	bq_gt__i__j($i, $j)
	{
		return array(($i > $j)? 1 : "");
	}
}


if (!function_exists("bq_igt__i__j")) {
## Take two strings from the stack, consider each as a number, and pile "1" on the stack if the first is larger, otherwise empty string.
## For example, `1__0__:igt` would be "1".
## `1__1__:igt` and `1__2__:igt` will be empty strings.
	function	bq_igt__i__j($i, $j)
	{
		return array(((int)$i > (int)$j)? 1 : "");
	}
}


if (!function_exists("bq_sgt__s__t")) {
## Take two strings from the stack and consider each to be a string, stacking "1" on the stack if the first is larger, otherwise an empty string.
## For example, `1__0__:sgt` would be "1".
## `1__1__:sgt` and `1__2__:sgt` will be empty strings.
	function	bq_sgt__s__t($s, $t)
	{
		return array(("_".$s > "_".$t)? 1 : "");
	}
}


if (!function_exists("bq_le__i__j")) {
	function	bq_le__i__j($i, $j)
	{
		return array(($i <= $j)? 1 : "");
	}
}


if (!function_exists("bq_ile__i__j")) {
## Take two strings from the stack, consider each as a number, and stack "1" if they are equal or the second is greater, otherwise empty string on the stack.
## For example, `1__2__:ile` or `1__1__:ile` would be "1".
## `1__0__:ile` will be an empty string.
	function	bq_ile__i__j($i, $j)
	{
		return array(((int)$i <= (int)$j)? 1 : "");
	}
}


if (!function_exists("bq_sle__s__t")) {
## Take two strings from the stack, consider each as a string, and pile "1" on the stack if they are equal or the second is greater, otherwise empty string.
## For example, `1__2__:sle` or `1__1__:sle` would be "1".
## `1__0__:sle` will be an empty string.
	function	bq_sle__s__t($s, $t)
	{
		return array(("_".$s <= "_".$t)? 1 : "");
	}
}


if (!function_exists("bq_ge__i__j")) {
	function	bq_ge__i__j($i, $j)
	{
		return array(($i >= $j)? 1 : "");
	}
}


if (!function_exists("bq_ige__i__j")) {
## Take two strings from the stack, consider each as a number, and stack "1" if they are equal or the first is greater, otherwise empty string on the stack.
## For example, `1__0__:ige` or `1__1__:ige` is "1".
## `1__2__:ige` will be an empty string.
	function	bq_ige__i__j($i, $j)
	{
		return array(((int)$i >= (int)$j)? 1 : "");
	}
}


if (!function_exists("bq_sge__s__t")) {
## Take two strings from the stack, consider each as a string, and stack "1" if they are equal or the first is larger, otherwise empty string on the stack.
## For example, `1__0__:sge` or `1__1__:sge` would be "1".
## `1__2__:sge` will be an empty string.
	function	bq_sge__s__t($s, $t)
	{
		return array(("_".$s >= "_".$t)? 1 : "");
	}
}


if (!function_exists("bq_dup__val")) {
## Take one string from the stack and stack two identical ones on the stack.
## This is used, for example, in `id__:g:dup:isnull:andbreak__table__field__:tableid`, when you want to use "id__:g" once obtained for both ":isnull" and ":tableid".
	function	bq_dup__val($val)
	{
		return array($val, $val);
	}


}


if (!function_exists("bq_sor__s__t")) {
## Take two strings from the stack and stack the first string if the second string is empty, otherwise stack the second string on the stack.
## For example, `empty__name__:g:sor` is "empty" if `name__:g` is an empty string, otherwise `name__:g`.
	function	bq_sor__s__t($s, $t)
	{
		if ($t == "")
			return array($s);
		return array($t);
	}
}


if (!function_exists("bq_sand__s__t")) {
	function	bq_sand__s__t($s, $t)
	{
		if ($t != "")
			return array($s);
		return array($t);
	}
}


if (!function_exists("bq_ior__i__j")) {
## Take two strings from the stack, consider each as a number, and pile the first number on the stack if the second number is 0, otherwise the second number.
## For example, `2__1__:ior` becomes "1" and `2__0__:ior` becomes "2".
	function	bq_ior__i__j($i, $j)
	{
		if ((int)$j == 0)
			return array((int)$i);
		return array((int)$j);
	}
}


if (!function_exists("bq_iand__i__j")) {
	function	bq_iand__i__j($i, $j)
	{
		if ((int)$j != 0)
			return array((int)$i);
		return array((int)$j);
	}
}


if (!function_exists("bq_loginrecord__field")) {
## Take one string from the stack, consider it a field name, and stack the field values of the record in the login table corresponding to the logged-in user for this session.
## Empty string if there is no login for this session.
## For example, `login__:loginrecord` will be the login name for this session.
	function	bq_loginrecord__field($field)
	{
		global	$loginrecord;
		
		if ($loginrecord !== null)
			return array($loginrecord->getfield($field)."");
		return array("");
	}
}


if (!function_exists("bq2_curid")) {
## Stack the current record ID on the stack.
## For example, inside "<!--{tableid user 1-->" inside, "1" is stacked on the stack.
	function	bq2_curid($rh0, $record)
	{
		return array((int)@$rh0->record->id);
	}
}


if (!function_exists("bq2_curtable")) {
## Stack the current table name on the stack.
## For example, inside "<!--{tableid user 1-->" inside, "user" is stacked on the stack.
	function	bq2_curtable($rh0, $record)
	{
		return array(@$rh0->record->tablename."");
	}
}


if (!function_exists("bq_curpage")) {
## Stack the current page name obtained from the URL.
## For example, when "g0000.html" is accessed, "g0000" is stacked on the stack.
	function	bq_curpage()
	{
		global	$sys;
		
		return array($sys->target);
	}
}


if (!function_exists("bq_isvalid")) {
## Validation stacks "1" if there are no errors, or an empty string if there are errors.
	function	bq_isvalid()
	{
		global	$invalid;
		
		return array(($invalid)? "" : "1");
	}
}


if (!function_exists("bq_g__name")) {
## Take one string from the stack, consider it a GET name, and stack the resulting GET value on the stack.
## For example, if the URL is "?id=1", then `id__:g` is "1".
## If "?id" is not specified, `id__:g` will be an empty string.
## GET can only yield an empty string or a sequence of numbers and commas for security reasons.
	function	bq_g__name($name)
	{
		$s = @$_GET[$name]."";
		$s = preg_replace("/[^,0-9]/", "", $s);
		return array($s);
	}
}


if (!function_exists("bq_p__name")) {
## Take one string from the stack and consider it as a POST name, and pile the resulting POST value on the stack.
## For example, the value submitted with <form method="post"><input name="s1"><input type="submit"> can be obtained with `s1__:p`.
## If it is not a POST or the POST name does not exist, it will be an empty string.
## With POST, you can get an arbitrary string (unlike GET, which has a submitkey).
## However, `` in SQL can only output numbers and (added 190429) ",".
# See also: parsewithbqinsql()
	function	bq_p__name($name)
	{
		$s = "";
		if ((ispost())) {
			$postkey = $this->prefix.str_replace(array(" ", "."), "_", $name);
			$s = @$_POST[$postkey];
		}
		return array($s);
	}
}


if (!function_exists("bq2_r__field")) {
## Take one string from the stack and consider it a field name, then retrieve the field from the current record and stack it on the stack.
## For example, "<!--{tableid user 1-->" inside, `id__:r` will yield the value of the "id" field of record 1 in the user table.
## If no record is defined or the specified field name does not exist, it will be an empty string.
	function	bq2_r__field($rh0, $record, $field)
	{
		return array($record->getfield($field)."");
	}
}


if (!function_exists("bq2_set__val__field")) {
## Take two strings from the stack, consider them as field values and field names, respectively, and set them to the current record.
## For example, `1__id__:set` sets the ID of the current record to 1.
	function	bq2_set__val__field($rh0, $record, $val, $field)
	{
		$record->setfield($field, $val);
		return array();
	}
}


if (!function_exists("bq2_sqlisnull__field")) {
## Take one string from the stack, consider it a field name, and add "and field name is null" to the corresponding SQL statement.
## This is a tablegrid that is a tablegrid that contains both the "<!--{tablegrid" parameter section and "<!--}--" up to and including "<!--{selectrows" parameter section.
	function	bq2_sqlisnull__field($rh0, $record, $field)
	{
		$rh0->whereargs["{$field} is null"] = array();
		return array();
	}
}


if (!function_exists("bq2_sqlisnotnull__field")) {
## Take one string from the stack, consider it a field name, and add "and field name is not null" to the corresponding SQL statement.
## This is a tablegrid that is a tablegrid that contains both the "<!--{tablegrid" parameter section and "<!--}--" up to and including "<!--{selectrows" parameter section.
	function	bq2_sqlisnotnull__field($rh0, $record, $field)
	{
		$rh0->whereargs["{$field} is not null"] = array();
		return array();
	}
}


if (!function_exists("bq2_sqlisempty__field")) {
## Take one string from the stack, consider it a field name, and add "and (field name is null or field name = "")" to the corresponding SQL statement.
## This is a tablegrid that is a tablegrid that contains both the "<!--{tablegrid" parameter section and "<!--}--" up to and including "<!--{selectrows" parameter section.
	function	bq2_sqlisempty__field($rh0, $record, $field)
	{
		$rh0->whereargs["({$field} is null or {$field} = ?)"] = array("");
		return array();
	}
}


if (!function_exists("bq2_sqlisnotempty__field")) {
## Take one string from the stack, consider it a field name, and add "and field name is not null and field name <> "" to the corresponding SQL statement.
## This is a tablegrid that is a tablegrid that contains both the "<!--{tablegrid" parameter section and "<!--}--" up to and including "<!--{selectrows" parameter section.
	function	bq2_sqlisnotempty__field($rh0, $record, $field)
	{
		$rh0->whereargs["{$field} is not null"] = array();
		$rh0->whereargs["{$field} <> ?"] = array("");
		return array();
	}
}


if (!function_exists("bq2_sqllike__val__field")) {
## Take two strings from the stack, consider each to be a search string and a field name, and add "and field name like "%search string%"" to the corresponding SQL statement.
## This is a tablegrid that is a tablegrid that contains both the "<!--{tablegrid" parameter section and "<!--}--" up to and including "<!--{selectrows" parameter section.
	function	bq2_sqllike__val__field($rh0, $record, $val, $field)
	{
		$rh0->whereargs["{$field} like ?"] = array("%{$val}%");
		return array();
	}
}


if (!function_exists("bq2_sqllike2__val__field1__field2")) {
## Take three strings from the stack, consider each as a search string, field name 1, and field name 2, and add "and (field name 1 like "%search string%" or field name 2 like "%search string%")" to the corresponding SQL statement.
## This is a tablegrid that is a tablegrid that contains both the "<!--{tablegrid" parameter section and "<!--}--" up to and including "<!--{selectrows" parameter section.
	function	bq2_sqllike2__val__field1__field2($rh0, $record, $val, $field1, $field2)
	{
		$rh0->whereargs["({$field1} like ? or {$field2} like ?)"] = array("%{$val}%", "%{$val}%");
		return array();
	}
}


if (!function_exists("bq2_sqllike3__val__field1__field2__field3")) {
## Take four strings from the stack, consider each as a search string, field name 1, field name 2, and field name 3, and add "and (field name 1 like "%Search String%" or field name 2 like "%Search String%" or field name 3 like "% Search String%")" is added.
## This is a tablegrid that is a tablegrid that contains both the "<!--{tablegrid" parameter section and "<!--}--" up to and including "<!--{selectrows" parameter section.
	function	bq2_sqllike3__val__field1__field2__field3($rh0, $record, $val, $field1, $field2, $field3)
	{
		$rh0->whereargs["({$field1} like ? or {$field2} like ? or {$field3} like ?)"] = array("%{$val}%", "%{$val}%", "%{$val}%");
		return array();
	}
}


if (!function_exists("bq2_sqllike4__val__field1__field2__field3_field4")) {
## Take 5 strings from the stack and consider each as a search string, field name 1, field name 2, field name 3, field name 4, and add "and (field name 1 like "%search string%" or field name 2 like "%search string%" or field name 3 like "%search string%" or field name 4 like "%search string")".
## This is a tablegrid that is a tablegrid that contains both the "<!--{tablegrid" parameter section and "<!--}--" up to and including "<!--{selectrows" parameter section.
	function	bq2_sqllike4__val__field1__field2__field3__field4($rh0, $record, $val, $field1, $field2, $field3, $field4)
	{
		$rh0->whereargs["({$field1} like ? or {$field2} like ? or {$field3} like ? or {$field4} like ?)"] = array("%{$val}%", "%{$val}%", "%{$val}%", "%{$val}%");
		return array();
	}
}


if (!function_exists("bq2_sqlnotlike__val__field")) {
## Take two strings from the stack, consider them as a search string and a field name, respectively, and add "and field name not like "%search string%"" to the corresponding SQL statement.
## This is a tablegrid that is a tablegrid that contains both the "<!--{tablegrid" parameter section and "<!--}--" up to and including "<!--{selectrows" parameter section.
	function	bq2_sqlnotlike__val__field($rh0, $record, $val, $field)
	{
		$rh0->whereargs["{$field} not like ?"] = array("%{$val}%");
		return array();
	}
}


if (!function_exists("bq2_sqleq__val__field")) {
## Take two strings from the stack, consider each to be a string and a field name, and add "and "string" = field name" to the corresponding SQL statement.
## This is a tablegrid that is a tablegrid that contains both the "<!--{tablegrid" parameter section and "<!--}--" up to and including "<!--{selectrows" parameter section.
	function	bq2_sqleq__val__field($rh0, $record, $val, $field)
	{
		$rh0->whereargs["? = {$field}"] = array($val);
		return array();
	}
}


if (!function_exists("bq2_sqlne__val__field")) {
## Take two strings from the stack, consider each to be a string and a field name, and add "and "string" <> field name" to the corresponding SQL statement.
## This is a tablegrid that is a tablegrid that contains both the "<!--{tablegrid" parameter section and "<!--}--" up to and including "<!--{selectrows" parameter section.
	function	bq2_sqlne__val__field($rh0, $record, $val, $field)
	{
		$rh0->whereargs["? <> {$field}"] = array($val);
		return array();
	}
}


if (!function_exists("bq2_sqllt__val__field")) {
## Take two strings from the stack, consider each to be a string and a field name, and add "and "string" < field name" to the corresponding SQL statement.
## This is a tablegrid that is a tablegrid that contains both the "<!--{tablegrid" parameter section and "<!--}--" up to and including "<!--{selectrows" parameter section.
	function	bq2_sqllt__val__field($rh0, $record, $val, $field)
	{
		$rh0->whereargs["? < {$field}"] = array($val);
		return array();
	}
}


if (!function_exists("bq2_sqlle__val__field")) {
## Take two strings from the stack, consider each to be a string and a field name, and add "and "string" <= field name" to the corresponding SQL statement.
## This is a tablegrid that is a tablegrid that contains both the "<!--{tablegrid" parameter section and "<!--}--" up to and including "<!--{selectrows" parameter section.
	function	bq2_sqlle__val__field($rh0, $record, $val, $field)
	{
		$rh0->whereargs["? <= {$field}"] = array($val);
		return array();
	}
}


if (!function_exists("bq2_sqlgt__val__field")) {
## Take two strings from the stack, consider each to be a string and a field name, and add "and "string" > field name" to the corresponding SQL statement.
## This is a tablegrid that is a tablegrid that contains both the "<!--{tablegrid" parameter section and "<!--}--" up to and including "<!--{selectrows" parameter section.
	function	bq2_sqlgt__val__field($rh0, $record, $val, $field)
	{
		$rh0->whereargs["? > {$field}"] = array($val);
		return array();
	}
}


if (!function_exists("bq2_sqlge__val__field")) {
## Take two strings from the stack, consider each to be a string and a field name, and add "and "string" >= field name" to the corresponding SQL statement.
## This is a tablegrid that is a tablegrid that contains both the "<!--{tablegrid" parameter section and "<!--}--" up to and including "<!--{selectrows" parameter section.
	function	bq2_sqlge__val__field($rh0, $record, $val, $field)
	{
		$rh0->whereargs["? >= {$field}"] = array($val);
		return array();
	}
}


