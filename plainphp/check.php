<?php
#
#	nophp	https://github.com/paijp/nophp
#	plainphp/check.php: does the new code understand the database of this
#	server? See plainphp/README.md. Released under the MIT License.
#
# Reads every record and makes every page, changes nothing (the transaction
# is rolled back), and prints counts only: no names, no texts. So its output
# of a production server may be shown to anybody (and to Claude).
#
# Use: copy to the application, and write the part marked APPLICATION.
#   php check.php        (as the user of the web server)

if (PHP_SAPI != "cli")
	die();

# ---- APPLICATION: load the code as index.php does, but do not call run()
require(__DIR__."/lib.php");
$sys = new sys();
ob_start();		# the settings file may call header()
require(($s = getenv("APP_ENV"))? $s : __DIR__."/example/env.php");
ob_end_clean();
$SCHEMA = array(
	"simple" => array("name	text unique not null", "contents	text"),
	"login" => array("login	text unique not null", "pass	text", "salt	text", "sessionkey	text", "lastlogin	int", "lastlogout	int", "ismaillogin	int"),
	"memo" => array("owner	int	/*indexed*/"),
);
require(__DIR__."/example/pages.php");
# pages to make: page name => SQL that gives the query of each ("" = once, without a query)
$CHECKPAGES = array(
	"rootpage" => "",
	"memo" => "select id as id from memo",
);
# ---- end of APPLICATION

ini_set("memory_limit", "256M");
$checkmode = 1;		# finish() throws instead of committing
$sys->now = time();
$sys->urlbase = "http://check/index.php";
$sys->url = "http://check/index.php/nofmt/check.html";
dbopen();
$db->beginTransaction();

$problems = 0;
$warnings = array();
set_error_handler(function($no, $str, $file, $line) {
	global	$warnings;
	if ((error_reporting() & $no))		# not for "@..."
		@$warnings[basename($file).":{$line}"]++;
	return TRUE;
});

# 1. Every record: would save() write back exactly what is stored?
foreach ($SCHEMA as $table => $dummy) {
	if ($table == "simple")
		continue;
	$n = $diff = $nullcols = 0;
	$keys = array();
	$sp = $db->query("select * from {$table}");		# row by row: a table may not fit in memory
	while (($row = $sp->fetch(PDO::FETCH_ASSOC)) !== FALSE) {
		$n++;
		$r = rec::fromrow($table, $row);
		foreach ($r->columns() as $key => $val) {
			if (@$row[$key] === null) {
				if ($val !== "")
					$diff++;
				else if ($key != "mixed")
					$nullcols++;
			} else if ((string)$row[$key] !== $val) {
				$diff++;
				break;
			}
		}
		foreach (explode("\t", (string)@$row["mixed"]) as $chunk)
			if (count($a = explode("=", $chunk, 2)) == 2)
				@$keys[$a[0]]++;
		unset($r, $row);
	}
	unset($sp);
	ksort($keys);
	$s = "";
	foreach ($keys as $key => $val)
		$s .= " ".preg_replace('/[^_0-9A-Za-z]/', "?", (string)$key)."={$val}";
	printf("%-13s %6d record(s), %d would change when saved, %d empty column value(s) stored as NULL; fields in mixed:%s\n", $table, $n, $diff, $nullcols, $s);
	$problems += $diff;
}

# 2. Every page (as the first user), counting what goes wrong.
$loginrecord = rec::get("login", (int)@sql("select min(id) as id from login")[0]["id"]);
foreach ($CHECKPAGES as $page => $query) {
	$list = array(array());
	if ($query != "") {
		$list = array();
		foreach (sql($query) as $row)
			$list[] = array_map("strval", $row);
	}
	$bad = 0;
	foreach ($list as $get) {
		$_GET = $get;
		$sys->target = $page;
		$sys->urlquery = http_build_query($get);
		ob_start();
		try {
			call_user_func("page_{$page}");
			if (strlen(ob_get_clean()) == 0)
				$bad++;
		} catch (Throwable $e) {
			ob_end_clean();
			$bad++;
			@$warnings["exception ".get_class($e)." ".basename($e->getFile()).":".$e->getLine()]++;
		}
	}
	printf("page %-13s %6d made, %d failed (peak memory %dM)\n", $page, count($list), $bad, memory_get_peak_usage() / 1048576);
	$problems += $bad;
}
ksort($warnings);
foreach ($warnings as $key => $val)
	print "warning {$key}: {$val}\n";
$problems += count($warnings);
$db->rollBack();
print (($problems)? "PROBLEMS: {$problems}" : "ok: nothing would change, every page was made")."\n";
exit(($problems)? 1 : 0);
