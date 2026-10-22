<?php
#
#	Make env_xxx.py from env.php (run on the server; the values are not shown).
#	sudo php genenv.php /var/www/html/app/env.php tables_app /var/www/html/app > /var/www/nophp-py/env_app.py
#	With only env.php, only env(sys) is printed (env.py of the old layout).
#	Only the "$sys->xxx = ...;" settings are converted; check the result
#	(date_default_timezone_set(), setlocale(), require(), header(), ... are not).
#
$sys = new stdClass;
include($argv[1]);
$tables = @$argv[2];
$workdir = @$argv[3];
$j = JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE;
echo "# generated from env.php\nimport locale\n\n";
if ($tables != "")
	echo "import nophp\nimport {$tables}\n";
echo "from phpcompat import PhpArray\n\n";
if ($tables != "")
	echo "WORKDIR = ".json_encode($workdir, $j)."\n\n";
echo "\ndef env(sys):\n";
echo "\ttry:\n\t\tlocale.setlocale(locale.LC_CTYPE, \"en_US.UTF-8\")\n\texcept locale.Error:\n\t\tpass\n";
foreach (get_object_vars($sys) as $k => $v) {
	$s = json_encode($v, $j);
	if (is_array($v))
		$s = "PhpArray($s)";
	echo "\tsys.$k = $s\n";
}
if ($tables != "")
	echo "\n\napplication = nophp.make_application(env, {$tables}, WORKDIR)\n\nif __name__ == \"__main__\":\n\tnophp.serve(application)\n";
