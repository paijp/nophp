<?php
#
#	Make env.py from env.php (run on the server; the values are not shown).
#	sudo php genenv.php /var/www/html/app/env.php > /var/www/app-py/env.py
#	Only the "$sys->xxx = ...;" settings are converted; check the result
#	(date_default_timezone_set(), setlocale(), require(), header(), ... are not).
#
$sys = new stdClass;
include($argv[1]);
$j = JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE;
echo "# generated from env.php\nimport locale\n\nfrom phpcompat import PhpArray\n\n\ndef env(sys):\n";
echo "\ttry:\n\t\tlocale.setlocale(locale.LC_CTYPE, \"en_US.UTF-8\")\n\texcept locale.Error:\n\t\tpass\n";
foreach (get_object_vars($sys) as $k => $v) {
	$s = json_encode($v, $j);
	if (is_array($v))
		$s = "PhpArray($s)";
	echo "\tsys.$k = $s\n";
}
