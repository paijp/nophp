<?php
#
#	nophp	https://github.com/paijp/nophp
#	
#	Copyright (c) 2021-2023 paijp
#
#	This software is released under the MIT License.
#	http://opensource.org/licenses/mit-license.php
#

$findtableidscache = array();
$ascii2null = array();
for ($i=0; $i<128; $i++)
	$ascii2null[chr($i)] = "";


class	im_table {
	var	$r;
	function	__construct($name, $id = 0) {
		global	 $tablelist;
		
		$this->r = $tablelist[$name]->getrecord($id);
	}
	function	del() {
		execsql("delete from {$this->r->tablename};", null, 0, 1);
	}
	function	set($key, $val) {
		$this->r->setfield($key, $val);
	}
	function	get($key) {
		if ($key != "id")
			return $this->r->getfield($key);
		if ($this->r->id <= 0)
			 $this->r->update();
		return $this->r->id;
	}
	function	update() {
		 $this->r->update();
	}
}


class	im_tables {
	var	$name;
	var	$list;
	function	__construct($name) {
		$this->name = $name;
		$this->list = array();
	}
	function	del() {
		global	$tablelist;
		
		$t = $tablelist["simple"]->gettable($this->name);
		$t->updatelist(array());
	}
	function	update() {
		global	$tablelist;
		global	$findtableidscache;
		
		$findtableidscache = array();	# clear cache.
		
		$t = $tablelist["simple"]->gettable($this->name);
		$list = $t->getlist();
		$list[] = $this->list;
		$t->updatelist($list);
	}
	function	set($key, $val) {
		$this->list[$key] = $val;
	}
	function	findtableids($field, $val) {
		global	$tablelist;
		global	$findtableidscache;
		
		if (($cache = @$findtableidscache[$cachename = "{$this->name}|{$field}"]) === null) {
			$cache = array();
			$t = $tablelist["simple"]->gettable($this->name);
			$list = $t->getlist();
			foreach ($list as $key => $a)
				$cache[@$a[$field]] = $key + 1;
			$findtableidscache[$cachename] = $cache;
		}
		return (int)@$cache[$val];
	}
	function	findtableidsa($field, $val) {
		global	$tablelist;
		global	$findtableidscache;
		
		if (($cache = @$findtableidscache[$cachename = "{$this->name}|{$field}"]) === null) {
			$cache = array();
			$t = $tablelist["simple"]->gettable($this->name);
			$list = $t->getlist();
			foreach ($list as $key => $a)
				$cache[@$a[$field]] = $key + 1;
			$findtableidscache[$cachename] = $cache;
		}
		if (@$cache[$val] === null) {
			$t = $tablelist["simple"]->gettable($this->name);
			$list = $t->getlist();
			$list[] = array($field => $val);
			$t->updatelist($list);
			$cache[$val] = count($list);
			$findtableidscache[$cachename] = $cache;
		}
		return (int)@$cache[$val];
	}
	function	findtableidsz($field, $val) {
		global	$tablelist;
		global	$ascii2null;
		
		$s0 = strtr(mb_convert_kana($val, "as", "UTF-8"), $ascii2null);
		
		$t = $tablelist["simple"]->gettable($this->name);
		$list = $t->getlist();
		foreach ($list as $key => $a) {
			$s1 = strtr(mb_convert_kana(@$a[$field], "as", "UTF-8"), $ascii2null);
			if ($s0 == $s1)
				return $key + 1;
		}
		return 0;
	}
}


function	basicauth()
{
	global	$sys;
	
	if (@$sys->auth_user === "")
		return;
	if (@$_SERVER["PHP_AUTH_USER"] != @$sys->auth_user)
		;
	else if (myhash(@$sys->auth_salt.@$_SERVER["PHP_AUTH_PW"]) != @$sys->auth_pass)
		;
	else
		return;
	
	header('WWW-Authenticate: Basic realm="www"');
	header("HTTP/1.0 401 Unauthorized");
	log_die();
}


if (@$_GET["mode"] == "sql") {
	basicauth();
	if (@$_POST["create"] !== null) {
		$debuglog = "<H1>".htmlspecialchars(@$_GET["mode"])."</H1>\n\n";

		foreach ($tablelist as $table)
			$table->createtable();
		log_die();
	}
	do {
		if (($s = @$_POST["query"]) === null)
			break;
		execsql();
		if (($sp0 = $db0->prepare($s)) === FALSE) {
			$a = $db0->errorInfo();
			print htmlspecialchars($a[2])."<BR>\n";
			break;
		}
		if (!$sp0->execute()) {
			$a = $sp0->errorInfo();
			print htmlspecialchars($a[2])."<BR>\n";
			break;
		}
		$list = $sp0->fetchAll(PDO::FETCH_ASSOC);
		if (count($list) == 0) {
			print "changes: ".$sp0->rowCount()."<BR>\n";
			break;
		}
		$first = 1;
		print "<TABLE border=2>\n";
		foreach ($list as $a) {
			if (($first)) {
				$first = 0;
				print "  <TR>\n";
				foreach ($a as $key => $val)
					print "    <TH>".htmlspecialchars($key)."</TH>\n";
				print "  </TR>\n";
			}
			print "  <TR>\n";
			foreach ($a as $key => $val)
				print "    <TD>".htmlspecialchars($val)."</TD>\n";
			print "  </TR>\n";
		}
		print "</TABLE>";
	} while (0);
	
	print <<<EOO
<FORM method=POST>
<TEXTAREA name=query cols=40 rows=10></TEXTAREA>
<INPUT type=submit>
</FORM>
<HR>
<FORM method=POST>
<INPUT type=submit name=create value="create">
<A href="?mode=import">import</A>
</FORM>
</BODY></HTML>
EOO;
	log_die();
}
if (@$_GET["mode"] == "import") {
	basicauth();
ini_set("max_execution_time", "300");
ini_set("display_errors", "TRUE");
$debuglog = "<H1>".htmlspecialchars(@$_GET["mode"])."</H1>\n\n";
		print <<<EOO
<FORM method=POST>
<UL>

EOO;
	foreach ($importlist as $key => $val) {
		$name = "import_".bin2hex($val);
		$s = htmlspecialchars($val);
		print <<<EOO
	<LI><LABEL><INPUT type=checkbox name="{$name}" value=1>{$s}</LABEL>

EOO;
		if (@$_POST[$name] != "") {
			$fp = fopen($val, "r") or log_die("fopen failed: ".htmlspecialchars($val)."(".bin2hex($val).")");
			execsql("begin;");
			$stack = array();
			$list = array();
			while (($line = fgets($fp)) !== FALSE) {
				$a = explode("\t", rtrim($line));
				if (count($a) < 2)
					continue;
				switch ($a[1]) {
					default:
						if (!class_exists($s = "im_".$a[1]))
							log_die("unknown command: ".htmlspecialchars($a[1])."(".bin2hex($a[1]).")");
						$obj = new $s(trim($a[0]));
						array_unshift($stack, $obj);
						continue 2;
					case	"":
					case	"#":
						continue 2;
					case	"#message":
						$a = explode("\t", $line, 3);
						print "<P>".htmlspecialchars(date("Y/m/d H:i:s ").@$a[2])."</P>\n";
						flush();
						continue 2;
					case	"delete":
						$obj = new im_table(trim($a[0]));
						$obj->del();
						continue 2;
					case	"deletes":
						$obj = new im_tables(trim($a[0]));
						$obj->del();
						continue 2;
					case	"table{":
						$obj = new im_table($s = trim($a[0]), (int)@$a[2]);
						$list[$s] = $obj;
						array_unshift($stack, $obj);
						if ((int)@$a[2] == 0)
							continue 2;
						if ($obj->get("id") > 0)
							continue 2;
						print htmlspecialchars("record not found : {$r->tablename}#{$r->id}.".$a[0])."<BR>\n";
						log_die();
					case	"}":
						$stack[0]->update();
						array_shift($stack);
						continue 2;
					case	"=":
						$a = explode("\t", rtrim($line), 3);
						$stack[0]->set(trim($a[0]), @$a[2]);
						continue 2;
					case	"==":
						$a = explode("\t", rtrim($line), 3);
						$s = $stack[0]->get(trim($a[0]));
						if ($s == @$a[2])
							continue 2;
						$r = $stack[0]->r;
						print htmlspecialchars("{$r->tablename}#{$r->id}.".$a[0])."<BR>\n";
						print htmlspecialchars("expected : ".@$a[2])."<BR>\n";
						print htmlspecialchars("found : {$s}")."<BR>\n";
						log_die();
					case	"=sprintf":
						break;
				}
				$a0 = array(@$a[2]."");
				for ($i=3; $i<count($a); $i++) {
					$a1 = explode(".", $a[$i]);
					if ($a1[0] != "") {
						$a0[] = $list[$a1[0]]->get(@$a1[1]);
						continue;
					}
					switch ($s = @$a1[1]."") {
						default:
							log_die("unknown command: {$s}(".bin2hex($s).")");
						case	"findtableids":
						case	"findtableidsa":
						case	"findtableidsz":
							$a1 = explode(".", $a[$i], 5);
							$obj = new im_tables(@$a1[2]);
							$a0[] = $obj->$s(@$a1[3], @$a1[4]);
							continue 2;
					}
				}
				$s = call_user_func_array("sprintf", $a0);
				$stack[0]->set(trim($a[0]), $s);
			}
			execsql("commit;");
			fclose($fp);
		}
	}
	print <<<EOO
</UL>
<UL>
	<LI><INPUT type=submit value=import>
</UL>
</FORM>

EOO;
	log_die();
}


