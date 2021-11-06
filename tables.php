<?php

class	login_table	extends	a_login_table {
}
new login_table();


class	commandparser_htmlsrc	extends	commandparser {
	function	parsehtml($tableholder = null, $record = null) {
		return nl2br(htmlspecialchars(parent::parsehtml($tableholder, $record), ENT_QUOTES));
	}
}

class	message0_table extends a_table {
	function	getconfig() {
		return parent::getconfig().<<<EOO

EOO;
	}
}
new message0_table();

class	message1_table extends a_table {
	function	getconfig() {
		return parent::getconfig().<<<EOO

EOO;
	}
	function	tv_mail($par, $s) {
		if (!preg_match('/@/', $s))
			return 1;
		return 0;
	}
}
new message1_table();

class	message2_table extends a_table {
	function	getconfig() {
		return parent::getconfig().<<<EOO
owner	integer	/*indexed*/
body	text

EOO;
	}
}
new message2_table();

class	test2_table extends a_table {
	function	getconfig() {
		return parent::getconfig().<<<EOO

EOO;
	}
}
new test2_table();

class	master0_table extends a_table {
	function	getconfig() {
		return parent::getconfig().<<<EOO


EOO;
	}
}
new master0_table();
$sys->mid = "master0";

class	test3_table extends a_table {
	function	getconfig() {
		return parent::getconfig().<<<EOO

EOO;
	}
}
new test3_table();

