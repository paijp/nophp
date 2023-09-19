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
## 「.」をスタックに積みます。
## 例えば`index__:dot__html__:cat:cat`は「index.html」になります。
## 通常は、これを使う必要はありません。
	function	bq_dot()
	{
		return array(".");
	}
}


if (!function_exists("bq_col")) {
## 「:」をスタックに積みます。
## 例えば`12__:col__00__:cat:cat`は「12:00」になります。
## 先頭が「:」の記述はコマンドとみなされるため、文字としての「:」を入力したい時に使用します。
	function	bq_col()
	{
		return array(":");
	}
}


if (!function_exists("bq_sp")) {
## 「 」をスタックに積みます。
## 例えば`abc__:sp__def__:cat:cat`は「abc def」になります。
## inputタグのname中など、スペースが使えない場合に使用します。
	function	bq_sp()
	{
		return array(" ");
	}
}


if (!function_exists("bq_bq")) {
## 「`」をスタックに積みます。
## 例えば`:bq`は「`」になります。
	function	bq_bq()
	{
		return array("`");
	}
}


if (!function_exists("bq_null")) {
## 空文字列(長さ0の文字列)をスタックに積みます。
	function	bq_null()
	{
		return array("");
	}
}


if (!function_exists("bq_hex__hex")) {
## スタックから文字列を1つ取り出して16進数の並びとみなし、文字列に変換してスタックに積みます。
## 例えば`414243__:hex`は「ABC」になります。
## 上記の方法で入力できない特殊文字を入力するのに使用します。
	function	bq_hex__hex($hex)
	{
		$s = "";
		for ($i=0; $i<strlen($hex); $i+=2)
			$s .= chr(filter_var("0x".substr($hex, $i, 2), FILTER_VALIDATE_INT, FILTER_FLAG_ALLOW_HEX));
		return array($s);
	}
}


if (!function_exists("bq_ispost")) {
## 現在のリクエストがPOSTであれば1を、POSTでなければ空文字列をスタックに積みます。
	function	bq_ispost()
	{
		return array((ispost())? "1" : "");
	}
}


if (!function_exists("bq_int__val")) {
## スタックから文字列を1つ取り出し、数値とみなして四捨五入し、スタックに積みます。
## 例えば`3.8__:int`は「4」になります。
	function	bq_int__val($val)
	{
		return array(round((float)$val));
	}
}


if (!function_exists("bq_isnull__val")) {
## スタックから文字列を1つ取り出し、空文字列であれば「1」を、そうでなければ空文字列をスタックに積みます。
## `id__:g:isnull:andbreak`とすると、「?id=1」のような指定がなければ、breakします。
## `:isvalid:isnull`のような形で、論理の反転に使用することもできます。
	function	bq_isnull__val($val)
	{
		return array(($val == "")? "1" : "");
	}
}


if (!function_exists("bq_h2z__hankaku")) {
## スタックから文字列を1つ取り出し、半角英数字を全角英数字に変換してスタックに積みます。
	function	bq_h2z__hankaku($hankaku)
	{
		return array(mb_convert_kana($hankaku, "ASKV", "UTF-8"));
	}
}


if (!function_exists("bq_z2h__zenkaku")) {
## スタックから文字列を1つ取り出し、全角英数字を半角英数字に変換してスタックに積みます。
	function	bq_z2h__zenkaku($zenkaku)
	{
		return array(mb_convert_kana($zenkaku, "as", "UTF-8"));
	}
}


if (!function_exists("bq_sys__name")) {
## スタックから文字列を1つ取り出し、システム変数名とみなして、その変数値をスタックに積みます。
## 例えばenv.php内で$sys->v_limit = 100;という記述があれば、`limit__:sys`は「100」になります。
	function	bq_sys__name($name)
	{
		global	$sys;
		
		$s ="v_{$name}";
		return array(@$sys->$s."");
	}
}


if (!function_exists("bq_now")) {
## 現在時刻値(1970年1月1日0:00:00GMTからの秒数)を、スタックに積みます。
## 一般的な日時に変換するには、:todateを使います。
## 例えば`:now__y/m/d H:i:s__:todate`は、「19/02/10 19:52:13」などになります。
	function	bq_now()
	{
		global	$sys;
		
		return array($sys->now);
	}
}


if (!function_exists("bq_ymd2t__year__month__day")) {
## スタックから文字列を3つ取り出し、それぞれ年・月・日とみなして、現在時刻値(1970年1月1日0:00:00GMTからの秒数)をスタックに積みます。
## 一般的な日時に変換するには、:todateを使います。
	function	bq_ymd2t__year__month__day($year, $month, $day)
	{
		return array(mktime(0, 0, 0, $month, $day, $year));
	}
}


if (!function_exists("bq_age2t__year")) {
## スタックから文字列を1つ取り出し、年数とみなして、現在時刻値(1970年1月1日0:00:00GMTからの秒数)から年数を引いた値を、スタックに積みます。
## 一般的な日時に変換するには、:todateを使います。
	function	bq_age2t__year($year)
	{
		global	$sys;
		
		return array(mktime(date("H", $sys->now), date("i", $sys->now), date("s", $sys->now), date("n", $sys->now), date("j", $sys->now), date("Y", $sys->now) - $year));
	}
}


if (!function_exists("bq_todate__time__dateformat")) {
## スタックから、時刻値(1970年1月1日0:00:00GMTからの秒数)と、書式文字列を取り出し、時刻を書式にあてはめた文字列をスタックに積みます。
## 書式文字列は、phpのdate()関数のものが使えます。
## 現在時刻値を得るには、:nowを使います。
## 例えば`:now__y/m/d H:i:s__:todate`は、「19/02/10 19:52:13」などになります。
	function	bq_todate__time__dateformat($time, $dateformat)
	{
		return array(date($dateformat, (int)$time));
	}
}


if (!function_exists("bq_cat__s__t")) {
## スタックから、文字列を2つ取り出して、結合し、それをスタックに積みます。
## 例えば`a__b__:cat`は「ab」になります。
	function	bq_cat__s__t($s, $t)
	{
		return array($s.$t);
	}
}


if (!function_exists("bq_rcat__s__t")) {
## スタックから、文字列を2つ取り出して、逆順に結合し、それをスタックに積みます。
## 例えば`a__b__:rcat`は「ba」になります。
	function	bq_rcat__s__t($s, $t)
	{
		return array($t.$s);
	}
}


if (!function_exists("bq_scat__s__t")) {
## スタックから、文字列を2つ取り出して、結合し、それをスタックに積みます。
## 例えば`a__b__:scat`は「a b」になります。
	function	bq_scat__s__t($s, $t)
	{
		return array("{$s} {$t}");
	}
}


if (!function_exists("bq_rscat__s__t")) {
## スタックから、文字列を2つ取り出して、逆順に結合し、それをスタックに積みます。
## 例えば`a__b__:rscat`は「b a」になります。
	function	bq_rscat__s__t($s, $t)
	{
		return array("{$t} {$s}");
	}
}


if (!function_exists("bq_ismatch__s__t")) {
## スタックから、文字列を2つ取り出し、1番目の文字列の中に2番目の文字列があれば「1」を、なければ空文字列をスタックに積みます。
## 例えば`abc__b__:match`は、「abc」の中に「b」があるので、「1」になります。
## 逆に、`abc__cb__:match`は、「abc」の中に「cb」がないので、空文字列になります。
	function	bq_ismatch__s__t($s, $t)
	{
		return array((strpos($s, $t) !== FALSE)? 1 : "");
	}
}


if (!function_exists("bq_addzero__num__digits")) {
## スタックから文字列を2つ取り出し、2番目の文字数で指定された長さになるまで、1番目の文字列の先頭に「0」を付加したものをスタックに積みます。
## 例えば`123__6__:addzero`は、「000123」になります。
## また、`123__2__:addzero`は、「123」になります。
## なお、数値として扱うわけではありませんので、`-123__6__:addzero`は「00-123」になります。
	function	bq_addzero__num__digits($num, $digits)
	{
		if (($i = strlen($num)) < (int)$digits)
			$num = str_repeat("0", $digits - $i).$num;
		return array($num);
	}
}


if (!function_exists("bq_add__i__j")) {
## スタックから文字列を2つ取り出し、それぞれを数値とみなして加算したものをスタックに積みます。
## たとえば`123__456__:add`は「579」になります。
	function	bq_add__i__j($i, $j)
	{
		return array($i + $j);
	}
}


if (!function_exists("bq_sub__i__j")) {
## スタックから文字列を2つ取り出し、それぞれを数値とみなして減算したものをスタックに積みます。
## たとえば`123__456__:sub`は「-333」になります。
	function	bq_sub__i__j($i, $j)
	{
		return array($i - $j);
	}
}


if (!function_exists("bq_rsub__i__j")) {
## スタックから文字列を2つ取り出し、それぞれを数値とみなして逆順に減算したものをスタックに積みます。
## たとえば`123__456__:rsub`は「333」になります。
	function	bq_rsub__i__j($i, $j)
	{
		return array($j - $i);
	}
}


if (!function_exists("bq_mul__i__j")) {
## スタックから文字列を2つ取り出し、それぞれを数値とみなして積算したものをスタックに積みます。
## たとえば`123__456__:mul`は「56088」になります。
	function	bq_mul__i__j($i, $j)
	{
		return array($i * $j);
	}
}


if (!function_exists("bq_div__i__j")) {
## スタックから文字列を2つ取り出し、それぞれを数値とみなして除算して切り捨てたものをスタックに積みます。
## たとえば`123__456__:div`は「0」になります。
## 除数が0の場合は、0になります。
	function	bq_div__i__j($i, $j)
	{
		if ((int)$j == 0)
			return array(0);
		return array(floor($i / $j));
	}
}


if (!function_exists("bq_rdiv__i__j")) {
## スタックから文字列を2つ取り出し、それぞれを数値とみなして逆順に除算して切り捨てたものをスタックに積みます。
## たとえば`123__456__:rdiv`は「3」になります。
## 除数が0の場合は、0になります。
	function	bq_rdiv__i__j($i, $j)
	{
		if ((int)$i == 0)
			return array(0);
		return array(floor($j / $i));
	}
}


if (!function_exists("bq_mod__i__j")) {
## スタックから文字列を2つ取り出し、それぞれを数値とみなして除算した剰余をスタックに積みます。
## たとえば`123__456__:mod`は「123」になります。
## 除数が0の場合は、0になります。
	function	bq_mod__i__j($i, $j)
	{
		if ((int)$j == 0)
			return array(0);
		return array($i % $j);
	}
}


if (!function_exists("bq_rmod__i__j")) {
## スタックから文字列を2つ取り出し、それぞれを数値とみなして逆順に除算した剰余をスタックに積みます。
## たとえば`123__456__:rmod`は「87」になります。
## 除数が0の場合は、0になります。
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
## スタックから文字列を2つ取り出し、それぞれを数値とみなして、等しければ「1」を、等しくなければ空文字列をスタックに積みます。
## 例えば`1__1__:ieq`や`1__01__:ieq`や`0__ __:ieq`や`0x1__1__:ieq`は「1」になります。
	function	bq_ieq__i__j($i, $j)
	{
		return array(((int)$i == (int)$j)? 1 : "");
	}
}


if (!function_exists("bq_seq__s__t")) {
## スタックから文字列を2つ取り出し、それぞれを文字列とみなして、等しければ「1」を、等しくなければ空文字列をスタックに積みます。
## 例えば`1__1__:seq`は「1」になります。
## `1__01__:seq`や`0__ __:seq`は空文字列になります。
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
## スタックから文字列を2つ取り出し、それぞれを数値とみなして、等しくなければ「1」を、等しければ空文字列をスタックに積みます。
## 例えば`1__1__:ieq`や`1__01__:ieq`や`0__ __:ieq`や`0x1__1__:ieq`は空文字列になります。
	function	bq_ine__i__j($i, $j)
	{
		return array(((int)$i != (int)$j)? 1 : "");
	}
}


if (!function_exists("bq_sne__s__t")) {
## スタックから文字列を2つ取り出し、それぞれを文字列とみなして、等しくなければ「1」を、等しければ空文字列をスタックに積みます。
## 例えば`1__1__:seq`は空文字列になります。
## `1__01__:seq`や`0__ __:seq`は「1」になります。
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
## スタックから文字列を2つ取り出し、それぞれを数値とみなして、2番目が大きければ「1」を、そうでなければ空文字列をスタックに積みます。
## 例えば`1__2__:ilt`は「1」になります。
## `1__1__:ilt`や`1__0__:ilt`は空文字列になります。
	function	bq_ilt__i__j($i, $j)
	{
		return array(((int)$i < (int)$j)? 1 : "");
	}
}


if (!function_exists("bq_slt__s__t")) {
## スタックから文字列を2つ取り出し、それぞれを文字列とみなして、2番目が大きければ「1」を、そうでなければ空文字列をスタックに積みます。
## 例えば`1__2__:slt`は「1」になります。
## `1__1__:slt`や`1__0__:slt`は空文字列になります。
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
## スタックから文字列を2つ取り出し、それぞれを数値とみなして、1番目が大きければ「1」を、そうでなければ空文字列をスタックに積みます。
## 例えば`1__0__:igt`は「1」になります。
## `1__1__:igt`や`1__2__:igt`は空文字列になります。
	function	bq_igt__i__j($i, $j)
	{
		return array(((int)$i > (int)$j)? 1 : "");
	}
}


if (!function_exists("bq_sgt__s__t")) {
## スタックから文字列を2つ取り出し、それぞれを文字列とみなして、1番目が大きければ「1」を、そうでなければ空文字列をスタックに積みます。
## 例えば`1__0__:sgt`は「1」になります。
## `1__1__:sgt`や`1__2__:sgt`は空文字列になります。
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
## スタックから文字列を2つ取り出し、それぞれを数値とみなして、等しいか2番目が大きければ「1」を、そうでなければ空文字列をスタックに積みます。
## 例えば`1__2__:ile`や`1__1__:ile`は「1」になります。
## `1__0__:ile`は空文字列になります。
	function	bq_ile__i__j($i, $j)
	{
		return array(((int)$i <= (int)$j)? 1 : "");
	}
}


if (!function_exists("bq_sle__s__t")) {
## スタックから文字列を2つ取り出し、それぞれを文字列とみなして、等しいか2番目が大きければ「1」を、そうでなければ空文字列をスタックに積みます。
## 例えば`1__2__:sle`や`1__1__:sle`は「1」になります。
## `1__0__:sle`は空文字列になります。
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
## スタックから文字列を2つ取り出し、それぞれを数値とみなして、等しいか1番目が大きければ「1」を、そうでなければ空文字列をスタックに積みます。
## 例えば`1__0__:ige`や`1__1__:ige`は「1」になります。
## `1__2__:ige`は空文字列になります。
	function	bq_ige__i__j($i, $j)
	{
		return array(((int)$i >= (int)$j)? 1 : "");
	}
}


if (!function_exists("bq_sge__s__t")) {
## スタックから文字列を2つ取り出し、それぞれを文字列とみなして、等しいか1番目が大きければ「1」を、そうでなければ空文字列をスタックに積みます。
## 例えば`1__0__:sge`や`1__1__:sge`は「1」になります。
## `1__2__:sge`は空文字列になります。
	function	bq_sge__s__t($s, $t)
	{
		return array(("_".$s >= "_".$t)? 1 : "");
	}
}


if (!function_exists("bq_dup__val")) {
## スタックから文字列を1つ取り出し、同じものを2つスタックに積みます。
## これは例えば、`id__:g:dup:isnull:andbreak__table__field__:tableid`のような使い方で、一度取得した「id__:g」を、「:isnull」と「:tableid」の両方で使いたい場合等に使用します。
	function	bq_dup__val($val)
	{
		return array($val, $val);
	}


}


if (!function_exists("bq_sor__s__t")) {
## スタックから文字列を2つ取り出し、2番目の文字列が空文字列なら1番目の文字列を、そうでなければ2番目の文字列をスタックに積みます。
## 例えば`empty__name__:g:sor`は、`name__:g`が空文字列なら「empty」に、そうでなければ`name__:g`になります。
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
## スタックから文字列を2つ取り出し、それぞれを数値とみなして、2番目の数値が0なら1番目の数値を、そうでなければ2番目の数値をスタックに積みます。
## 例えば`2__1__:ior`は「1」に、`2__0__:ior`は「2」になります。
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
## スタックから文字列を1つ取り出してフィールド名とみなし、このセッションのログインユーザーに対応する、ログインテーブルのレコードのフィールド値をスタックに積みます。
## このセッションでログインがおこなわれていない場合は、空文字列になります。
## 例えば`login__:loginrecord`は、このセッションのログイン名になります。
	function	bq_loginrecord__field($field)
	{
		global	$loginrecord;
		
		if ($loginrecord !== null)
			return array($loginrecord->getfield($field)."");
		return array("");
	}
}

