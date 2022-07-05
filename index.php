<?php
#
#	nophp	https://github.com/paijp/nophp
#	
#	Copyright (c) 2021 paijp
#
#	This software is released under the MIT License.
#	http://opensource.org/licenses/mit-license.php
#

class	sys {
#	var	$htmlbase = "/html/user/v1/";
#	var	$sqlpath = "sqlite:/var/www/html/db/v1.sq3";
##	var	$sqlpath = "mysql:dbname=test";
	var	$debugdir = "debuglog";
	var	$debugmaxlogrecords = 500;
	var	$debugchunksize = 4000;	# atomic writable size: 4000 for linux, 900 for windows.
	var	$debuggz = 1;		# 1:compress debuglog and coveragelog
	var	$mailinterval = 60;
	var	$mailexpire = 1800;
	var	$forcelogoutonupdate = 1;
	var	$noredirectonlogin = 0;		# 1: don't redirect from index.html to rootpage on login status.
	
	var	$urlbase = null;
	var	$target = null;
}
$sys = new sys();

require("env.php");

if (!function_exists("myhash")) {
	function	myhash($s) {
		return hash("sha256", $s);
	}
}
if (trim(myhash("")) == "")
	die("hash not work");

if (@$_SERVER["HTTPS"] == "on")
	$sys->url = "https://";
else
	$sys->url = "http://";
$a = explode("?", @$_SERVER["REQUEST_URI"], 2);
$sys->url .= @$_SERVER["HTTP_HOST"].$a[0];
$sys->urlquery = @$a[1];


if ((@$logview_fn !== null)) {
# The name may be like "\000__COMPILER_HALT_OFFSET__\000/var/www/html....php" and it is not documented.
	$log_fp = null;
	foreach (get_defined_constants() as $key => $val) {
		if (!preg_match('/^__COMPILER_HALT_OFFSET__/', ltrim($key)))
			continue;
		$log_fp = popen("dd bs=1 if=".escapeshellarg(@$logview_self)." skip={$val} |gunzip", "r");
		break;
	}
	if ((@$_GET["snapshot"])) {
		if ($log_fp === null)
			$content = file_get_contents("{$logview_fn}.php");
		else {
			$content = stream_get_contents($log_fp);
			pclose($fp_log);
			$log_fp = null;
		}
		$output = "";
		foreach (explode('<pre class="outphase1"', $content) as $key => $val) {
			if ($key == 0)
				continue;
			$a = explode(">", $val, 2);
			$a2 = explode("</pre>", @$a[1], 2);
			$output .= html_entity_decode(strip_tags($a2[0]));
		}
		if (count($a = preg_split("/<HEAD>/i", $output, 2)) == 2) {
			list($s0, $s1) = $a;
			$output = <<<EOO
{$s0}<HEAD><BASE href="{$logview_urlbase}/nopost/snapshot.html">
{$s1}
EOO;
		} else {
			$output = <<<EOO
<HEAD><BASE href="{$logview_urlbase}/nopost/snapshot.html"></HEAD>
{$output}
EOO;
		}
		print $output;
		die();
	}
	if ((@$_GET["coverage"])) {
		$fn = "{$logview_coverage}.log";
		if ($log_fp === null) {
			if (!is_readable($fn))
				die();
			$content = file_get_contents($fn);
		} else {
			$fp = popen("gunzip <".escapeshellarg("{$fn}.gz"), "r");
			$content = stream_get_contents($fp);
			pclose($fp);
		}
		$list = array();
		$actionid = -1;
		$actionlist = array();
		foreach (preg_split("/\r\n|\r|\n/", $content) as $line) {
			if (count($a = explode("\t", $line, 4)) < 3)
				continue;
			$key = $a[1] + 0;
			$key2 = "_".$a[2];
			if (count($a) == 3) {
				$actionid = $key;
				$actionlist[$key2] = 1;
				continue;
			}
			$key3 = "_".$a[3];
			if (@$list[$key][$key2][$key3] === null)
				$list[$key][$key2][$key3] = $a[0];
		}
	
		$head = null;
		$titlelist = array();
		foreach($list[0]["_0"] as $key => $val) {
			if ($head === null) {
				$head = "";
				print "<table border>\n";
				foreach (explode("\t", substr($key, 1)) as $k => $v) {
					if (($k & 1))
						continue;
					$head .= '<tr><th colspan="'.($k / 2 + 2).'" style="text-align: right;';
					if ((($k / 2) % 6) < 3)
						$head .= " background: #8f8;";
					$head .= '">';
					list($l, $s) = explode("_", base64_decode($v), 2);
					$titlelist[$l] = $s;
					$head .= '<a href="#'.$l.'">'.htmlspecialchars($s)."</a>\n";
				}
				print $head;
			}
			$fn = htmlspecialchars($val, ENT_QUOTES);
			print '<tr><th style="text-align: left;"><a href="'.$fn.'.php">'."{$fn}\n";
			foreach (explode("\t", substr($key, 1)) as $k => $v)
				if (($k & 1)) {
					print "\t<td";
					if ((($k / 2) % 6) < 3)
						print ' style="background: #8f8;"';
					print ">";
					if (($s = base64_decode($v)) == 0)
						$s = "";
					print htmlspecialchars($s)."\n";
				}
		}
		if ($head !== null)
			print "{$head}</table>\n";
		
		$searchlist = array("home", "return", "break", "jump");
		$replacelist = array();
		foreach ($searchlist as $s)
			$replacelist[] = '<span style="color:#f00;">'."{$s}</span>";
		
		ksort($list);
		foreach ($list as $id => $val) {
			if ($id == 0)
				continue;
			print '<h2><a name="'.$id.'">'.htmlspecialchars(@$titlelist[$id])."</a></h2>\n";
			foreach ($val as $key2 => $val2) {
				if ($id == $actionid)
					$actionlist[$key2] = 0;
				$head = "";
				$pars = "";
				foreach (explode("__", base64_decode($key2)) as $block) {
					if (!preg_match('/^:/', $block)) {
						$pars .= "{$block}__ ";
						continue;
					}
					$remaincmds = explode(":", $block);
					array_shift($remaincmds);
					foreach ($remaincmds as $cmd) {
						$head .= '<th><span style="color: #f8f;">'.htmlspecialchars($pars);
						$pars = "";
						$head .= "</span>:".str_replace($searchlist, $replacelist, htmlspecialchars($cmd));
					}
				}
				print "<table border><tr><th>\n{$head}";
				foreach ($val2 as $key3 => $val3) {
					$a = explode("#", $val3);
					$fn = htmlspecialchars($a[0], ENT_QUOTES);
					$frag = htmlspecialchars($a[1], ENT_QUOTES);
					print '<tr><th style="text-align: left;"><a href="'.$fn.'.php#'.$id.".".$frag.'">'."{$fn}#{$frag}\n";
					foreach (explode("\t", $key3) as $k => $v)
						print "\t".'<td style="text-align: right;">'.htmlspecialchars(base64_decode($v));
				}
				print "<tr><th>\n{$head}</table>\n<p></p>\n";
			}
		}
		if ($id != $actionid)
			print '<h2><a name="'.$actionid.'">'."(action)</a></h2>\n";
		foreach ($actionlist as $key2 => $val2) {
			if ($val2 == 0)
				continue;
			$head = "";
			$pars = "";
			foreach (explode("__", base64_decode($key2)) as $block) {
				if (!preg_match('/^:/', $block)) {
					$pars .= "{$block}__ ";
					continue;
				}
				$remaincmds = explode(":", $block);
				array_shift($remaincmds);
				foreach ($remaincmds as $cmd) {
					$head .= '<th><span style="color: #f8f;">'.htmlspecialchars($pars);
					$pars = "";
					$head .= "</span>:".str_replace($searchlist, $replacelist, htmlspecialchars($cmd));
				}
			}
			print '<table border><tr><th><span style="color: #aaa;">(no log)</span>'."\n{$head}";
#			print '<tr><th><span style="color: #aaa;">(no log)</span>'."\n{$head}</table>\n<p></p>\n";
			print "</table>\n<p></p>\n";
		}
		die();
	}
	print "<pre><b>".htmlspecialchars(@file_get_contents("{$logview_fn}.errorlog"))."</b></pre>\n";
	
	if ($log_fp !== null) {
		print stream_get_contents($log_fp);
		die();
	}
	return;
}
$coverage_list = null;
$coverage_nextid = 0;
$coverage_id = 0;
$coverage_title = array();
$coverage_count = array();
$coverage_actionlist = array();

$sys->importlist = array();

list($m, $s) = explode(" ", microtime());
$sys->debugfn = date("ymd_His_", $s + 0).substr($m, 2);
$sys->now = $s;

if (@$sys->rootpage === null)
	$sys->rootpage = "g0000";
if (!is_dir(@$sys->debugdir)) {
	$sys->debugdir = null;
	$sys->debugfn = null;
}


$fplist = array();


function	file_add_contents($fn, $s, $gz = 0)
{
	global	$fplist;
	
	if ($fn == "")
		return;
	if (($fp = @$fplist[$fn]) !== null)
		;
	else if (($gz))
		$fp = $fplist[$fn] = @popen("gzip >>".escapeshellarg($fn), "w");
	else
		$fp = $fplist[$fn] = @fopen($fn, "a");
	if ($fp !== null) {
		fputs($fp, $s);
		fflush($fp);
	}
}


$targethash = "";
$tableshash = "";


function	adddebuglog($debugdir = null, $debugfn = null)
{
	global	$sys;
	global	$debuglog;
	global	$targethash;
	global	$tableshash;
	
	if (@$sys->debugdir !== null) {
		if ($debugdir === null)
			$debugdir = $sys->debugdir;
		if ($debugfn === null)
			$debugfn = "{$sys->debugfn}.php";
		$fn = "{$debugdir}/{$debugfn}";
		
		if (!file_exists($fn)) {
			$fn_self = __FILE__;
			
			$s = <<<EOO
<?php
if ((@\$isinclude))
	return;
\$logview_fn = "{$sys->debugfn}";
\$logview_coverage = "{$targethash}.{$tableshash}";
\$logview_urlbase = "{$sys->urlbase}";
\$logview_targetpath = "{$sys->htmlbase}/{$sys->target}.html";
\$logview_self = __FILE__;
require("{$fn_self}");

EOO;
			if (($sys->debuggz))
				$s .= "__halt_compiler();";
			else
				$s .= "?>";

			file_put_contents($fn, $s, FILE_APPEND);
		}
		$a = explode(" ", microtime());
		file_add_contents($fn, "<!-- ".date("ymd_His", $a[1] + 0).substr($a[0], 1)." -->{$debuglog}", $sys->debuggz);
	}
	$debuglog = "";
}


$coveragelogbuffer = "";


function	addcoveragelog($fn, $s = null)
{
	global	$sys;
	global	$coveragelogbuffer;
	
	if ($s === null)
		;
	else if (strlen($coveragelogbuffer) + strlen($s) < $sys->debugchunksize) {
		$coveragelogbuffer .= $s;
		return;
	}
	
	if ($sys->debuggz == 0) {
		file_put_contents($fn, $coveragelogbuffer, FILE_APPEND);
		$coveragelogbuffer = "";
		return;
	}
	$fn .= ".gz";
	if (function_exists("gzencode")) {
		file_put_contents($fn, gzencode($coveragelogbuffer), FILE_APPEND);
		$coveragelogbuffer = "";
		return;
	}
	$fp = popen("gzip >>".escapeshellarg($fn), "w");
	fputs($fp, $coveragelogbuffer);
	pclose($fp);
	$coveragelogbuffer = "";
}


$db0 = new PDO($sys->sqlpath);


function	log_die($message = "")
{
	global	$sys;
	global	$tablelist;
	global	$debuglog;
	global	$debugtablelist;
	global	$loginrecord;
	global	$targethash;
	global	$tableshash;
	global	$coverage_list;
	global	$coverage_title;
	global	$coverage_count;
	
	if (($loginrecord))
		execsql("commit;");
	
	if (@$sys->debugdir === null)
		die($message);
	
	$debugdir0 = $sys->debugdir;
	$sys->debugdir = null;
	
	$debuglog .= "<B>".htmlspecialchars($message)."</B>\n";
	$debuglog .= "<HR><H1>table changes</H1>";
	adddebuglog($debugdir0);
	
	foreach ($debugtablelist as $tablename => $orglist) {
		$table = $tablelist[$tablename];
		$idlist = $table->getrecordidlist();
		if (count($idlist) >= $sys->debugmaxlogrecords)
			continue;
		
		$mergeidlist = array();
		$collist = array();
		foreach ($orglist as $id => $r) {
			$mergeidlist[$id] = 1;
			foreach (get_object_vars($r) as $varname => $varval)
				if (preg_match('/^v_(.*)/', $varname, $a))
					$collist[$a[1]] = 1;
		}
		$newlist = array();
		foreach ($idlist as $id) {
			$mergeidlist[$id] = 1;
			$newlist[$id] = $table->getrecord($id);
			foreach (get_object_vars($newlist[$id]) as $varname => $varval)
				if (preg_match('/^v_(.*)/', $varname, $a))
					$collist[$a[1]] = 1;
		}
		ksort($mergeidlist);
		$debuglog .= <<<EOO
<H2>{$tablename}</H2>
<TABLE border>
<TR>
	<TH>id

EOO;
		foreach ($collist as $colname => $dummy)
			$debuglog .= "\t<TH>{$colname}\n";
		foreach ($mergeidlist as $id => $dummy) {
			$issame = 1;
			foreach ($collist as $colname => $dummy) {
				$s = "v_{$colname}";
				if (@$orglist[$id]->$s != @$newlist[$id]->$s) {
					$issame = 0;
					break;
				}
			}
			$link = "";
			$hrefname = "{$tablename}.{$id}";
			if (($s = @$orglist[$id]->v_debuglogfn) !== null)
				$link = '<A href="'.$s.'.php#'.$hrefname.'">log</A>';
			
			if (($issame)) {
				$debuglog .= '<TR><TH style="color:#a0a0a0;"><A name="'.$hrefname.'">'."{$id}</A> {$link}\n";
				foreach ($collist as $colname => $dummy) {
					$s = "v_{$colname}";
					$debuglog .= '<TD style="color:#a0a0a0;">'.nl2br(htmlspecialchars(@$orglist[$id]->$s))."\n";
				}
			} else {
				$debuglog .= '<TR><TH><A name="'.$hrefname.'">'."{$id}{$link}\n";
				foreach ($collist as $colname => $dummy) {
					$s = "v_{$colname}";
					if (@$orglist[$id]->$s == @$newlist[$id]->$s) {
						$debuglog .= '<TD style="color:#a0a0a0;">'.nl2br(htmlspecialchars(@$orglist[$id]->$s))."\n";
					} else {
						$debuglog .= '<TD><S style="color:#ff0000">'.nl2br(htmlspecialchars(@$orglist[$id]->$s))."</S><BR>";
						$debuglog .= '<U>'.nl2br(htmlspecialchars(@$newlist[$id]->$s))."</U>\n";
					}
				}
			}
		}
		$debuglog .= "</TABLE>\n";
		adddebuglog($debugdir0);
	}
	if ($coverage_list !== null) {
		$fn = "{$debugdir0}/{$targethash}.{$tableshash}.log";
		$s0 = $sys->debugfn."\t0\t0";
		foreach ($coverage_title as $k => $v)
			if ($v != "")
				$s0 .= "\t".base64_encode($v)."\t".base64_encode(@$coverage_count[$k] + 0);
		addcoveragelog($fn, $s0."\n");
		foreach ($coverage_list as $key => $val)
			foreach ($val as $key2 => $val2)
				addcoveragelog($fn, "{$sys->debugfn}#{$val2}\t{$key}{$key2}\n");
		addcoveragelog($fn);
	}
	die($message);
}


function	execsql($sql, $array = null, $returnid = 0, $ignoreerror = 0)
{
	global	$db0;
	global	$sys;
	global	$debuglog;
	
	if ($array === null)
		$array = array();
	
	if (@$sys->debugdir !== null) {
		$debuglog .= "<table>\n";
		foreach (explode("?", $sql) as $key => $val) {
			$debuglog .= '<tr><th align="right"><span style="color:#a00;">'.htmlspecialchars($val, ENT_QUOTES)."</span>";
			$debuglog .= '<td>'.nl2br(htmlspecialchars(@$array[$key], ENT_QUOTES));
		}
		$debuglog .= "</table>\n";
		adddebuglog();
	}
	
	if (($sp0 = $db0->prepare($sql)) === FALSE) {
		$a = $db0->errorInfo();
		if (@$sys->debugdir !== null) {
			$debuglog .= "<P><B>".htmlspecialchars($a[2], ENT_QUOTES)."</B></P>\n";
			adddebuglog();
		}
		if (!$ignoreerror)
			log_die($a[2]." : ".$sql);
		if (($returnid))
			return 0;
		return array();
	}
	if (!$sp0->execute($array)) {
		$a = $db0->errorInfo();
		if (@$sys->debugdir !== null) {
			$debuglog .= "<P><B>".htmlspecialchars($a[2], ENT_QUOTES)."</B></P>\n";
			adddebuglog();
		}
		if (!$ignoreerror)
			log_die($a[2]." : ".$sql);
		if (($returnid))
			return 0;
		return array();
	}
	if ($ignoreerror < 0) {
		if (@$sys->debugdir !== null) {
			$debuglog .= "<P><B>success.</B></P>\n";
			adddebuglog();
		}
		return 1;		# success.
	}
	if (($returnid)) {
		$ret = $db0->lastInsertId();
		if (@$sys->debugdir !== null) {
			$debuglog .= "<P><B>lastInsertId : {$ret}</B></P>\n";
			adddebuglog();
		}
		return $ret;
	}
	$list = $sp0->fetchAll(PDO::FETCH_ASSOC);
	if (!is_array($list))
		return array();
	if (@$sys->debugdir === null)
		return $list;
	
	if (count($list) <= 0)
		return $list;
	if (count($list) > $sys->debugmaxlogrecords)
		return $list;
	$debuglog .= "<TABLE border><TR>\n";
	foreach ($list[0] as $key => $val)
		$debuglog .= "<TH>".htmlspecialchars($key, ENT_QUOTES);
	foreach ($list as $fields) {
		$debuglog .= "<TR>";
		foreach ($fields as $val)
			$debuglog .= "<TD>".nl2br(htmlspecialchars($val, ENT_QUOTES));
	}
	$debuglog .= "</TABLE>";
	adddebuglog();
	
	return $list;
}


class	table {
	var	$tablename = null;
	var	$configlist;
	var	$id = -1;
	var	$methodlist = null;
	function	__construct($recordid = null, $configlist = null) {
		global	$sys;
		global	$tablelist;
		global	$debugtablelist;
		
		if (($this->configlist = $configlist) === null)
			$this->configlist = preg_split("/\r|\n|\r\n/", trim($this->getconfig()));
		if ($this->tablename === null) {
			$s = get_class($this);
			if (preg_match('/^([_0-9A-Za-z]+)_table$/', $s, $a))
				$s = $a[1];
			$this->tablename = $s;
		}
		if ($recordid === null) {
			$tablelist[$this->tablename] = $this;
			
			if ($sys->debugdir === null)
				return;
			$a = execsql("select count(id) as count from {$this->tablename}", null, 0, 1);
			if (count($a) == 0)
				return;
			if ($a[0]["count"] > $sys->debugmaxlogrecords)
				return;
			$list = array();
			foreach ($this->getrecordidlist() as $id) {
				$r = $this->getrecord($id);
				$list[$r->id] = $r;
			}
			$debugtablelist[$this->tablename] = $list;
			return;
		}
		$this->id = 0;
		if ($recordid <= 0)
			return;
		$sql = "select * from {$this->tablename} where id = ?;";
		$a = array($recordid);
		if (count($list = execsql($sql, $a, 0, 1)) <= 0)
			return;
		$this->id = $recordid;
		$mixed = null;
		foreach (@$list[0] as $key => $val) {
			switch ($key) {
				default:
					$s = "v_".$key;
					$this->$s = $val;
					continue 2;
				case	"id":
					$this->id = $val + 0;
					continue 2;
				case	"mixed":
					$mixed = $val;
					continue 2;
			}
		}
		if ($mixed !== null)
			foreach (explode("\t", $mixed) as $chunk) {
				if (count($a = explode("=", $chunk, 2)) < 2)
					continue;
				list($key2, $val2) = $a;
				$s = "v_".$key2;
				$this->$s = rawurldecode($val2);
			}
	}
	function	getconfig() {
		return <<<EOO
created	int
updated	int
deleted	int

EOO;
	}
	function	getrecord($id = 0) {
		$s = get_class($this);
		return new $s($id, $this->configlist);
	}
	function	getrecordidlist($cond = "", $list = null) {
		$sql = "select {$this->tablename}.id as id from {$this->tablename} {$cond};";
		if (count($list = execsql($sql, $list, 0, 1)) <= 0)
			return array();
		$idlist = array();
		foreach ($list as $record)
			$idlist[] = $record["id"] + 0;
		return $idlist;
	}
	function	getlist() {
		$list = array();
		foreach (get_object_vars($this) as $varname => $varval) {
			if (!preg_match('/^v_(.*)/', $varname, $a))
				continue;
			$list[$a[1]] = $varval;
		}
		return $list;
	}
	function	getfield($s) {
		if ($s != "id")
			$s = "v_{$s}";
		return @$this->$s;
	}
	function	update($ignoreerror = 0) {
		global	$sys;
		
		if ($this->id < 0)
			log_die("update called.");
		if ($this->id <= 0)
			$this->v_created = $this->v_updated = $sys->now;
		else
			$this->v_updated = $sys->now;
		
		if ($sys->debugfn !== null)
			$this->v_debuglogfn = $sys->debugfn;
		else if (@$this->v_debuglogfn !== null)
			unset($this->v_debuglogfn);
		
		$fieldlist = array();
		$mixedlist = array();
		foreach ($this->configlist as $line) {
#			list($name, $dummy) = explode("\t", $line, 2);
			list($name, $dummy) = preg_split("/[ \t]+/", $line, 2);
			$fieldlist[$name] = $this->getfield($name)."";
		}
		foreach (get_object_vars($this) as $varname => $dummy) {
			if (!preg_match('/^v_(.*)/', $varname, $a))
				continue;
			if (@$fieldlist[$a[1]] !== null)
				continue;
			if (preg_match('/^:/', $a[1]))
				continue;
			if (preg_match('/__/', $a[1]))
				continue;
			if (preg_match('/^[0-9]/', $a[1]))
				return;
			$mixedlist[$a[1]] = $a[1]."=".rawurlencode($this->$varname);
		}
		$fieldlist["mixed"] = implode("\t", $mixedlist);
		
		if ($this->id <= 0) {
			$skeys = implode(", ", array_keys($fieldlist));
			$svals = implode(", ", array_fill(0, count($fieldlist), "?"));
			$sql = "insert into {$this->tablename}($skeys) values($svals);";
			$a = array_values($fieldlist);
			$this->id = execsql($sql, $a, 1) + 0;
		} else {
			$a = array();
			foreach ($fieldlist as $key => $val)
				$a[] = "{$key} = ?";
			$s = implode(", ", $a);
			$sql = "update {$this->tablename} set {$s} where id = ?";
			$a = array_values($fieldlist);
			$a[] = $this->id;
			execsql($sql, $a, 0, $ignoreerror);
		}
	}
	function	delete() {
		if ($this->id <= 0)
			return;
		$sql = "delete from {$this->tablename} where id = ?;";
		execsql($sql, array($this->id));
		$this->id = 0;
		return;
	}
	function	createtable() {
# tables.phpでフィールドを追加した場合は、最初のアクセス時か、?mode=createをおこなったときに、生成済みのテーブルについてもalter table addや、create indexをおこなう。
# このとき、mixedに同名のフィールドが登録されていた時は、そちらから実フィールドにデータを移動する。
		global	$db0;
		
		$s = implode(",", $this->configlist);
		if ($db0->getAttribute(PDO::ATTR_DRIVER_NAME) == "sqlite") {
#	id int primary key autoincrement, 
# create table if not exists {$this->tablename}(
			$sql = <<<EOO
create table {$this->tablename}(
	id	integer primary key, 
	mixed	text, 
	{$s}
);
EOO;
		} else {
			$sql = <<<EOO
create table {$this->tablename}(
	id int auto_increment, 
	primary key (id), 
	mixed	longtext, 
	{$s}
);
EOO;
		}
		execsql($sql, null, 0, 1);
		
		$updated = 0;
		foreach ($this->configlist as $line) {
			list($fieldname) = preg_split("/[ \t]+/", trim($line), 2);
			
			$s = "alter table {$this->tablename} add ".trim($line).";";
			if ((execsql($s, null, 1, -1)))
				$updated = 1;
			
			if (strpos($line, "/*indexed*/") === FALSE)
				continue;
			
			$s = "create index {$this->tablename}{$fieldname}_index on {$this->tablename}({$fieldname});";
			execsql($s, null, 0, 1);
		}
		if ($updated == 0)
			return;
		
		execsql("begin;");
		foreach ($this->getrecordidlist() as $recordid) {
			$r = $this->getrecord($recordid);
			$r->update();
		}
		execsql("commit;");
	}
	function	dumpfields($title = "") {
		global	$sys;
		global	$debuglog;
		
		if (@$sys->debugdir == "")
			return;
		$debuglog .= '<table border><tr><th colspan="2" style="background:#aff;">'."{$title}{$this->tablename}\n";
		$debuglog .= "<tr><th>id<td>".htmlspecialchars($this->id)."\n";
		foreach (get_object_vars($this) as $key => $val)
			if (preg_match('/^v_(.*)/', $key, $a2)) {
				$s = htmlspecialchars($a2[1], ENT_QUOTES);
				$s2 = htmlspecialchars($val);
				$debuglog .= "<tr><th>{$s}<td>{$s2}\n";
			}
		$debuglog .= "</table>\n";
	}
}


class	a_table extends table {
	function	t_id__id__field($rh0, $record, $s1, $s3) {
## スタックから文字列を3つ取り出し、1番目をレコードID、2番目をフィールド名、3番目をテーブル名とみなし、テーブルのレコードIDのフィールド値をスタックに積みます。
## 例えば`1__name__user__:t_id`は、userテーブルのレコード1のnameフィールドの値になります。
		$r = $this->getrecord($s1 + 0);
		return array($r->getfield($s3)."");
	}
	function	t_find__val__field($rh0, $record, $s1, $s3) {
## スタックから文字列を3つ取り出し、1番目をフィールド値、2番目をフィールド名、3番目をテーブル名とみなし、テーブルにフィールド値が入っているレコードIDを検索してスタックに積みます。
## フィールド値が見つからなかった場合は空文字列を返します。複数のレコードがマッチした場合は、もっともIDの小さいものが返ります。
## 例えば`john__name__user__:t_find`は、userテーブルのnameフィールドの値がjohnであるレコードのレコードIDになります。
		foreach ($this->getrecordidlist() as $id) {
			$r = $this->getrecord($id);
			if (@$r->getfield($s3) == $s1)
				return array($id);
		}
		return array("");
	}
	function	h_set__val__field($rh0, $record, $s1, $s3) {
## スタックから文字列を3つ取り出して、それぞれフィールド値とフィールド名、テーブル名とみなし、指定したテーブルのカレントレコードに設定します。
## テーブル名は、あらかじめ「<!--{tableid」などで宣言されている必要があります。
## 例えば`1__id__user__:t_set`は、userテーブルのカレントレコードのIDに1を設定します。
		if ($s3 == "id")
			$s4 = $s3;
		else
			$s4 = "v_{$s3}";
		$this->$s4 = $s1;
		return array();
	}
	function	hs_update($rh0, $record = null) {
## スタックから文字列を1つ取り出し、テーブル名とみなして、そのテーブルのレコードをupdateまたはinsertします。
## テーブルは「<!--{tableid」などに指定したものを使用します。
		$s = "";
		if ($this->update() == 0)
			$s = $this->id;
		return array($s);
	}
	function	hs_delete($rh0, $record = null) {
#		global	$sys;
#		$this->v_deleted = $sys->now;
#		$this->update();
		$this->delete();
		return array("");
	}
	function	tv_len($par, $s) {
# <!--{valid len3-5 v1-->カレントレコードのv1のフィールドが、0,1,2,6,7文字の場合に表示されます。<!--}--> 
# <!--{valid len3- v1-->カレントレコードのv1のフィールドが、0,1,2文字の場合に表示されます。<!--}--> 
# <!--{valid len-5 v1-->カレントレコードのv1のフィールドが、6,7文字の場合に表示されます。<!--}--> 
		if (!preg_match('/^(-?[0-9]*)-(-?[0-9]*)/', $par, $a2))
			return 0;
		if ((($i = $a2[1]) != "")&&(strlen($s) < $i + 0))
			return 1;
		if ((($i = $a2[2]) != "")&&(strlen($s) > $i + 0))
			return 1;
		return 0;
	}
	function	tv_lenopt($par, $s) {
# <!--{valid lenopt3-5 v1-->カレントレコードのv1のフィールドが、1,2,6,7文字の場合に表示されます。<!--}--> 
# <!--{valid lenopt3- v1-->カレントレコードのv1のフィールドが、1,2文字の場合に表示されます。<!--}--> 
		if ($s == "")
			return 0;
		return $this->tv_len($par, $s);
	}
	function	tv_num($par, $s) {
# <!--{valid num3-5 v1-->カレントレコードのv1のフィールドが、整数の3-5でない場合に表示されます。<!--}--> 
# <!--{valid num3- v1-->カレントレコードのv1のフィールドが、整数の3以上でない場合に表示されます。<!--}--> 
# <!--{valid num-5 v1-->カレントレコードのv1のフィールドが、整数の5以下でない場合に表示されます。<!--}--> 
# <!--{valid num-5--3 v1-->カレントレコードのv1のフィールドが、整数の-5から-3でない場合に表示されます。<!--}--> 
		if (!preg_match('/^(-?[0-9]*)-(-?[0-9]*)/', $par, $a2))
			return 0;
		if (!preg_match('/^-?[0-9]+$/', $s))
			return 1;
		if ((($i = $a2[1]) != "")&&($s + 0 < $i + 0))
			return 1;
		if ((($i = $a2[2]) != "")&&($s + 0 > $i + 0))
			return 1;
		return 0;
	}
	function	tv_numopt($par, $s) {
# <!--{valid numopt3-5 v1-->カレントレコードのv1のフィールドが、空でなく、整数の3-5でもない場合に表示されます。<!--}--> 
# <!--{valid numopt3- v1-->カレントレコードのv1のフィールドが、空でなく、整数の3以上でもない場合に表示されます。<!--}--> 
# <!--{valid numopt-5 v1-->カレントレコードのv1のフィールドが、空でなく、整数の5以下でもない場合に表示されます。<!--}--> 
		if ($s == "")
			return 0;
		return $this->tv_num($par, $s);
	}
}


function	explodetab($s, $opt = "asKV") {
	$a = explode("\t", $s);
	foreach ($a as $key => $val) {
		if ($val == "NULL")
			$a[$key] = "";
		else
			$a[$key] = mb_convert_kana($val, $opt, "UTF-8");
	}
	return $a;
}


class	simpletable {
	var	$record = null;
	var	$list = null;
	var	$id = 0;
	var	$methodlist = null;
	var	$tablename;
	function	__construct($record, $tablename = "", $list = null, $id = 0) {
		$this->record = $record;
		$this->tablename = $tablename;
		$this->id = $id;
		if ($list !== null) {
			$this->list = $list;
			foreach ($list as $key => $val) {
				$s = "v_".$key;
				$this->$s = $val;
			}
			return;
		}
		$this->list = array();
		if (($s = @$this->record->v_contents) === null)
			return;
		foreach (explode("\n", $s) as $key => $line) {
			$this->list[$key] = array();
			foreach (explode("\t", $line) as $chunk) {
				if (count($a = explode("=", $chunk, 2)) < 2)
					continue;
				$this->list[$key][$a[0]] = rawurldecode($a[1]);
			}
		}
	}
	function	getfield($s) {
		if ($s != "id")
			$s = "v_{$s}";
		return @$this->$s;
	}
	function	getrecord($id = 0) {
		$s = get_class($this);
		if (($a = @$this->list[$id - 1]) === null)
			return new $s(null, $this->tablename, array(), 0);
		return new $s(null, $this->tablename, $a, $id);
	}
	function	getrecordidlist($dummy0 = "", $dummy1 = null) {
		$a = array();
		foreach ($this->list as $key => $val)
			$a[] = $key + 1;
		return $a;
	}
	function	getlist() {
		return $this->list;
	}
	function	updatelist($list = null) {
		if ($this->record === null)
			log_die("updatelist called.");
		$this->list = $list;
		$rowlist = array();
		foreach ($this->list as $y => $fields) {
			$collist = array();
			foreach ($fields as $key => $val)
				$collist[] = $key."=".rawurlencode($val);
			$rowlist[] = implode("\t", $collist);
		}
		$this->record->v_contents = implode("\n", $rowlist);
		$this->record->update();
	}
	function	update() {
		log_die("update called.");
	}
	function	dumpfields($title = "") {
		global	$sys;
		global	$debuglog;
		
		if (@$sys->debugdir == "")
			return;
		$debuglog .= "<TABLE border><TR><TH colspan=2>{$title}simple: {$this->tablename}\n";
		foreach (get_object_vars($this) as $key => $val)
			if (preg_match('/^v_(.*)/', $key, $a2)) {
				$s = htmlspecialchars($a2[1], ENT_QUOTES);
				$s2 = htmlspecialchars($val);
				$debuglog .= "<TR><TH>{$s}<TD>{$s2}\n";
			}
		$debuglog .= "</TABLE>\n";
	}
	function	h_set__val__field($rh0, $record, $s1, $s3) {
## スタックから文字列を3つ取り出して、それぞれフィールド値とフィールド名、テーブル名とみなし、指定したテーブルのカレントレコードに設定します。
## テーブル名は、あらかじめ「<!--{tableid」などで宣言されている必要があります。
## 例えば`1__id__user__:t_set`は、userテーブルのカレントレコードのIDに1を設定します。
		if ($s3 == "id")
			$s4 = $s3;
		else
			$s4 = "v_{$s3}";
		$this->$s4 = $s1;
		return array();
	}
	function	hs_update($rh0, $record = null) {
		global	$tablelist;
		
		$t = @$tablelist["simple"]->gettable($this->tablename);
		$list = $t->getlist();
		
		$fields = array();
		foreach (get_object_vars($this) as $varname => $varval) {
			if (!preg_match('/^v_(.*)/', $varname, $a))
				continue;
			$fields[$a[1]] = $varval;
		}
		
		if (@$list[$this->id - 1] === null)
			$list[] = $fields;
		else
			$list[$this->id - 1] = $fields;
		$t->updatelist($list);
		return array();
	}
	function	s_id__id__field($rh0, $record, $s1, $s3) {
## スタックから文字列を3つ取り出し、1番目をレコードID、2番目をフィールド名、3番目をシンプルテーブル名とみなし、シンプルテーブルのレコードIDのフィールド値をスタックに積みます。
## 例えば`1__name__user__:s_id`は、userシンプルテーブルのレコード1のnameフィールドの値になります。
		$r = $this->getrecord($s1 + 0);
		return array($r->getfield($s3)."");
	}
	function	s_find__val__field($rh0, $record, $s1, $s3) {
## スタックから文字列を3つ取り出し、1番目をフィールド値、2番目をフィールド名、3番目をシンプルテーブル名とみなし、シンプルテーブルにフィールド値が入っているレコードID検索してをスタックに積みます。
## フィールド値が見つからなかった場合は空文字列になります。。複数のレコードがマッチした場合は、もっともIDの小さいものになります。
## 例えば`john__name__user__:s_find`は、userテーブルのnameフィールドの値がjohnであるレコードのレコードIDになります。
		foreach ($this->getlist() as $k3 => $v3) {
			if (@$v3[$s3] == $s1)
				return array($k3 + 1);
		}
		return array("");
	}
}
class	innersimpletable	extends	table {
	var	$tablename = "simple";
	function	getconfig() {
		return parent::getconfig().<<<EOO
name	text unique not null
contents	text

EOO;
	}
	function	gettable($tablename = "test") {
		if (count($a = $this->getrecordidlist("where name = ?", array($tablename))) > 0)
			$r = $this->getrecord($a[0]);
		else
			$r = $this->getrecord();
		$r->v_name = $tablename;
		if (class_exists($s = "simpletable_{$tablename}"))
			return new $s($r, $tablename);
		return new simpletable($r, $tablename);
	}
}
new innersimpletable();


$loginrecord = null;
class	a_login_table extends a_table {
	function	getconfig() {
		return parent::getconfig().<<<EOO
login	text unique not null
pass	text
salt	text
sessionkey	text
lastlogin	int
lastlogout	int
ismaillogin	int

EOO;
	}
	function	createtable() {
		global	$sys;
		
		parent::createtable();
		if (@$sys->defaultuser == "")
			;
		else if (count($this->getrecordidlist()) == 0) {
			$r = $this->getrecord();
			$r->v_login = $sys->defaultuser;
			if (@$sys->defaultpass != "")
				$r->setpassword($sys->defaultpass, 1);
			else
				$r->update(1);
		}
	}
	function	update($ignoreerror = 0) {
		global	$sys;
		
		$r = $this->getrecord($this->id);
		if (($this->v_login != $r->v_login)&& function_exists("bq_login"))
			bq_login("changelogin", $r);
		$this->v_salt = $r->v_salt;
		$this->v_pass = $r->v_pass;
		if ((@$sys->forcelogoutonupdate)) {
			$this->v_sessionkey = "";
			$this->v_mailkey = "";
		}
		parent::update($ignoreerror);
	}
	function	getrandom() {
		if (($fp = fopen("/dev/urandom", "r")) !== FALSE) {
			$random = bin2hex(fread($fp, 20));
			fclose($fp);
		} else
			$random = myhash(microtime());
		return $random;
	}
	function	setmailkey($key) {
		global	$sys;
		
		$this->v_mailkey = myhash($this->v_login.$key);
		$this->v_mailsent = $sys->now;
		parent::update();
	}
	function	setpassword($newpass, $ignoreerror = 0) {
		if (function_exists("bq_login"))
			bq_login("changepass", $this);
		$this->v_salt = $this->getrandom();
		$this->v_pass = myhash($newpass.$this->v_salt);
		$this->v_sessionkey = "";
		$this->v_mailkey = "";
		parent::update($ignoreerror);
	}
	function	login($pass = "", $ismaillogin = 0) {
		global	$sys;
		global	$cookiepath;
		
		if (($ismaillogin)) {
			if (@$this->v_ismaillogin == 0)
				log_die("ismaillogin but not v_ismaillogin");
		} else if ((@$this->v_ismaillogin))
			log_die("v_ismaillogin but not ismaillogin");
		else if (myhash($pass.$this->v_salt) != $this->v_pass) {
			if (function_exists("bq_login"))
				bq_login("badlogin", $this);
			log_die("login fail.");
		}
		if (function_exists("bq_login"))
			bq_login("goodlogin", $this);
		$key = $this->getrandom();
		$this->v_sessionkey = myhash($this->v_salt.$key);
		$this->v_submitkey = $this->getrandom();
		$this->v_lastlogin = $sys->now;
		if (($ismaillogin)) {
			$this->v_mailkey = "";
			$this->v_pass = "";
		}
		parent::update();
		setcookie("sessionid", $this->id, 0, $cookiepath, "", (@$_SERVER["HTTPS"] == "on"), true);
		setcookie("sessionkey", $key, 0, $cookiepath, "", (@$_SERVER["HTTPS"] == "on"), true);
		log_die("login success.");
	}
	function	check_loginform() {
		global	$sys;
		
		header("Location: {$sys->url}");
		
		if (@$_GET["mode"] == "1login") {
			$uid = @$_GET["uid"] + 0;
			$key = @$_GET["key"]."";
			
			$r = $this->getrecord($uid);
			if (@$r->v_ismaillogin != 0)
				log_die("ismaillogin.");
			if (@$r->v_mailkey == "")
				log_die("mailkey empty.");
			if (@$sys->mailexpire <= 0)
				;
			else if (@$r->v_mailsent < $sys->now - $sys->mailexpire)
				log_die("mailexpire.");
			if (myhash($r->v_login.$key) == $r->v_mailkey) {
				if (($pass = @$_POST["pass"]) == "")
					log_die("pass empty.");
				$r->setpassword($pass);
				log_die("password change success.");
			}
			log_die("maillogin fail.");
			return 0;
		}
		
		if (($login = @$_POST["login"]) === null) {
			if (function_exists("bq_login"))
				bq_login("badlogin");
			log_die("login null.");
		}
		if (($pass = @$_POST["pass"]) === null) {
			if (function_exists("bq_login"))
				bq_login("badlogin");
			log_die("pass null.");
		}
		$list = $this->getrecordidlist("where login = ?", array($login));
		if (count($list) < 1) {
			if (function_exists("bq_login"))
				bq_login("badlogin");
			log_die("no login found");
		}
		$r = $this->getrecord($list[0]);
		$r->login($pass);
		log_die("login fail.");
	}
	function	check_maillogin() {
		global	$sys;
		
		if (@$_GET["mode"] != "1login")
			return 0;
		$uid = @$_GET["uid"] + 0;
		$key = @$_GET["key"]."";
		
		$r = $this->getrecord($uid);
		if (@$r->v_ismaillogin == 0)
			return 0;
		
		header("Location: {$sys->url}");
		
		if (@$r->v_mailkey == "")
			log_die("mailkey empty.");
		if (@$sys->mailexpire <= 0)
			;
		else if (@$r->v_mailsent < $sys->now - $sys->mailexpire)
			log_die("mailexpire.");
		if (myhash($r->v_login.$key) == $r->v_mailkey)
			$r->login("", 1);
		log_die("maillogin fail.");
	}
	function	is_login() {
		global	$loginrecord;
		
		$loginrecord = null;
		if (($sessionid = @$_COOKIE["sessionid"]) === null)
			return 0;
		if (($key = @$_COOKIE["sessionkey"]) === null)
			return 0;
		$loginrecord = $this->getrecord($sessionid);
		if (@$loginrecord->v_submitkey == "")
			;
		else if ($loginrecord->v_sessionkey == "")
			;
		else if ($loginrecord->v_sessionkey == myhash($loginrecord->v_salt.$key)) {
			execsql("begin;");
			$loginrecord = $this->getrecord($sessionid);
			return 1;
		}
		$loginrecord = null;
		return 0;
	}
	function	logout() {
		global	$loginrecord;
		global	$sys;
		global	$cookiepath;
		
		if ($loginrecord !== null) {
			if (function_exists("bq_login"))
				bq_login("logout", $loginrecord);
			$loginrecord->v_lastlogout = $sys->now;
			$loginrecord->v_sessionkey = "";
			$loginrecord->mailkey = "";
			$loginrecord->update();
			execsql("commit;");
		}
		setcookie("sessionid", "", 1, $cookiepath, "", (@$_SERVER["HTTPS"] == "on"), true);
		setcookie("sessionkey", "", 1, $cookiepath, "", (@$_SERVER["HTTPS"] == "on"), true);
		header("Location: {$sys->urlbase}/logout/".@$sys->rootpage.".html");
	}
}


class	rootrecord {
	var	$tablename = "root";
	var	$methodlist = null;
	function	getfield($s) {
		if ($s != "id")
			$s = "v_{$s}";
		return @$this->$s;
	}
	function	dumpfields($title = "") {
	}
}

$funclist = null;

class	recordholder {
	var	$remainblocks;
	var	$remaincmds;
	var	$stack;
	var	$record = null;
	var	$actioncommand = "";
	var	$whereargs;
	var	$prefix = "";
	var	$methodlist = null;
	var	$recordmethodlist = null;
	var	$coveragelist = null;
	var	$debugpoplog = "";
	var	$debugpoplist = null;
	function	__construct($rh = null, $record = null, $tablename = "", $prefix = "", $par = "") {
		$this->record = new rootrecord();
		$this->prefix = $prefix;
		$this->whereargs = array();
	}
	function	popstack($cmd, $par) {
		global	$sys;
		
		if ($par == "")
			return array();
		$ret = array();
		foreach (array_reverse(explode(" ", $par)) as $val) {
			$ret[] = $s0 = array_pop($this->stack)."";
			$this->debugpoplog = htmlspecialchars($s0).'<span style="color:#aaa;">: '.htmlspecialchars($val)."</span><br />{$this->debugpoplog}";
		}
		$a = array_reverse($ret);
		return $a;
	}
	function	pushstack($array, $tocoverage = 1) {
		global	$sys;
		
		if (($tocoverage)) {
			$this->debugpoplist[] = $this->debugpoplog;
			@$this->coveragelist[] = implode("\n", $array);
		}
		$this->debugpoplog = "";
		foreach ($array as $v)
			$this->stack[] = $v;
	}
	function	parsewhere($sql) {
		$args = array();
		$a = preg_split('/where /i', $sql, 2);
		if (count($a) < 2)
			return array($sql, array());
		
		$sql0 = $a[0]."where ";
		$sql1 = $a[1];
		foreach ($this->whereargs as $key => $a) {
			$sql0 .= "{$key} and ";
			foreach ($a as $val)
				$args[] = $val;
		}
		return array($sql0.$sql1, $args);
	}
	function	callfunc($rh, $cmd, $record = null, $issubmit = 0) {
		global	$recordholderlist;
		
		if ($rh === null) {
			list($s1) = $this->popstack($cmd, "recordholder");
			if (($rh = @$recordholderlist[$s1]) === null) {
				$this->pushstack(array());
				trigger_error($s = "no recordholder({$s1})");
				$debuglog .= "<h3>".htmlspecialchars($s)."</h3>";
				$this->pushstack(array());
				return;
			}
		}
		if ($rh->methodlist === null) {
			$rh->methodlist = array();
			foreach (get_class_methods($rh) as $name) {
				if (!preg_match('/^[a-z]s?_/', $name))
					continue;
				$a = explode("__", $name);
				$rh->methodlist[$a[0]] = $name;
			}
		}
		if (($rh->record !== null)&&($rh->record->methodlist === null)) {
			$rh->record->methodlist = array();
			foreach (get_class_methods($rh->record) as $name) {
				if (!preg_match('/^[a-z]s?_/', $name))
					continue;
				$a = explode("__", $name);
				$rh->record->methodlist[$a[0]] = $name;
			}
		}
		$cmd = "h".substr($cmd, 1);
		if (($issubmit)) {
			$cmds = "hs".substr($cmd, 1);
			if (($fn = @$rh->methodlist[$cmds]) !== null) {
				$a = explode("__", $fn);
				array_shift($a);
				$pars = $this->popstack($cmds, implode(" ", $a));
				array_unshift($pars, $this, $record);
				$this->pushstack(call_user_func_array(array($rh, $fn), $pars));
				return;
			}
			if (($rh->record !== null)&&(($fn = @$rh->record->methodlist[$cmds]) !== null)) {
				$a = explode("__", $fn);
				array_shift($a);
				$pars = $this->popstack($cmds, implode(" ", $a));
				array_unshift($pars, $this, $record);
				$this->pushstack(call_user_func_array(array($rh->record, $fn), $pars));
				return;
			}
		}
		if (($fn = @$rh->methodlist[$cmd]) !== null) {
			$a = explode("__", $fn);
			array_shift($a);
			$pars = $this->popstack($cmd, implode(" ", $a));
			array_unshift($pars, $this, $record);
			$this->pushstack(call_user_func_array(array($rh, $fn), $pars));
			return;
		}
		if (($rh->record !== null)&&(($fn = @$rh->record->methodlist[$cmd]) !== null)) {
			$a = explode("__", $fn);
			array_shift($a);
			$pars = $this->popstack($cmd, implode(" ", $a));
			array_unshift($pars, $this, $record);
			$this->pushstack(call_user_func_array(array($rh->record, $fn), $pars));
			return;
		}
		trigger_error($s = "no recordholder function({$cmd} in ".get_class($this).")");
		$debuglog .= "<h3>".htmlspecialchars($s)."</h3>";
		$this->pushstack(array());
	}
	function	callfunctable($t, $cmd, $record = null, $issubmit = 0) {
		global	$tablelist;
		
		if ($t === null) {
			list($s1) = $this->popstack($cmd, "table:t");
			if (($t = @$tablelist[$s1]) === null) {
				$this->pushstack(array());
				trigger_error($s = "no table({$s1})");
				$debuglog .= "<h3>".htmlspecialchars($s)."</h3>";
				$this->pushstack(array());
				return;
			}
		}
		if ($t->methodlist === null) {
			$t->methodlist = array();
			foreach (get_class_methods($t) as $name) {
				if (!preg_match('/^[a-z]s?_/', $name))
					continue;
				$a = explode("__", $name);
				$t->methodlist[$a[0]] = $name;
			}
		}
		$cmd = "t".substr($cmd, 1);
		if (($issubmit)) {
			$cmds = "ts".substr($cmd, 1);
			if (($fn = @$t->methodlist[$cmds]) !== null) {
				$a = explode("__", $fn);
				array_shift($a);
				$pars = $this->popstack($cmds, implode(" ", $a));
				array_unshift($pars, $this, $record);
				$this->pushstack(call_user_func_array(array($t, $fn), $pars));
				return;
			}
		}
		if (($fn = @$t->methodlist[$cmd]) !== null) {
			$a = explode("__", $fn);
			array_shift($a);
			$pars = $this->popstack($cmd, implode(" ", $a));
			array_unshift($pars, $this, $record);
			$this->pushstack(call_user_func_array(array($t, $fn), $pars));
			return;
		}
		trigger_error($s = "no table function({$cmd} in ".get_class($this).")");
		$debuglog .= "<h3>".htmlspecialchars($s)."</h3>";
		$this->pushstack(array());
	}
	function	callfuncstable($cmd, $record = null, $issubmit = 0) {
		global	$tablelist;
		
		list($s1) = $this->popstack($cmd, "stable");
		
		$t = @$tablelist["simple"]->gettable($s1);
		if ($t->methodlist === null) {
			$t->methodlist = array();
			foreach (get_class_methods($t) as $name) {
				if (!preg_match('/^[a-z]s?_/', $name))
					continue;
				$a = explode("__", $name);
				$t->methodlist[$a[0]] = $name;
			}
		}
		if (($issubmit)) {
			$cmds = "ss".substr($cmd, 1);
			if (($fn = @$t->methodlist[$cmds]) !== null) {
				$a = explode("__", $fn);
				array_shift($a);
				$pars = $this->popstack($cmds, implode(" ", $a));
				array_unshift($pars, $this, $record);
				$this->pushstack(call_user_func_array(array($t, $fn), $pars));
				return;
			}
		}
		$cmds = "s".substr($cmd, 1);
		if (($fn = @$t->methodlist[$cmd]) !== null) {
			$a = explode("__", $fn);
			array_shift($a);
			$pars = $this->popstack($cmd, implode(" ", $a));
			array_unshift($pars, $this, $record);
			$this->pushstack(call_user_func_array(array($t, $fn), $pars));
			return;
		}
		trigger_error($s = "no stable function({$cmd} in ".get_class($this).")");
		$debuglog .= "<h3>".htmlspecialchars($s)."</h3>";
		$this->pushstack(array());
	}
	function	flush_coverage() {
		global	$coverage_list;
		global	$coverage_id;
		global	$coverage_count;
		global	$debuglog;
		
		if ($coverage_list === null)
			return;
		$s = "";
		foreach ($this->coveragelist as $val)
			$s .= "\t".base64_encode($val);
		if ($s == "")
			return;
		if (@$coverage_list[$coverage_id][$s] === null)
			$coverage_list[$coverage_id][$s] = @$coverage_count[$coverage_id] + 0;
		
		$head = "";
		$pars = "";
		foreach (explode("__", $this->coveragelist[0]) as $block) {
			if (!preg_match('/^:/', $block)) {
				$pars .= "{$block}__ ";
				continue;
			}
			$remaincmds = explode(":", $block);
			array_shift($remaincmds);
			foreach ($remaincmds as $cmd) {
				$head .= '<th><span style="color: #f8f;">'.htmlspecialchars($pars);
				$pars = "";
				$head .= "</span>:".htmlspecialchars($cmd);
			}
		}
		$debuglog .= "<table border><tr>{$head}\n<tr>";
		foreach ($this->coveragelist as $key => $val)
			if ($key > 0)
				$debuglog .= "\t".'<td style="text-align: right;">'.@$this->debugpoplist[$key].nl2br(htmlspecialchars($val));
		$debuglog .= "\n</table>\n";
	}
	function	parsebq($text = "", $record = null, $issubmit = 0, $initstack = null) {
		global	$sys;
		global	$debuglog;
		global	$tablelist;
		global	$loginrecord;
		global	$invalid;
		global	$funclist;
		
		if ($record === null)
			$record = $this->record;
		
		$this->stack = array();
		if ($initstack !== null)
			$this->stack = $initstack;
		
		$this->coveragelist = array($text);
		$this->debugpoplog = "";
		$this->debugpoplist = array(null);
		
		$outputmode = "";
		$this->remainblocks = explode("__", $text);
		while (($block = array_shift($this->remainblocks)) !== null) {
			if (!preg_match('/^:/', $block)) {
				$this->pushstack(array($block), 0);
				continue;
			}
			$this->remaincmds = explode(":", $block);
			array_shift($this->remaincmds);
			while (($cmd = array_shift($this->remaincmds)) !== null) {
				switch ($cmd) {
					default:
						if (preg_match('/^h_/', $cmd)) {
							$this->callfunc(null, $cmd, $record, $issubmit);
							break;
						}
						if (preg_match('/^H_/', $cmd)) {
							$this->callfunc($this, $cmd, $record, $issubmit);
							break;
						}
						if (preg_match('/^t_/', $cmd)) {
							$this->callfunctable(null, $cmd, $record, $issubmit);
							break;
						}
						if (preg_match('/^T_/', $cmd)) {
							$this->callfunctable($this->record, $cmd, $record, $issubmit);
							break;
						}
						if (preg_match('/^s_/', $cmd)) {
							$this->callfuncstable($cmd, $record, $issubmit);
							break;
						}
						if (preg_match('/^([a-z]id)([_0-9A-Za-z]*)$/', $cmd, $a)) {
							$s = $a[1];
							if (($tablename2 = @$sys->$s) === null) {
								trigger_error($s = "no table shortcut({$cmd} in ".get_class($this).")");
								$debuglog .= "<h3>".htmlspecialchars($s)."</h3>";
								$this->pushstack(array());
								break;
							}
							list($tablename) = $this->popstack($cmd, "table");
							$id2 = 0;
							switch ($tablename) {
								default:
									list($id) = $this->popstack($cmd, "id");
									if (($t = @$tablelist[$tablename]) !== null) {
										$r = $t->getrecord($id);
										$id2 = $r->getfield($cmd);
									}
									break;
								case	"r":
									$id2 = $record->getfield($cmd);
									break;
								case	"g":
									$id2 = @$_GET[$cmd];
									break;
								case	"p":
									$id2 = @$_POST[$cmd];
									break;
								case	"l":
									if ($loginrecord !== null)
										$id2 = $loginrecord->getfield($cmd);
									break;
							}
							$id2 = round($id2 + 0);
							$this->pushstack(array($id2, $tablename2));
							break;
						}
						if (preg_match('/^r_([_0-9A-Za-z]+)$/', $cmd, $a)) {
							list($tablename) = $this->popstack($cmd, "table");
							$s = "";
							switch ($tablename) {
								default:
									list($id) = $this->popstack($cmd, "id");
									if (($t = @$tablelist[$tablename]) !== null) {
										$r = $t->getrecord($id);
										$s = $r->getfield($a[1]);
									}
									break;
								case	"r":
									$s = $record->getfield($a[1]);
									break;
								case	"l":
									if ($loginrecord !== null)
										$s = $loginrecord->getfield($a[1]);
									break;
							}
							$this->pushstack(array($s));
							break;
						}
						if (@$funclist === null) {
							$a = get_defined_functions();
							$funclist = array();
							foreach ($a["user"] as $s) {
								if (!preg_match('/^bq_/', $s))
									continue;
								$a0 = explode("__", $s);
								$funclist[$a0[0]] = $s;
							}
						}
						if (($s = @$funclist["bq_{$cmd}"]) === null) {
							trigger_error($s = "unknown command({$cmd} in ".get_class($this).")");
							$debuglog .= "<h3>".htmlspecialchars($s)."</h3>";
							$this->pushstack(array());
							break;
						}
						$a = explode("__", $s);
						array_shift($a);
						$a0 = $this->popstack($cmd, implode(" ", $a));
						$a = call_user_func_array($s, $a0);
						if (!is_array($a))
							return $a;		# avoid debuglog
						$this->pushstack($a);
						break;
#* bq
					case	"dot":
## 「.」をスタックに積みます。
## 例えば`index__:dot__html__:cat:cat`は「index.html」になります。
## 通常は、これを使う必要はありません。
						$this->popstack($cmd, "");
						$this->pushstack(array("."));
						break;
					case	"col":
## 「:」をスタックに積みます。
## 例えば`12__:col__00__:cat:cat`は「12:00」になります。
## 先頭が「:」の記述はコマンドとみなされるため、文字としての「:」を入力したい時に使用します。
						$this->popstack($cmd, "");
						$this->pushstack(array(":"));
						break;
					case	"sp":
## 「 」をスタックに積みます。
## 例えば`abc__:sp__def__:cat:cat`は「abc def」になります。
## inputタグのname中など、スペースが使えない場合に使用します。
						$this->popstack($cmd, "");
						$this->pushstack(array(" "));
						break;
					case	"bq":
## 「`」をスタックに積みます。
## 例えば`:bq`は「`」になります。
						$this->popstack($cmd, "");
						$this->pushstack(array("`"));
						break;
					case	"null":
## 空文字列(長さ0の文字列)をスタックに積みます。
						$this->popstack($cmd, "");
						$this->pushstack(array(""));
						break;
					case	"hex":
## スタックから文字列を1つ取り出して16進数の並びとみなし、文字列に変換してスタックに積みます。
## 例えば`414243__:hex`は「ABC」になります。
## 上記の方法で入力できない特殊文字を入力するのに使用します。
						list($s1) = $this->popstack($cmd, "hex");
						$s = "";
						for ($i=0; $i<strlen($s1); $i+=2)
							$s .= chr(filter_var("0x".substr($s1, $i, 2), FILTER_VALIDATE_INT, FILTER_FLAG_ALLOW_HEX));
						$this->pushstack(array($s.""));
						break;
					case	"curid":
## 現在のレコードIDをスタックに積みます。
## 例えば「<!--{tableid user 1-->」内部では、「1」がスタックに積まれます。
						$this->popstack($cmd, "");
						$s = @$this->record->id + 0;
						$this->pushstack(array($s));
						break;
					case	"curtable":
## 現在のテーブル名をスタックに積みます。
## 例えば「<!--{tableid user 1-->」内部では、「user」がスタックに積まれます。
						$this->popstack($cmd, "");
						$s = @$this->record->tablename."";
						$this->pushstack(array($s));
						break;
					case	"curpage":
## URLから得た現在のページ名をスタックに積みます。
## 例えば「g0000.html」がアクセスされたときは、「g0000」がスタックに積まれます。
						$this->popstack($cmd, "");
						$this->pushstack(array($sys->target));
						break;
					case	"ispost":
## 現在のリクエストがPOSTであれば1を、POSTでなければ空文字列をスタックに積みます。
						$this->popstack($cmd, "");
						$this->pushstack(array((ispost())? "1" : ""));
						break;
					case	"isvalid":
## バリデーションでエラーがなければ「1」を、エラーがあれば空文字列をスタックに積みます。
						$this->popstack($cmd, "");
						$this->pushstack(array(($invalid)? "" : "1"));
						break;
					case	"g":
## スタックから文字列を1つ取り出してGET名とみなし、得られたGET値をスタックに積みます。
## 例えば、URLが「?id=1」となっている場合には、`id__:g`は「1」になります。
## 「?id」が指定されていない場合は、`id__:g`は空文字列になります。
## GETでは、セキュリティ上の理由により、空文字列か数字とコンマの並びしか得ることができません。
						list($s1) = $this->popstack($cmd, "name");
						$s = @$_GET[$s1]."";
						$s = preg_replace("/[^,0-9]/", "", $s);
						$this->pushstack(array($s));
						break;
					case	"p":
## スタックから文字列を1つ取り出してPOST名とみなし、得られたPOST値をスタックに積みます。
## 例えば、<form method="post"><input name="s1"><input type="submit">で送信された値は`s1__:p`で得ることができます。
## POSTでない場合や、POST名が存在しない場合は、空文字列になります。
## POSTでは、(GETとは異なり)任意の文字列を得ることができます。
## ただしSQL中での``は、数値と(190429追加)「,」しか出力できません。
# 参照: parsewithbqinsql()
						list($s1) = $this->popstack($cmd, "name");
						if ((ispost())) {
							$postkey = $this->prefix.str_replace(array(" ", "."), "_", $s1);
							$this->pushstack(array(@$_POST[$postkey].""));
						} else
							$this->pushstack(array(""));
						break;
					case	"r":
## スタックから文字列を1つ取り出してフィールド名とみなし、現在のレコードからフィールドを取得してスタックに積みます。
## 例えば「<!--{tableid user 1-->」内部では、`id__:r`は、userテーブルのレコード1の「id」フィールドの値が得られます。
## レコードが定義されていない場合や、指定したフィールド名が存在しない場合は、空文字列になります。
						list($s1) = $this->popstack($cmd, "field");
						$s = $record->getfield($s1)."";
						$this->pushstack(array($s));
						break;
					case	"int":
## スタックから文字列を1つ取り出し、数値とみなして四捨五入し、スタックに積みます。
## 例えば`3.8__:int`は「4」になります。
						list($s1) = $this->popstack($cmd, "val");
						$this->pushstack(array(round($s1 + 0)));
						break;
					case	"isnull":
## スタックから文字列を1つ取り出し、空文字列であれば「1」を、そうでなければ空文字列をスタックに積みます。
## `id__:g:isnull:andbreak`とすると、「?id=1」のような指定がなければ、breakします。
## `:isvalid:isnull`のような形で、論理の反転に使用することもできます。
						list($s1) = $this->popstack($cmd, "val");
						$this->pushstack(array(($s1 == "")? "1" : ""));
						break;
					case	"h2z":
## スタックから文字列を1つ取り出し、半角英数字を全角英数字に変換してスタックに積みます。
						list($s1) = $this->popstack($cmd, "hankaku");
						$this->pushstack(array(mb_convert_kana($s1, "ASKV", "UTF-8")));
						break;
					case	"z2h":
## スタックから文字列を1つ取り出し、全角英数字を半角英数字に変換してスタックに積みます。
						list($s1) = $this->popstack($cmd, "zenkaku");
						$this->pushstack(array(mb_convert_kana($s1, "as", "UTF-8")));
						break;
					case	"sys":
## スタックから文字列を1つ取り出し、システム変数名とみなして、その変数値をスタックに積みます。
## 例えばenv.php内で$sys->v_limit = 100;という記述があれば、`limit__:sys`は「100」になります。
						list($s1) = $this->popstack($cmd, "name");
						$s ="v_{$s1}";
						$this->pushstack(array(@$sys->$s.""));
						break;
					case	"now":
## 現在時刻値(1970年1月1日0:00:00GMTからの秒数)を、スタックに積みます。
## 一般的な日時に変換するには、:todateを使います。
## 例えば`:now__y/m/d H:i:s__:todate`は、「19/02/10 19:52:13」などになります。
						$this->popstack($cmd, "");
						$this->pushstack(array($sys->now));
						break;
					case	"ymd2t":
## スタックから文字列を3つ取り出し、それぞれ年・月・日とみなして、現在時刻値(1970年1月1日0:00:00GMTからの秒数)をスタックに積みます。
## 一般的な日時に変換するには、:todateを使います。
						list($s1, $s2, $s3) = $this->popstack($cmd, "year month day");
						$t = mktime(0, 0, 0, $s2, $s3, $s1);
						$this->pushstack(array($t));
						break;
					case	"age2t":
## スタックから文字列を1つ取り出し、年数とみなして、現在時刻値(1970年1月1日0:00:00GMTからの秒数)から年数を引いた値を、スタックに積みます。
## 一般的な日時に変換するには、:todateを使います。
						list($s1) = $this->popstack($cmd, "year");
						$t = mktime(date("H", $sys->now), date("i", $sys->now), date("s", $sys->now), date("n", $sys->now), date("j", $sys->now), date("Y", $sys->now) - $s1);
						$this->pushstack(array($t));
						break;
					case	"todate":
## スタックから、時刻値(1970年1月1日0:00:00GMTからの秒数)と、書式文字列を取り出し、時刻を書式にあてはめた文字列をスタックに積みます。
## 書式文字列は、phpのdate()関数のものが使えます。
## 現在時刻値を得るには、:nowを使います。
## 例えば`:now__y/m/d H:i:s__:todate`は、「19/02/10 19:52:13」などになります。
						list($s1, $s2) = $this->popstack($cmd, "time dateformat");
						$this->pushstack(array(date($s2, $s1 + 0)));
						break;
					case	"html":
## 出力をHTMLエスケープすることを示します。
## スタックは変化しませんので、どこに置いても構いません。
## 例えば`:html__<`も、`<__:html`も、どちらも「&lt;」になります。
					case	"url":
## 出力をrawurlエンコードすることを示します。
## スタックは変化しませんので、どこに置いても構いません。
## 例えば`:url__<`も、`<__:url`も、どちらも「%3C」になります。
					case	"js":
## 出力を、JavaScriptに直接埋め込める形の文字列に変換することを示します。
## スタックは変化しませんので、どこに置いても構いません。
## 具体的には、例えば`:js__<`や、`<__:js`は、どちらも「decodeURIComponent("%3C")」になります。
## また、`:js__A`や、`A__:js`は、どちらも「"A"」になります。
## このため、JavaScript内で「var a = `name__:r:js`;」のように、引用符をつけずに扱うことができます。
## また、すべてをrawurlエンコードする場合よりも、出力を少なくすることが可能です。
					case	"raw":
						$outputmode = $cmd;
						$this->pushstack(array());
						break;
					case	"cat":
## スタックから、文字列を2つ取り出して、結合し、それをスタックに積みます。
## 例えば`a__b__:cat`は「ab」になります。
						list($s1, $s2) = $this->popstack($cmd, "s0 s1");
						$this->pushstack(array($s1.$s2));
						break;
					case	"rcat":
## スタックから、文字列を2つ取り出して、逆順に結合し、それをスタックに積みます。
## 例えば`a__b__:rcat`は「ba」になります。
						list($s1, $s2) = $this->popstack($cmd, "s0 s1");
						$this->pushstack(array($s2.$s1));
						break;
					case	"scat":
## スタックから、文字列を2つ取り出して、結合し、それをスタックに積みます。
## 例えば`a__b__:scat`は「a b」になります。
						list($s1, $s2) = $this->popstack($cmd, "s0 s1");
						$this->pushstack(array("{$s1} {$s2}"));
						break;
					case	"rscat":
## スタックから、文字列を2つ取り出して、逆順に結合し、それをスタックに積みます。
## 例えば`a__b__:rscat`は「b a」になります。
						list($s1, $s2) = $this->popstack($cmd, "s0 s1");
						$this->pushstack(array("{$s2} {$s1}"));
						break;
					case	"ismatch":
## スタックから、文字列を2つ取り出し、1番目の文字列の中に2番目の文字列があれば「1」を、なければ空文字列をスタックに積みます。
## 例えば`abc__b__:match`は、「abc」の中に「b」があるので、「1」になります。
## 逆に、`abc__cb__:match`は、「abc」の中に「cb」がないので、空文字列になります。
						list($s1, $s2) = $this->popstack($cmd, "s0 s1");
						$this->pushstack(array((strpos($s1, $s2) !== FALSE)? 1 : ""));
						break;
					case	"addzero":
## スタックから文字列を2つ取り出し、2番目の文字数で指定された長さになるまで、1番目の文字列の先頭に「0」を付加したものをスタックに積みます。
## 例えば`123__6__:addzero`は、「000123」になります。
## また、`123__2__:addzero`は、「123」になります。
## なお、数値として扱うわけではありませんので、`-123__6__:addzero`は「00-123」になります。
						list($s, $v) = $this->popstack($cmd, "num digits");
						if (($i = strlen($s)) < $v + 0)
							$s = str_repeat("0", $v - $i).$s;
						$this->pushstack(array($s));
						break;
					case	"add":
## スタックから文字列を2つ取り出し、それぞれを数値とみなして加算したものをスタックに積みます。
## たとえば`123__456__:add`は「579」になります。
						list($s1, $s2) = $this->popstack($cmd, "i j");
						$this->pushstack(array($s1 + $s2));
						break;
					case	"sub":
## スタックから文字列を2つ取り出し、それぞれを数値とみなして減算したものをスタックに積みます。
## たとえば`123__456__:sub`は「-333」になります。
						list($s1, $s2) = $this->popstack($cmd, "i j");
						$this->pushstack(array($s1 - $s2));
						break;
					case	"rsub":
## スタックから文字列を2つ取り出し、それぞれを数値とみなして逆順に減算したものをスタックに積みます。
## たとえば`123__456__:rsub`は「333」になります。
						list($s1, $s2) = $this->popstack($cmd, "i j");
						$this->pushstack(array($s2 - $s1));
						break;
					case	"mul":
## スタックから文字列を2つ取り出し、それぞれを数値とみなして積算したものをスタックに積みます。
## たとえば`123__456__:mul`は「56088」になります。
						list($s1, $s2) = $this->popstack($cmd, "i j");
						$this->pushstack(array($s1 * $s2));
						break;
					case	"div":
## スタックから文字列を2つ取り出し、それぞれを数値とみなして除算して切り捨てたものをスタックに積みます。
## たとえば`123__456__:div`は「0」になります。
## 除数が0の場合は、0になります。
						list($s1, $s2) = $this->popstack($cmd, "i j");
						if ($s2 == 0)
							$this->pushstack(array(0));
						else
							$this->pushstack(array(floor($s1 / $s2)));
						break;
					case	"rdiv":
## スタックから文字列を2つ取り出し、それぞれを数値とみなして逆順に除算して切り捨てたものをスタックに積みます。
## たとえば`123__456__:rdiv`は「3」になります。
## 除数が0の場合は、0になります。
						list($s1, $s2) = $this->popstack($cmd, "i j");
						if ($s1 == 0)
							$this->pushstack(array(0));
						else
							$this->pushstack(array(floor($s2 / $s1)));
						break;
					case	"mod":
## スタックから文字列を2つ取り出し、それぞれを数値とみなして除算した剰余をスタックに積みます。
## たとえば`123__456__:mod`は「123」になります。
## 除数が0の場合は、0になります。
						list($s1, $s2) = $this->popstack($cmd, "i j");
						if ($s2 == 0)
							$this->pushstack(array(0));
						else
							$this->pushstack(array($s1 % $s2));
						break;
					case	"rmod":
## スタックから文字列を2つ取り出し、それぞれを数値とみなして逆順に除算した剰余をスタックに積みます。
## たとえば`123__456__:rmod`は「87」になります。
## 除数が0の場合は、0になります。
						list($s1, $s2) = $this->popstack($cmd, "i j");
						if ($s1 == 0)
							$this->pushstack(array(0));
						else
							$this->pushstack(array($s2 % $s1));
						break;
					case	"eq":
						list($s1, $s2) = $this->popstack($cmd, "i j");
						$this->pushstack(array(($s1 == $s2)? 1 : ""));
						break;
					case	"ieq":
## スタックから文字列を2つ取り出し、それぞれを数値とみなして、等しければ「1」を、等しくなければ空文字列をスタックに積みます。
## 例えば`1__1__:ieq`や`1__01__:ieq`や`0__ __:ieq`や`0x1__1__:ieq`は「1」になります。
						list($s1, $s2) = $this->popstack($cmd, "i j");
						$this->pushstack(array(($s1 + 0 == $s2 + 0)? 1 : ""));
						break;
					case	"seq":
## スタックから文字列を2つ取り出し、それぞれを文字列とみなして、等しければ「1」を、等しくなければ空文字列をスタックに積みます。
## 例えば`1__1__:seq`は「1」になります。
## `1__01__:seq`や`0__ __:seq`は空文字列になります。
						list($s1, $s2) = $this->popstack($cmd, "i j");
						$this->pushstack(array(($s1."" === $s2."")? 1 : ""));
						break;
					case	"ne":
						list($s1, $s2) = $this->popstack($cmd, "i j");
						$this->pushstack(array(($s1 != $s2)? 1 : ""));
						break;
					case	"ine":
## スタックから文字列を2つ取り出し、それぞれを数値とみなして、等しくなければ「1」を、等しければ空文字列をスタックに積みます。
## 例えば`1__1__:ieq`や`1__01__:ieq`や`0__ __:ieq`や`0x1__1__:ieq`は空文字列になります。
						list($s1, $s2) = $this->popstack($cmd, "i j");
						$this->pushstack(array(($s1 + 0 != $s2 + 0)? 1 : ""));
						break;
					case	"sne":
## スタックから文字列を2つ取り出し、それぞれを文字列とみなして、等しくなければ「1」を、等しければ空文字列をスタックに積みます。
## 例えば`1__1__:seq`は空文字列になります。
## `1__01__:seq`や`0__ __:seq`は「1」になります。
						list($s1, $s2) = $this->popstack($cmd, "i j");
						$this->pushstack(array(($s1."" !== $s2."")? 1 : ""));
						break;
					case	"lt":
						list($s1, $s2) = $this->popstack($cmd, "i j");
						$this->pushstack(array(($s1 < $s2)? 1 : ""));
						break;
					case	"ilt":
## スタックから文字列を2つ取り出し、それぞれを数値とみなして、2番目が大きければ「1」を、そうでなければ空文字列をスタックに積みます。
## 例えば`1__2__:ilt`は「1」になります。
## `1__1__:ilt`や`1__0__:ilt`は空文字列になります。
						list($s1, $s2) = $this->popstack($cmd, "i j");
						$this->pushstack(array(($s1 + 0 < $s2 + 0)? 1 : ""));
						break;
					case	"slt":
## スタックから文字列を2つ取り出し、それぞれを文字列とみなして、2番目が大きければ「1」を、そうでなければ空文字列をスタックに積みます。
## 例えば`1__2__:slt`は「1」になります。
## `1__1__:slt`や`1__0__:slt`は空文字列になります。
						list($s1, $s2) = $this->popstack($cmd, "i j");
						$this->pushstack(array(("_".$s1 < "_".$s2)? 1 : ""));
						break;
					case	"gt":
						list($s1, $s2) = $this->popstack($cmd, "i j");
						$this->pushstack(array(($s1 > $s2)? 1 : ""));
						break;
					case	"igt":
## スタックから文字列を2つ取り出し、それぞれを数値とみなして、1番目が大きければ「1」を、そうでなければ空文字列をスタックに積みます。
## 例えば`1__0__:igt`は「1」になります。
## `1__1__:igt`や`1__2__:igt`は空文字列になります。
						list($s1, $s2) = $this->popstack($cmd, "i j");
						$this->pushstack(array(($s1 + 0 > $s2 + 0)? 1 : ""));
						break;
					case	"sgt":
## スタックから文字列を2つ取り出し、それぞれを文字列とみなして、1番目が大きければ「1」を、そうでなければ空文字列をスタックに積みます。
## 例えば`1__0__:sgt`は「1」になります。
## `1__1__:sgt`や`1__2__:sgt`は空文字列になります。
						list($s1, $s2) = $this->popstack($cmd, "i j");
						$this->pushstack(array(("_".$s1 > "_".$s2)? 1 : ""));
						break;
					case	"le":
						list($s1, $s2) = $this->popstack($cmd, "i j");
						$this->pushstack(array(($s1 <= $s2)? 1 : ""));
						break;
					case	"ile":
## スタックから文字列を2つ取り出し、それぞれを数値とみなして、等しいか2番目が大きければ「1」を、そうでなければ空文字列をスタックに積みます。
## 例えば`1__2__:ile`や`1__1__:ile`は「1」になります。
## `1__0__:ile`は空文字列になります。
						list($s1, $s2) = $this->popstack($cmd, "i j");
						$this->pushstack(array(($s1 + 0 <= $s2 + 0)? 1 : ""));
						break;
					case	"sle":
## スタックから文字列を2つ取り出し、それぞれを文字列とみなして、等しいか2番目が大きければ「1」を、そうでなければ空文字列をスタックに積みます。
## 例えば`1__2__:sle`や`1__1__:sle`は「1」になります。
## `1__0__:sle`は空文字列になります。
						list($s1, $s2) = $this->popstack($cmd, "i j");
						$this->pushstack(array(("_".$s1 <= "_".$s2)? 1 : ""));
						break;
					case	"ge":
						list($s1, $s2) = $this->popstack($cmd, "i j");
						$this->pushstack(array(($s1 >= $s2)? 1 : ""));
						break;
					case	"ige":
## スタックから文字列を2つ取り出し、それぞれを数値とみなして、等しいか1番目が大きければ「1」を、そうでなければ空文字列をスタックに積みます。
## 例えば`1__0__:ige`や`1__1__:ige`は「1」になります。
## `1__2__:ige`は空文字列になります。
						list($s1, $s2) = $this->popstack($cmd, "i j");
						$this->pushstack(array(($s1 + 0 >= $s2 + 0)? 1 : ""));
						break;
					case	"sge":
## スタックから文字列を2つ取り出し、それぞれを文字列とみなして、等しいか1番目が大きければ「1」を、そうでなければ空文字列をスタックに積みます。
## 例えば`1__0__:sge`や`1__1__:sge`は「1」になります。
## `1__2__:sge`は空文字列になります。
						list($s1, $s2) = $this->popstack($cmd, "i j");
						$this->pushstack(array(("_".$s1 >= "_".$s2)? 1 : ""));
						break;
					case	"dup":
## スタックから文字列を1つ取り出し、同じものを2つスタックに積みます。
## これは例えば、`id__:g:dup:isnull:andbreak__table__field__:tableid`のような使い方で、一度取得した「id__:g」を、「:isnull」と「:tableid」の両方で使いたい場合等に使用します。
						$this->popstack($cmd, "");
						$s1 = $this->stack[count($this->stack) - 1];
						$this->pushstack(array($s1));
						break;
					case	"andbreak":
## スタックから文字列を1つ取り出し、空文字列か「0」ならばそのまま継続し、そうでなければそこで式の評価を終了して空文字列を返します。
## 例えば`0__:andbreak__a`は「a」になります。
## 一方、`1__:andbreak__a`は空文字列になります(__a以降は評価されません)。
## これは例えば、`id__:g:dup:isnull:andbreak__table__field__:tableid`のような使い方で、「id__:g」が空文字列であったら、そこで評価を終了するのに使われます。
						list($s1) = $this->popstack($cmd, "val");
						if ($s1 != 0) {
							$this->pushstack(array("BREAK"));
							$this->flush_coverage();
							return "";
						}
						$this->pushstack(array());
						break;
					case	"iandbreak":
## スタックの文字列が、空文字列か「0」ならばそのまま継続し、そうでなければそこで式の評価を終了して空文字列を返します。
## 例えば`0__:iandbreak`は「0」になります。
## 一方、`1__:iandbreak`は空文字列になります(以降は評価されません)。
## これは例えば、`id__:g:dup:isnull:andbreak__table__field__:tableid`のような使い方で、「id__:g」が空文字列であったら、そこで評価を終了するのに使われます。
						list($s1) = $this->popstack($cmd, "val");
						if ($s1 != 0) {
							$this->pushstack(array("BREAK"));
							$this->flush_coverage();
							return "";
						}
						$this->pushstack(array());
						break;
					case	"orbreak":
## スタックから文字列を1つ取り出し、空文字列か「0」ならばそこで式の評価を終了して空文字列を返し、そうでなければそのまま継続します。
## 例えば`1__:orbreak__a`は「a」になります。
## 一方、`0__:orbreak__a`は空文字列になります(__a以降は評価されません)。
## これは例えば、`id__:g:int:dup:orbreak__id__:set`のような使い方で、「id__:g:int」が0か空文字列であったら、そこで評価を終了するのに使われます。
						list($s1) = $this->popstack($cmd, "val");
						if ($s1 == 0) {
							$this->pushstack(array("BREAK"));
							$this->flush_coverage();
							return "";
						}
						$this->pushstack(array());
						break;
					case	"iorbreak":
## スタックの文字列が、空文字列か「0」ならばそこで式の評価を終了して空文字列を返し、そうでなければそのまま継続します。
## 例えば`1__:iorbreak`は「1」になります。
## 一方、`0__:iorbreak`は空文字列になります(以降は評価されません)。
## これは例えば、`id__:g:int:dup:orbreak__id__:set`のような使い方で、「id__:g:int」が0か空文字列であったら、そこで評価を終了するのに使われます。
						list($s1) = $this->popstack($cmd, "val");
						if ($s1 == 0) {
							$this->pushstack(array("BREAK"));
							$this->flush_coverage();
							return "";
						}
						$this->pushstack(array());
						break;
					case	"break":
## そこで式の評価を終了して空文字列を返します。
						$this->popstack($cmd, "");
						$this->pushstack(array("BREAK"));
						$this->flush_coverage();
						return "";
					case	"andreturn0":
## スタックから文字列を1つ取り出し、空文字列か「0」ならばそのまま継続し、そうでなければそこで式の評価を終了して「0」を返します。
## 例えば`0__:andreturn0__a`は「a」になります。
## 一方、`1__:andreturn0__a`は「0」になります(__a以降は評価されません)。
					case	"andreturn1":
## スタックから文字列を1つ取り出し、空文字列か「0」ならばそのまま継続し、そうでなければそこで式の評価を終了して「1」を返します。
## 例えば`0__:andreturn1__a`は「a」になります。
## 一方、`1__:andreturn1__a`は「1」になります(__a以降は評価されません)。
					case	"andreturn2":
## スタックから文字列を1つ取り出し、空文字列か「0」ならばそのまま継続し、そうでなければそこで式の評価を終了して「2」を返します。
## 例えば`0__:andreturn2__a`は「a」になります。
## 一方、`1__:andreturn2__a`は「2」になります(__a以降は評価されません)。
					case	"andreturn3":
## スタックから文字列を1つ取り出し、空文字列か「0」ならばそのまま継続し、そうでなければそこで式の評価を終了して「3」を返します。
## 例えば`0__:andreturn3__a`は「a」になります。
## 一方、`1__:andreturn3__a`は「3」になります(__a以降は評価されません)。
					case	"andreturn4":
## スタックから文字列を1つ取り出し、空文字列か「0」ならばそのまま継続し、そうでなければそこで式の評価を終了して「4」を返します。
## 例えば`0__:andreturn4__a`は「a」になります。
## 一方、`1__:andreturn4__a`は「4」になります(__a以降は評価されません)。
						list($s1) = $this->popstack($cmd, "val");
						if ($s1 != 0) {
							$val = substr($cmd, -1) + 0;
							$this->pushstack(array($val));
							$this->flush_coverage();
							return $val;
						} else
							$this->pushstack(array());
						break;
					case	"orreturn0":
## スタックから文字列を1つ取り出し、空文字列か「0」ならばそこで式の評価を終了して「0」を返し、そうでなければそのまま継続します。
## 例えば`1__:orreturn0__a`は「a」になります。
## 一方、`0__:orreturn0__a`は「0」になります(__a以降は評価されません)。
					case	"orreturn1":
## スタックから文字列を1つ取り出し、空文字列か「0」ならばそこで式の評価を終了して「1」を返し、そうでなければそのまま継続します。
## 例えば`1__:orreturn1__a`は「a」になります。
## 一方、`0__:orreturn1__a`は「1」になります(__a以降は評価されません)。
					case	"orreturn2":
## スタックから文字列を1つ取り出し、空文字列か「0」ならばそこで式の評価を終了して「2」を返し、そうでなければそのまま継続します。
## 例えば`1__:orreturn2__a`は「a」になります。
## 一方、`0__:orreturn2__a`は「2」になります(__a以降は評価されません)。
					case	"orreturn3":
## スタックから文字列を1つ取り出し、空文字列か「0」ならばそこで式の評価を終了して「3」を返し、そうでなければそのまま継続します。
## 例えば`1__:orreturn3__a`は「a」になります。
## 一方、`0__:orreturn3__a`は「3」になります(__a以降は評価されません)。
					case	"orreturn4":
## スタックから文字列を1つ取り出し、空文字列か「0」ならばそこで式の評価を終了して「4」を返し、そうでなければそのまま継続します。
## 例えば`1__:orreturn4__a`は「a」になります。
## 一方、`0__:orreturn4__a`は「4」になります(__a以降は評価されません)。
						list($s1) = $this->popstack($cmd, "val");
						if ($s1 == 0) {
							$val = substr($cmd, -1) + 0;
							$this->pushstack(array($val));
							$this->flush_coverage();
							return $val;
						} else
							$this->pushstack(array());
						break;
					case	"home":
## 式の評価を終了してルートページにリダイレクトします。
						$this->popstack($cmd, "");
						$this->pushstack(array("HOME"));
						$this->flush_coverage();
						header("Location: {$sys->urlbase}/nofmt/{$sys->rootpage}.html");
						log_die();
					case	"andhome":
## スタックから文字列を1つ取り出し、空文字列か「0」ならばそのまま継続し、そうでなければそこで式の評価を終了してルートページにリダイレクトします。
## 例えば`0__:andhome__a`は「a」になります。
## 一方、`1__:andhome__a`はルートページにリダイレクトします(__a以降は評価されません)。
						list($s1) = $this->popstack($cmd, "val");
						if ($s1 != 0) {
							$this->pushstack(array("HOME"));
							$this->flush_coverage();
							header("Location: {$sys->urlbase}/nofmt/{$sys->rootpage}.html");
							log_die();
						} else
							$this->pushstack(array());
						break;
					case	"iandhome":
## スタックの文字列が、空文字列か「0」ならばそのまま継続し、そうでなければそこで式の評価を終了してルートページにリダイレクトします。
## 例えば`0__:iandhome`は「0」になります。
## 一方、`1__:iandhome`はルートページにリダイレクトします(以降は評価されません)。
						list($s1) = $this->popstack($cmd, "val");
						if ($s1 != 0) {
							$this->pushstack(array("HOME"));
							$this->flush_coverage();
							header("Location: {$sys->urlbase}/nofmt/{$sys->rootpage}.html");
							log_die();
						} else
							$this->pushstack(array($s1));
						break;
					case	"orhome":
## スタックから文字列を1つ取り出し、空文字列か「0」ならばそこで式の評価を終了してルートページにリダイレクトし、そうでなければそのまま継続します。
## 例えば`1__:orhome__a`は「a」になります。
## 一方、`0__:orhome__a`はルートページにリダイレクトします(__a以降は評価されません)。
						list($s1) = $this->popstack($cmd, "val");
						if ($s1 == 0) {
							$this->pushstack(array("HOME"));
							$this->flush_coverage();
							header("Location: {$sys->urlbase}/nofmt/{$sys->rootpage}.html");
							log_die();
						} else
							$this->pushstack(array());
						break;
					case	"iorhome":
## スタックの文字列が、空文字列か「0」ならばそこで式の評価を終了してルートページにリダイレクトし、そうでなければそのまま継続します。
## 例えば`1__:iorhome`は「1」になります。
## 一方、`0__:iorhome`はルートページにリダイレクトします(以降は評価されません)。
						list($s1) = $this->popstack($cmd, "val");
						if ($s1 == 0) {
							$this->pushstack(array("HOME"));
							$this->flush_coverage();
							header("Location: {$sys->urlbase}/nofmt/{$sys->rootpage}.html");
							log_die();
						} else
							$this->pushstack(array($s1));
						break;
					case	"pop":
## スタックから文字列を1つ取り出して、捨てます。
## これは例えば:h_updateなどで、得られたレコードIDを使わない場合に使用します。
						$this->popstack($cmd, "drop");
						$this->pushstack(array());
						break;
					case	"authwithpass":
					case	"reportbody":
					case	"popall":
## スタックをすべて捨てて空にします。
						$this->popstack($cmd, "");
						$this->stack = array();
						$this->pushstack(array());
						break;
					case	"jump":
## スタックから全要素を取り出し、URLに変換して、そのURLにジャンプします。
## 例えば`a0__:jump`は「a0.html」にジャンプします。
## また、`a0__id__1__:jump`は「a0.html?id=1」にジャンプします。
## さらに、`a0__id__1__mode__new__:jump`は「a0.html?id=1&mode=new」にジャンプします。
## これは必要なだけ続けることができます。
						$this->popstack($cmd, "");
						$s = "";
						foreach ($this->stack as $k3 => $v3) {
							if ($k3 == 0)
								$s .= "{$sys->urlbase}/{$sys->debugfn}/{$v3}.html";
							else if ($k3 == 1)
								$s .= "?{$v3}";
							else if (($k3 & 1))
								$s .= "&{$v3}";
							else
								$s .= "={$v3}";
						}
						$this->pushstack(array($s));
						$this->flush_coverage();
						header("Location: {$s}");
						log_die();
					case	"sor":
## スタックから文字列を2つ取り出し、2番目の文字列が空文字列なら1番目の文字列を、そうでなければ2番目の文字列をスタックに積みます。
## 例えば`empty__name__:g:sor`は、`name__:g`が空文字列なら「empty」に、そうでなければ`name__:g`になります。
						list($s1, $s2) = $this->popstack($cmd, "i j");
						if ($s2 == "")
							$s2 = $s1;
						$this->pushstack(array($s2));
						break;
					case	"sand":
						list($s1, $s2) = $this->popstack($cmd, "i j");
						if ($s2 != "")
							$s2 = $s1;
						$this->pushstack(array($s2));
						break;
					case	"ior":
## スタックから文字列を2つ取り出し、それぞれを数値とみなして、2番目の数値が0なら1番目の数値を、そうでなければ2番目の数値をスタックに積みます。
## 例えば`2__1__:ior`は「1」に、`2__0__:ior`は「2」になります。
						list($s1, $s2) = $this->popstack($cmd, "i j");
						if ($s2 == 0)
							$s2 = $s1;
						$this->pushstack(array($s2 + 0));
						break;
					case	"iand":
						list($s1, $s2) = $this->popstack($cmd, "i j");
						if ($s2 != 0)
							$s2 = $s1;
						$this->pushstack(array($s2 + 0));
						break;
					case	"loginrecord":
## スタックから文字列を1つ取り出してフィールド名とみなし、このセッションのログインユーザーに対応する、ログインテーブルのレコードのフィールド値をスタックに積みます。
## このセッションでログインがおこなわれていない場合は、空文字列になります。
## 例えば`login__:loginrecord`は、このセッションのログイン名になります。
						list($s1) = $this->popstack($cmd, "field");
						$s = "";
						if ($loginrecord !== null)
							$s = $loginrecord->getfield($s1)."";
						$this->pushstack(array($s));
						break;
					case	"set":
## スタックから文字列を2つ取り出して、それぞれフィールド値とフィールド名とみなし、カレントレコードに設定します。
## 例えば`1__id__:set`は、カレントレコードのIDに1を設定します。
						list($s1, $s2) = $this->popstack($cmd, "val field");
						if ($s2 == "id")
							$s3 = $s2;
						else
							$s3 = "v_{$s2}";
						$this->record->$s3 = $s1;
						$this->pushstack(array());
						break;
					case	"sqlisnull":
## スタックから文字列を1つ取り出して、フィールド名とみなし、対応するSQL文に「and フィールド名 is null」を追加します。
## これは、「<!--{tablegrid」のパラメータ部と「<!--}--」までの区間、そして「<!--{selectrows」のパラメータ部で使用することができます。
						list($s1) = $this->popstack($cmd, "field");
						$this->whereargs["{$s1} is null"] = array();
						$this->pushstack(array());
						break;
					case	"sqlisnotnull":
## スタックから文字列を1つ取り出して、フィールド名とみなし、対応するSQL文に「and フィールド名 is not null」を追加します。
## これは、「<!--{tablegrid」のパラメータ部と「<!--}--」までの区間、そして「<!--{selectrows」のパラメータ部で使用することができます。
						list($s1) = $this->popstack($cmd, "field");
						$this->whereargs["{$s1} is not null"] = array();
						$this->pushstack(array());
						break;
					case	"sqlisempty":
## スタックから文字列を1つ取り出して、フィールド名とみなし、対応するSQL文に「and (フィールド名 is null or フィールド名 = "")」を追加します。
## これは、「<!--{tablegrid」のパラメータ部と「<!--}--」までの区間、そして「<!--{selectrows」のパラメータ部で使用することができます。
						list($s1) = $this->popstack($cmd, "field");
						$this->whereargs["({$s1} is null or {$s1} = ?)"] = array("");
						$this->pushstack(array());
						break;
					case	"sqlisnotempty":
## スタックから文字列を1つ取り出して、フィールド名とみなし、対応するSQL文に「and フィールド名 is not null and フィールド名 <> ""」を追加します。
## これは、「<!--{tablegrid」のパラメータ部と「<!--}--」までの区間、そして「<!--{selectrows」のパラメータ部で使用することができます。
						list($s1) = $this->popstack($cmd, "field");
						$this->whereargs["{$s1} is not null"] = array();
						$this->whereargs["{$s1} <> ?"] = array("");
						$this->pushstack(array());
						break;
					case	"sqllike":
## スタックから文字列を2つ取り出して、それぞれを検索文字列とフィールド名とみなし、対応するSQL文に「and フィールド名 like "%検索文字列%"」を追加します。
## これは、「<!--{tablegrid」のパラメータ部と「<!--}--」までの区間、そして「<!--{selectrows」のパラメータ部で使用することができます。
						list($s1, $s2) = $this->popstack($cmd, "val field");
						$this->whereargs["{$s2} like ?"] = array("%{$s1}%");
						$this->pushstack(array());
						break;
					case	"sqllike2":
## スタックから文字列を3つ取り出して、それぞれを検索文字列、フィールド名1、フィールド名2とみなし、対応するSQL文に「and (フィールド名1 like "%検索文字列%" or フィールド名2 like "%検索文字列%")」を追加します。
## これは、「<!--{tablegrid」のパラメータ部と「<!--}--」までの区間、そして「<!--{selectrows」のパラメータ部で使用することができます。
						list($s1, $s2, $s3) = $this->popstack($cmd, "val field1 field2");
						$this->whereargs["({$s2} like ? or {$s3} like ?)"] = array("%{$s1}%", "%{$s1}%");
						$this->pushstack(array());
						break;
					case	"sqllike3":
## スタックから文字列を4つ取り出して、それぞれを検索文字列、フィールド名1、フィールド名2、フィールド名3とみなし、対応するSQL文に「and (フィールド名1 like "%検索文字列%" or フィールド名2 like "%検索文字列%" or フィールド名3 like "%検索文字列%")」を追加します。
## これは、「<!--{tablegrid」のパラメータ部と「<!--}--」までの区間、そして「<!--{selectrows」のパラメータ部で使用することができます。
						list($s1, $s2, $s3, $s4) = $this->popstack($cmd, "val field1 field2 field3");
						$this->whereargs["({$s2} like ? or {$s3} like ? or {$s4} like ?)"] = array("%{$s1}%", "%{$s1}%", "%{$s1}%");
						$this->pushstack(array());
						break;
					case	"sqllike4":
## スタックから文字列を5つ取り出して、それぞれを検索文字列、フィールド名1、フィールド名2、フィールド名3、フィールド名4とみなし、対応するSQL文に「and (フィールド名1 like "%検索文字列%" or フィールド名2 like "%検索文字列%" or フィールド名3 like "%検索文字列%" or フィールド名4 like "%検索文字列")」を追加します。
## これは、「<!--{tablegrid」のパラメータ部と「<!--}--」までの区間、そして「<!--{selectrows」のパラメータ部で使用することができます。
						list($s1, $s2, $s3, $s4, $s5) = $this->popstack($cmd, "val field1 field2 field3 field4");
						$this->whereargs["({$s2} like ? or {$s3} like ? or {$s4} like ? or {$s5} like ?)"] = array("%{$s1}%", "%{$s1}%", "%{$s1}%", "%{$s1}%");
						$this->pushstack(array());
						break;
					case	"sqlnotlike":
## スタックから文字列を2つ取り出して、それぞれを検索文字列とフィールド名とみなし、対応するSQL文に「and フィールド名 not like "%検索文字列%"」を追加します。
## これは、「<!--{tablegrid」のパラメータ部と「<!--}--」までの区間、そして「<!--{selectrows」のパラメータ部で使用することができます。
						list($s1, $s2) = $this->popstack($cmd, "val field");
						$this->whereargs["{$s2} not like ?"] = array("%{$s1}%");
						$this->pushstack(array());
						break;
					case	"sqleq":
## スタックから文字列を2つ取り出して、それぞれを文字列とフィールド名とみなし、対応するSQL文に「and "文字列" = フィールド名」を追加します。
## これは、「<!--{tablegrid」のパラメータ部と「<!--}--」までの区間、そして「<!--{selectrows」のパラメータ部で使用することができます。
						list($s1, $s2) = $this->popstack($cmd, "val field");
						$this->whereargs["? = {$s2}"] = array($s1);
						$this->pushstack(array());
						break;
					case	"sqlne":
## スタックから文字列を2つ取り出して、それぞれを文字列とフィールド名とみなし、対応するSQL文に「and "文字列" <> フィールド名」を追加します。
## これは、「<!--{tablegrid」のパラメータ部と「<!--}--」までの区間、そして「<!--{selectrows」のパラメータ部で使用することができます。
						list($s1, $s2) = $this->popstack($cmd, "val field");
						$this->whereargs["? <> {$s2}"] = array($s1);
						$this->pushstack(array());
						break;
					case	"sqllt":
## スタックから文字列を2つ取り出して、それぞれを文字列とフィールド名とみなし、対応するSQL文に「and "文字列" < フィールド名」を追加します。
## これは、「<!--{tablegrid」のパラメータ部と「<!--}--」までの区間、そして「<!--{selectrows」のパラメータ部で使用することができます。
						list($s1, $s2) = $this->popstack($cmd, "val field");
						$this->whereargs["? < {$s2}"] = array($s1);
						$this->pushstack(array());
						break;
					case	"sqlle":
## スタックから文字列を2つ取り出して、それぞれを文字列とフィールド名とみなし、対応するSQL文に「and "文字列" <= フィールド名」を追加します。
## これは、「<!--{tablegrid」のパラメータ部と「<!--}--」までの区間、そして「<!--{selectrows」のパラメータ部で使用することができます。
						list($s1, $s2) = $this->popstack($cmd, "val field");
						$this->whereargs["? <= {$s2}"] = array($s1);
						$this->pushstack(array());
						break;
					case	"sqlgt":
## スタックから文字列を2つ取り出して、それぞれを文字列とフィールド名とみなし、対応するSQL文に「and "文字列" > フィールド名」を追加します。
## これは、「<!--{tablegrid」のパラメータ部と「<!--}--」までの区間、そして「<!--{selectrows」のパラメータ部で使用することができます。
						list($s1, $s2) = $this->popstack($cmd, "val field");
						$this->whereargs["? > {$s2}"] = array($s1);
						$this->pushstack(array());
						break;
					case	"sqlge":
## スタックから文字列を2つ取り出して、それぞれを文字列とフィールド名とみなし、対応するSQL文に「and "文字列" >= フィールド名」を追加します。
## これは、「<!--{tablegrid」のパラメータ部と「<!--}--」までの区間、そして「<!--{selectrows」のパラメータ部で使用することができます。
						list($s1, $s2) = $this->popstack($cmd, "val field");
						$this->whereargs["? >= {$s2}"] = array($s1);
						$this->pushstack(array());
						break;
# not work without submitkey.
#					case	"login":
#						$tablelist["login"]->check_loginform();
#						log_die();
					case	"logout":
						$tablelist["login"]->logout();
						log_die();
				}
			}
		}
		$this->flush_coverage();
		$output = "";
		$s = @$this->stack[0]."";
		switch ($outputmode) {
			default:
			case	"html":
				$output .= htmlspecialchars($s, ENT_QUOTES);
				break;
			case	"url":
				$output .= rawurlencode($s);
				break;
			case	"js":
				if (($s2 = rawurlencode($s)) == $s)
					$s2 = '"'.$s.'"';
				else
					$s2 = 'decodeURIComponent("'.$s2.'")';
				$output .= $s2;
				break;
			case	"raw":
				$output .= $s;
				break;
		}
		return $output;
	}
	function	parsewithbq($text, $record = null) {
		$output = "";
		foreach (explode("`", $text) as $key => $chunk) {
			if (($key & 1) == 0) {
				$output .= $chunk;
				continue;
			}
			$output .= $this->parsebq($chunk, $record);
		}
		return $output;
	}
	function	parsewithbqhighlight($text, $record = null) {
		$output = "";
		foreach (explode("`", $text) as $key => $chunk) {
			if (($key & 1) == 0) {
				$output .= $chunk;
				continue;
			}
			$output .= "`?".str_replace("`", "`!", $this->parsebq($chunk, $record))."`?";
		}
		return $output;
	}
	function	parsewithbqinsql($text, $record = null) {
		$output = "";
		foreach (explode("`", $text) as $key => $chunk) {
			if (($key & 1) == 0) {
				$output .= $chunk;
				continue;
			}
			$s = $this->parsebq($chunk, $record);
			$s = preg_replace("/[^,0-9]/", "", $s);
			$output .= $s;
		}
		return $output;
	}
	function	parsename($text) {
		return $this->record->getfield($text);
	}
	function	postname($name, $checkval = null, $noclear = 0, $nopost = null) {
		$postkey = $this->prefix.str_replace(array(" ", "."), "_", $name);
		if (!ispost()) {
			if (($val = $nopost) === null)
				return;
		} else if (($val = @$_POST[$postkey]) === null)
			$val = $nopost;
		if ((substr($name, 0, 1) == ":")||(preg_match('/__/', $name)))
			$this->parsebq($name, $this->record, 0, array($val));
		if (preg_match('/^[0-9]/', $name))
			return;
		if ($checkval === null)
			;
		else if ($checkval == $val)
			;
		else if (($noclear))
			return;
		else
			$val = "";
		$s = "v_".$name;
		$this->record->$s = $val;
	}
	function	parsehtmlhighlight($html = "") {
		global	$tablelist;
		global	$actionrecordholder;
		global	$beforename;
		global	$beforenopost;
		global	$loginrecord;
		global	$sys;
		global	$coverage_actionlist;
		
		$output = "";
		foreach (explode("<", $html) as $key => $chunk) {
			if ($key == 0) {
				$tag = "";
				$body = $chunk;
			} else if (count($a = explode(">", $chunk, 2)) < 2) {
				$tag = "";
				$body = "<".$chunk;
			} else
				list($tag, $body) = $a;
			
			preg_match('#^(/?[A-Za-z]*)#i', $tag, $a);
			$tagtype = strtolower($a[1]);
			$type = "";
			if (preg_match('/type="?([a-zA-Z]+)/', $tag, $a))
				$type = strtolower($a[1]);
			if (($tagtype == "form")&&(!preg_match("/action=/i", $tag)))
				$tag .= '`| action="?'.$sys->urlquery.'"`|';
			else if (($tagtype == "/form")&&($loginrecord !== null))
				$output .= '`|<INPUT type=hidden name=submitkey value="'.($loginrecord->v_submitkey).'">`|'."\n";
			else if (($tagtype == "input")&&($type == "submit") && preg_match('/name="([^"]+)"/', $tag, $a)) {
				if ($this->prefix != "")
					$tag = preg_replace('/name="/', 'name="`|'.$this->prefix."`|", $tag, 1);
				$coverage_actionlist[$a[1]] = 1;
				$postkey = $this->prefix.str_replace(array(" ", "."), "_", $a[1]);
				if ((ispost())&&(@$_POST[$postkey] !== null)) {
					$actionrecordholder = $this;
					$this->actioncommand = $a[1];
				} else if (($loginrecord === null)&&($a[1] == ":login")&&(@$_POST[":login"] === null))
					$tablelist["login"]->check_maillogin();
				else if (($loginrecord === null)&&($a[1] == ":login")) {
					if (@$_POST["pass"] == "")
						bq_login(0);
					else
						$tablelist["login"]->check_loginform();
					log_die();
				}
			} else if (($tagtype == "input")&&($type == "checkbox") && preg_match('/name="([^"]+)/', $tag, $a) && preg_match('/value="([^"]+)/', $tag, $a2)) {
				if ($this->prefix != "")
					$tag = preg_replace('/name="/', 'name="`|'.$this->prefix."`|", $tag, 1);
				$this->postname($a[1], $a2[1]);
				if ($this->parsename($a[1]) == $a2[1])
					$tag .= " checked";
			} else if (($tagtype == "input")&&($type == "radio") && preg_match('/name="([^"]+)/', $tag, $a) && preg_match('/value="([^"]+)/', $tag, $a2)) {
				if ($this->prefix != "")
					$tag = preg_replace('/name="/', 'name="`|'.$this->prefix."`|", $tag, 1);
				$this->postname($a[1], $a2[1], 1);
				if ($this->parsename($a[1]) == $a2[1])
					$tag .= " checked";
			} else if (($tagtype == "input") && preg_match('/name="([^"]+)"/', $tag, $a) && preg_match('/x-value="([^"]*)"/', $tag, $a2)) {
				if ($this->prefix != "")
					$tag = preg_replace('/name="/', 'name="`|'.$this->prefix."`|", $tag, 1);
				$this->postname($a[1], null, 0, $a2[1]);
				$tag .= ' `|value="'.htmlspecialchars(@$this->parsename($a[1]), ENT_QUOTES).'"`|';
			} else if (($tagtype == "input") && preg_match('/name="([^"]+)"/', $tag, $a) && preg_match('/value=/', $tag)) {
				if ($this->prefix != "")
					$tag = preg_replace('/name="/', 'name="`|'.$this->prefix."`|", $tag, 1);
				$this->postname($a[1]);
			} else if (($tagtype == "input") && preg_match('/name="([^"]+)"/', $tag, $a)) {
				if ($this->prefix != "")
					$tag = preg_replace('/name="/', 'name="`|'.$this->prefix."`|", $tag, 1);
				$this->postname($a[1]);
				$tag .= ' `|value="'.htmlspecialchars(@$this->parsename($a[1]), ENT_QUOTES).'"`|';
			} else if (($tagtype == "select") && preg_match('/name="([^"]+)"/', $tag, $a) && preg_match('/x-value="([^"]*)"/', $tag, $a2)) {
				if ($this->prefix != "")
					$tag = preg_replace('/name="/', 'name="`|'.$this->prefix."`|", $tag, 1);
				$beforename = $a[1];
				$beforenopost = $a2[1];
			} else if (($tagtype == "select") && preg_match('/name="([^"]+)"/', $tag, $a)) {
				if ($this->prefix != "")
					$tag = preg_replace('/name="/', 'name="`|'.$this->prefix."`|", $tag, 1);
				$beforename = $a[1];
				$beforenopost = null;
			} else if (($tagtype == "option") && preg_match('/value="([^"]*)"/', $tag, $a)) {
				$this->postname($beforename, $a[1], 1, $beforenopost);
				if (@$this->parsename($beforename) == $a[1])
					$tag .= " `|selected`|";
			} else if (($tagtype == "textarea") && preg_match('/name="([_0-9A-Za-z]+)"/', $tag, $a) && preg_match('/x-value="([^"]*)"/', $tag, $a2)) {
				if ($this->prefix != "")
					$tag = preg_replace('/name="/', 'name="`|'.$this->prefix."`|", $tag, 1);
				$this->postname($a[1], null, 0, $a2[1]);
				if ($body == "")
					$body = "`|".htmlspecialchars(@$this->parsename($a[1]), ENT_QUOTES)."`|";
			} else if (($tagtype == "textarea") && preg_match('/name="([_0-9A-Za-z]+)"/', $tag, $a)) {
				if ($this->prefix != "")
					$tag = preg_replace('/name="/', 'name="`|'.$this->prefix."`|", $tag, 1);
				$this->postname($a[1]);
				if ($body == "")
					$body = "`|".htmlspecialchars(@$this->parsename($a[1]), ENT_QUOTES)."`|";
			}
			if ($tag != "")
				$output .= "<{$tag}>";
			$output .= $body;
		}
		return $output;
	}
	function	action() {
		$this->parsebq($this->actioncommand, $this->record, 1);
	}
}


class	recordholder_tableid extends recordholder {
# 「<!--{tableid テーブル名 id-->」の直後から「<!--}-->」までは、テーブル名とidで指定したレコードが「現在レコード」となる。
# idにはプレイスホルダー(``)を使って式を記述できる。
# idを省略するか0にすると、(下記の)update時に新規レコードを作成する。
# tableidはネストできる。例えば「<!--{tableid customer `CD__:r`-->」は、外側の現在レコードのCDフィールドが、customerテーブルのidとなる。
# submitのnameに「テーブル名__:h_update」を指定すると、指定したテーブルを入力した内容でupdateできる。
# なお、submitは複数のテーブルを指定できるので、tableidの内部に置く必要はない。
# また、1つのページ中で、tableidをいくつも使うと、フィールド名(「<INPUT name="フィールド名">」で入力を受け付けている)が衝突してしまう。この場合は、「<!--{tableid 別名あ=テーブル名 id-->」と「<!--{tableid 別名い=テーブル名 id-->」のようにすると、それぞれのnameに自動でプレフィックス「別名あ_」「別名い_」が追加され、衝突を避けることができる。これは、異なるテーブルに同じ名前のフィールドがある場合だけでなく、1つのテーブルの異なるレコードを1つのページで扱う場合にも利用できる。
# 2箇所以上のtableidで、同じテーブル名(別名を指定した場合は別名)を指定したときは、最初のtableidで指定したレコードが対象となる。これを利用して、1つのページで、2つのレコードを切り替えながらフィールドを記述することができる。
	function	__construct($rh = null, $record = null, $tablename = "", $prefix = "", $par = "") {
		global	$tablelist;
		
		parent::__construct($rh, $record, $tablename, $prefix, $par);
		
		if (($t = @$tablelist[$tablename]) === null)
			return;
		$this->record = $t->getrecord($par + 0);
#		$this->record->dumpfields();
	}
}


class	recordholder_stableid extends recordholder {
# 「<!--{stableid テーブル名 id-->」の直後から「<!--}-->」までは、テーブル名とidで指定したシンプルレコードが「現在レコード」となる。
# idにはプレイスホルダー(``)を使って式を記述できる。
# submitのnameに「テーブル名__:h_update」を指定すると、指定したテーブルを入力した内容でupdateできる。
# また、1つのページ中で、stableidをいくつも使うと、フィールド名(「<INPUT name="フィールド名">」で入力を受け付けている)が衝突してしまう。この場合は、「<!--{stableid 別名あ=テーブル名 id-->」と「<!--{stableid 別名い=テーブル名 id-->」のようにすると、それぞれのnameに自動でプレフィックス「別名あ_」「別名い_」が追加され、衝突を避けることができる。これは、異なるテーブルに同じ名前のフィールドがある場合だけでなく、1つのテーブルの異なるレコードを1つのページで扱う場合にも利用できる。
	function	__construct($rh = null, $record = null, $tablename = "", $prefix = "", $par = "") {
		global	$tablelist;
		
		parent::__construct($rh, $record, $tablename, $prefix, $par);
		
		$t = @$tablelist["simple"]->gettable($tablename);
		$this->record = $t->getrecord($par + 0);
#		$this->record->dumpfields();
	}
}


class	selectrecord {
	var	$tablename = "select";
	var	$fields;
	function	__construct($fields) {
		$this->fields = array();
		foreach ($fields as $key => $val) {
			if (!preg_match('/^mixed(.*)/', $key, $a)) {
				$this->fields[$key] = $val;
				continue;
			}
			foreach (explode("\t", $val) as $chunk) {
				if (count($a2 = explode("=", $chunk, 2)) < 2)
					continue;
				$this->fields[$a2[0].rawurldecode($a[1])] = rawurldecode($a2[1]);
			}
		}
	}
	function	getfield($field) {
		return @$this->fields[$field];
	}
	function	dumpfields($title = "") {
		global	$sys;
		global	$debuglog;
		
		if (@$sys->debugdir == "")
			return;
		$debuglog .= '<table border><tr><th colspan="2" style="background:#aff;">'."{$title}{$this->tablename}\n";
		foreach ($this->fields as $key => $val) {
			$s = htmlspecialchars($key, ENT_QUOTES);
			$s2 = htmlspecialchars($val);
			$debuglog .= "<tr><th>{$s}<td>{$s2}\n";
		}
		$debuglog .= "</table>\n";
	}
}


$commandparserindex = 0;
$rootparser = null;

class	commandparser {		# volatile object
	var	$cond = 1;
	var	$parent;
	var	$before;
	var	$name;
	var	$children;
	var	$index;
	var	$coverage_id;
	function	__construct($par = "", $parent = null, $before = null, $name = "") {
		global	$commandparserindex;
		global	$coverage_nextid;
		global	$coverage_title;
		
		$this->par = $par;
		$this->parent = $parent;
		$this->before = $before;
		$this->name = $name;
		$this->children = array();
		$this->index = $commandparserindex++;
		$this->coverage_id = $coverage_nextid;
		if ($name != "")
			$coverage_title[$coverage_nextid] = "{$coverage_nextid}_{$name} {$par}";
		
		if ($this->parent !== null)
			$this->parent->addchild($this);
	}
	function	addchild($commandparser = null) {
		$this->children[] = $commandparser;
	}
	function	gettree($index) {
		if ($index == 0)
			return "";
		if ($index < $this->index)
			return "";
		$ret = "";
		if ($this->parent === null) {
			$ret .= '<ul style="background:#c0c0ff">';
			$id = $this->gettreecoverageid($index);
			$ret .= '<a href="?coverage=1#'.$id.'" name="'.$id.'">coverage</a>';
			foreach ($this->children as $child)
				$ret .= $child->gettree($index);
			$ret .= "</ul>\n";
			return $ret;
		}
		$cond = "";
		$p = $this;
		while ($p !== null) {
			if ($p->cond <= 0) {
				$cond = "text-decoration: line-through;";
				break;
			}
			$p = $p->parent;
		}
		if ($this->name != "")
			$ret .= '<li style="'.$cond.'">'.$this->name." ".$this->par;
		if ($index == $this->index) {
			if ($ret == "")
				return '<li>...';
			return $ret;
		}
		
		if (count($this->children) > 0) {
			$ret .= "<ul>\n";
			foreach ($this->children as $child)
				$ret .= $child->gettree($index);
			$ret .= "</ul>\n";
		}
		return $ret;
	}
	function	gettreecoverageid($index) {
		if ($index == 0)
			return 0;
		if ($index < $this->index)
			return 0;
		$ret = $this->coverage_id;
		foreach ($this->children as $child) {
			$id = $child->gettreecoverageid($index);
			if ($ret < $id)
				$ret = $id;
		}
		return $ret;
	}
	function	getdebuglog($s = "") {
		if ($this->parent === null)
			return '<ul style="background:#c0c0ff"><li>root'.$s."</ul>\n";
		$a = preg_split('/_+/', get_class($this), 2);
		if ($this->before !== null)
			return $this->before->getdebuglog("<li>".@$a[1]." ".$this->par."\n{$s}");
		return $this->parent->getdebuglog("<ul><li>".@$a[1]." ".$this->par."\n{$s}</ul>\n");
	}
	function	parsehtml($rh = null, $record = null) {
		global	$debuglog;
		global	$rootparser;
		global	$coverage_id;
		
		$coverage_id = $this->coverage_id;
		
		$debuglog .= $rootparser->gettree($this->index);
		$this->parsehtmlinner($rh, $record);
	}
	function	parsehtmlinner($rh = null, $record = null) {
		global	$coverage_id;
		global	$coverage_count;
		
		@$coverage_count[$coverage_id]++;
		
		if ($rh === null)
			;
		else if ($rh->record === null)
			;
		else if (($s = $rh->prefix) != "")
			$rh->record->dumpfields("recordholder(".htmlspecialchars($s)."): ");
		else
			$rh->record->dumpfields("recordholder: ");
		if ($record !== null)
			$record->dumpfields("record: ");
		
		foreach ($this->children as $index => $child)
			$child->parsehtml($rh, $record, $index);
	}
	function	output($html = "", $htmlhighlight = "") {
		global	$htmloutput;
		global	$debuglog;
		
		if ($html == "")
			return;
		if ($this->parent !== null) {
			$this->parent->output($html, $htmlhighlight);
			return;
		}
		if ($htmlhighlight == "")
			$htmlhighlight = htmlspecialchars($html);
		$htmloutput .= $html;
		$debuglog .= $htmlhighlight;
	}
}


class	commandparserhtml extends commandparser {
	var	$first = 1;
	function	parsehtml($rh = null, $record = null, $index = 1) {
		global	$sys;
		global	$phase;
		global	$debuglog;
		global	$rootparser;
		
		$debuglog .= $rootparser->gettree($this->index);
		
		if (($this->first)) {
			$debuglog .= '<pre class="srcpartfirst" style="margin:0; background:#c0ffc0;">';
			$this->first = 0;
		} else {
			$debuglog .= '<pre class="srcpartnotfirst" style="margin:0; background:#c0c0c0;">';
		}
		foreach (explode("`", $this->par) as $key => $chunk) {
			$chunk = htmlspecialchars($chunk);
			if (($key & 1))
				$debuglog .= '<b style="color: #ff0000;">`'.$chunk.'`</b>';
			else
				$debuglog .= $chunk;
		}
		$debuglog .= '</pre>';
		
		$highlight = $rh->parsehtmlhighlight($rh->parsewithbqhighlight($this->par, $record));
		
		if ($phase == 0)
			$debuglog .= '<pre class="outphase0" style="margin:0; background:#ffc0c0;">';
		else
			$debuglog .= '<pre class="outphase1" style="margin:0; background:#ffffc0;">';
		
		$html = "";
		$htmlhighlight = "";
		$flag = 0;
		foreach (explode("`", $highlight) as $key => $val) {
			if ($key == 0) {
				$html .= $val;
				$htmlhighlight .= htmlspecialchars($val);
				continue;
			}
			$s = "";
			switch (substr($val, 0, 1)) {
				case	"!":
					$s = "`";
					break;
				case	"?":
					$flag ^= 1;
					break;
				case	"|":
					$flag ^= 2;
					break;
			}
			$sclose = "";
			switch ($flag) {
				case	1:
				case	3:
					$htmlhighlight .= '<b style="color: #ff0000;">';
					$sclose = "</b>";
					break;
				case	2:
					$htmlhighlight .= '<span style="color: #0000ff;">';
					$sclose = "</span>";
					break;
			}
			$html .= $s.substr($val, 1);
			$htmlhighlight .= htmlspecialchars($s.substr($val, 1)).$sclose;
		}
		$this->output($html, $htmlhighlight);
		$debuglog .= '</pre>';
	}
}


class	commandparserrecordholder extends commandparser {
	function	parsehtmlinner($rh = null, $record = null) {
		global	$recordholderlist;
		
#		$a = explode(" ", trim($rh->parsewithbqinsql($this->par, $record)), 2);
		$a = explode(" ", trim($rh->parsewithbq($this->par, $record)), 2);
		
		if (count($a2 = explode("=", $a[0], 2)) == 1) {
			$tablename = $alias = $a[0];
			$prefix = "";
		} else {
			$alias = $a2[0];
			$tablename = $a2[1];
			$prefix = $alias."_";
		}
		
		if (($h = @$recordholderlist[$alias]) === null) {
			$s = "recordholder_".$this->name;
			$h = $recordholderlist[$alias] = new $s($rh, $record, $tablename, $prefix, trim(@$a[1]));
		}
		parent::parsehtmlinner($h);
	}
}


class	commandparser_if extends commandparser {
	function	parsehtmlinner($rh = null, $record = null) {
		$this->cond = ($rh->parsewithbq($this->par, $record) == 0)? 0 : 1;
		if ($this->cond < 1)
			return;
		parent::parsehtmlinner($rh, $record);
	}
}


class	commandparser__elseif extends commandparser {
	function	parsehtmlinner($rh = null, $record = null) {
		if ($this->before === null)
			;
		else if ($this->before->cond == 0)
			;
		else {
			$this->cond = -1;
			return;
		}
		$this->cond = ($rh->parsewithbq($this->par, $record) == 0)? 0 : 1;
		if ($this->cond < 1)
			return;
		parent::parsehtmlinner($rh, $record);
	}
}


class	commandparser__else extends commandparser {
	function	parsehtmlinner($rh = null, $record = null) {
		if ($this->before === null)
			;
		else if ($this->before->cond == 0)
			;
		else {
			$this->cond = -1;
			return;
		}
		$this->cond = 1;
		parent::parsehtmlinner($rh, $record);
	}
}


class	commandparser_selectrows extends commandparser {
# 「<!--{selectrows SQL句-->」の直後から「<!--}-->」までの範囲は、指定したSQL句で得られた検索結果の数だけ繰り返される。
# 例えば「<!--{selectrows from customer limit 10-->`id__:r`<!--}-->」とすると、「select * from customer limit 10」を実行し、得られた1行1行に「`id__:r`」がおこなわれて出力される。
# プレイスホルダーでは、検索結果の行が現在レコードとして扱われるが、「:curtable」や「:set」などは、外側の現在レコードがアクセスされる。
	function	parsehtmlinner($rh = null, $record = null) {
		$rh2 = new recordholder();
		$sql = "select * ".$rh2->parsewithbqinsql($this->par, $record);
		list($s, $list) = $rh2->parsewhere($sql);
		$this->cond = 0;
		foreach (execsql($s, $list, 0, 1) as $val) {
			$this->cond = 1;
			parent::parsehtmlinner($rh, new selectrecord($val));
		}
	}
}


class	commandparser_stablerows extends commandparser {
# 「<!--{stablerows シンプルテーブル名-->」の直後から「<!--}-->」までの範囲は、指定したシンプルテーブルのレコードの数だけ繰り返される。
# SQLでなくシンプルテーブル名を扱う以外は、selectrowsコマンドと同じである。
	function	parsehtmlinner($rh = null, $record = null) {
		global	$tablelist;
		
		$this->cond = 0;
		$stable = @$tablelist["simple"]->gettable(trim($this->par.""));
		if ($stable === null)
			return "";
		foreach ($stable->getrecordidlist() as $recordid) {
			$this->cond = 1;
			parent::parsehtmlinner($rh, $stable->getrecord($recordid));
		}
	}
}


class	daterecord extends rootrecord {
	var	$tablename = "date";
	function	__construct($t, $s = "") {
		$this->v_t = $t;
		$this->v_s = date($s, $t);
		$this->v_Y = date("Y", $t);	# 2001
		$this->v_y = date("y", $t);	# 01
		$this->v_m = date("m", $t);	# 01-12
		$this->v_n = date("n", $t);	# 1-12
		$this->v_w = date("w", $t);	# 0-6
		$this->v_W = date("W", $t);	# (week number)
		$this->v_d = date("d", $t);	# 01-31
		$this->v_j = date("j", $t);	# 1-31
	}
}


class	commandparser_dayrows extends commandparser {
	function	parsehtmlinner($rh = null, $record = null) {
		$a = explode(" ", $rh->parsewithbq($this->par, $record), 3);
		$t = $a[0] + 0;
		if (($count = @$a[1]) == 0)
			$count = 1;
		
		while ($count > 0) {
			parent::parsehtmlinner($rh, new daterecord($t, @$a[2]));
			$t += 86400;
			$count--;
		}
		while ($count < 0) {
			parent::parsehtmlinner($rh, new daterecord($t, @$a[2]));
			$t -= 86400;
			$count++;
		}
	}
}


class	commandparser_wdayrows extends commandparser {
	function	parsehtmlinner($rh = null, $record = null) {
		$a = explode(" ", $rh->parsewithbq($this->par, $record), 4);
		$t = $a[0] + 0;
		if (($count = @$a[1]) == 0)
			$count = 1;
		$start = @$a[2] + 0;
		
		for ($i=0; $i<7; $i++) {
			$r = new daterecord($t);
			if ($r->v_w == $start)
				break;
			$t -= 86400;
		}
		
		while ($count > 0) {
			parent::parsehtmlinner($rh, new daterecord($t, @$a[3]));
			$t += 86400;
			$count--;
		}
		while ($count < 0) {
			for ($i=0; $i<7; $i++) {
				parent::parsehtmlinner($rh, new daterecord($t, @$a[3]));
				$t += 86400;
				$count++;
			}
			$t -= 86400 * 14;
		}
	}
}


class	commandparser_valid extends commandparser {
	function	parsehtmlinner($rh = null, $record = null) {
		global	$beforename;
		global	$invalid;
		
		$this->cond = 0;
		if (!ispost())
			return;
		
		$a = explode(" ", $this->par, 2);
		if (($s0 = @$a[1]) == "")
			$s0 = $beforename;
		$s = $rh->record->getfield($s0);
		if (!preg_match('/^([_A-Za-z]+)(.*)/', $a[0], $a2))
			return;
		$cmd = "tv_".$a2[1];
		if (!method_exists($rh->record, $cmd))
			return;
		if (!($rh->record->$cmd($a2[2], $s)))
			return;
		$invalid = $this->cond = 1;
		parent::parsehtmlinner($rh, $record);
	}
}


require("tables.php");


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
		$s = "v_{$key}";
		$this->r->$s = $val;
	}
	function	get($key) {
		if ($key != "id") {
			$s = "v_{$key}";
			return @$this->r->$s."";
		}
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
		return @$cache[$val] + 0;
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
		return @$cache[$val] + 0;
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
						$obj = new im_table($s = trim($a[0]), @$a[2] + 0);
						$list[$s] = $obj;
						array_unshift($stack, $obj);
						if (@$a[2] + 0 == 0)
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


$cookiepath = "";
if (preg_match('%://[^/]+/(.*)/index[.]php%', $sys->url, $a))
	$cookiepath = "/".$a[1];
if (!preg_match('%^((.*)/index[.]php)(.*)$%', $sys->url, $a)) {
	header("Location: {$sys->url}/index.php");
	log_die();
}

$sys->urlbase = $a[1];
if (!preg_match('%^/([^/]*)/([0-9A-Za-z]+)[.]html$%', $a[3], $a2)) {
	header("Location: {$sys->urlbase}/nofmt/{$sys->rootpage}.html");
	log_die();
}
$orgdebugfn = $a2[1];
$sys->target = $a2[2];

if ($orgdebugfn == "nopost")
	die("<h1>view only.</h1>");

if (($s = @$_POST[":reportbody"]) !== null) {
	$body = $s."";
	$link = "";
	if (@$sys->debugdir !== null)
		$link = $a[2]."/{$sys->debugdir}/{$orgdebugfn}.php";
	if (@$sys->reportjsonurl != "") {
		$list = array();
		foreach (@$sys->reportjsonbase as $key => $s) {
			$s = str_replace("@body@", $body, $s);
			$s = str_replace("@link@", $link, $s);
			$list[$key] = $s;
				continue;
		}
		$a = array(
			"http" => array(
				"method" => "POST", 
				"protocol_version" => 1.1, 
				"header" => "Content-Type: application/json\r\nConnection: close\r\n", 
				"content" => json_encode($list)
			)
		);
		file_get_contents(@$sys->reportjsonurl, FALSE, stream_context_create($a));
		print <<<EOO
<HEAD><META http-equiv=refresh content="2; {$sys->url}"></HEAD>
<H2>Report sent! Thank you!</H2>

EOO;
		log_die();
	}
	log_die();
}


$t = @$tablelist["simple"]->gettable("__dbinfo");
$a = $t->getlist();
$updated = 0;
foreach ($tablelist as $key => $obj)
	if (@$a[0][$key] != $obj->getconfig()) {
		$updated = 1;
		break;
	}
if (($updated)) {
	foreach ($tablelist as $key => $obj)
		$obj->createtable();
	$t = @$tablelist["simple"]->gettable("__dbinfo");
	$a = array();
	foreach ($tablelist as $key => $obj)
		$a[$key] = $obj->getconfig();
	$t->updatelist(array($a));
}


if (!function_exists("bq_login")) {
	function	bq_login($type = null, $r = null)
	{
		global	$tablelist;
		global	$sys;
		
		if ($type === null)
			return array();
		switch ($type) {
			case	"emptypass":		# empty password
				header("Location: {$sys->url}?mode=2mailsent");
				if (($mailcmd = $sys->mailcmd) == "")
					log_die("mailcmd empty.");
				if (($mailbody = @$sys->mailbody) == "")
					log_die("mailbody empty.");
				if (@$sys->mailinterval <= 0)
					log_die("mailinterval <= 0.");
		
				if (($login = @$_POST["login"]) == "")
					log_die("login empty.");
				$list = $tablelist["login"]->getrecordidlist("where login = ?", array($login));
				if (count($list) < 1)
					log_die("no login found");
				$r = $tablelist["login"]->getrecord($uid = $list[0]);
		
				if (@$r->v_mailsent > $sys->now - $sys->mailinterval)
					log_die("mail interval too short.");
		
				$key =$r->getrandom();
				$r->setmailkey($key);
		
				$a0 = array("@addr@", "@url@");
				$a1 = array($login, "{$sys->url}?mode=1login&uid={$uid}&key={$key}&");
				$mailcmd = str_replace($a0, $a1, $mailcmd);
				$mailbody = str_replace($a0, $a1, $mailbody);
				if (($fp = popen($mailcmd, "w")) === null)
					log_die("popen failed: {$mailcmd}");
				fputs($fp, $mailbody);
				pclose($fp);
				log_die("mail sent.");
			case	"goodlogin":		# login success
				break;
			case	"badlogin":		# login fail
				break;
			case	"logout":		# logout
				break;
			case	"changelogin":	# change mail address
				break;
			case	"changepass":		# change password
				break;
		}
		return array();
	}
}


if ($tablelist["login"]->is_login() <= 0)
	$sys->target = "index";
else if ((@$sys->noredirectonlogin))
	;
else if (@$sys->target == "index") {
	header("Location: {$sys->urlbase}/index/".@$sys->rootpage.".html");
	log_die();
}
if (($targethtml = @file_get_contents("{$sys->htmlbase}/{$sys->target}.html")) === FALSE) {
	header("Location: {$sys->urlbase}/nopage/".@$sys->rootpage.".html");
	log_die();
}
$targethash = sha1($targethtml);
$tableshash = sha1(file_get_contents("tables.php"));

#file_put_contents("php://stderr", $sys->target."\n");


function	ispost()
{
	global	$loginrecord;
	
	if (@$loginrecord === null)
		return 0;
	if (@$loginrecord->v_submitkey == "")
		return 0;
	if (@$_POST["submitkey"] == @$loginrecord->v_submitkey)
		return 1;
	return 0;
}


if (@$sys->debugdir !== null) {
	if ($loginrecord !== null)
		$sys->debugfn .= implode("_", preg_split('/[^0-9A-Za-z]+/', @$loginrecord->v_login.""));
	$sys->debugfn .= "_{$sys->target}";
	if ((ispost()))
		$sys->debugfn .= "_post";
	ini_set("log_errors", "1");
	ini_set("error_log", "{$sys->debugdir}/{$sys->debugfn}.errorlog");
	$coverage_list = array();
}
$ip = @$_SERVER["REMOTE_ADDR"];
$ua = htmlspecialchars(@$_SERVER["HTTP_USER_AGENT"], ENT_QUOTES);

$debuglog = <<<EOO
orgdebuglog: <A href="{$orgdebugfn}.php">{$orgdebugfn}.php</A> from {$ip} ({$ua})
<br /><a href="?coverage=1">coverage</a> <a href="?snapshot=1">view snapshot</a>
<TABLE border>
<TR><TH colspan=2>GET

EOO;

foreach (@$_GET as $key => $val) {
	$debuglog .= <<<EOO
<TR><TH>{$key}<TD>{$val}
	
EOO;
}
$debuglog .= <<<EOO
</TABLE>

<TABLE border>
<TR><TH colspan=2>POST

EOO;
foreach (@$_POST as $key => $val) {
	if ($key == "pass")
		$val = "(".strlen($val).")";
	$debuglog .= <<<EOO
<TR><TH>{$key}<TD>{$val}

EOO;
}
$debuglog .= <<<EOO
</TABLE>

EOO;


$recordholderlist = array();
$actionrecordholder = null;

if (@$sys->debugdir !== null) {
	$debuglog .= '<h1>* source</h1><div class="srcall">';
	foreach (preg_split("/\r\n|\r|\n/", $targethtml) as $line) {
		$debuglog .= '<p style="margin:0; background:#c0c0c0;">';
		foreach (explode("<!--{", $line) as $k0 => $v0) {
			if ($k0 > 0)
				$debuglog .= '<b style="color:#0000ff;">&lt;!--{';
			foreach (explode("<!--}", $v0) as $k1 => $v1) {
				if ($k1 > 0)
					$debuglog .= '<b style="color:#0000ff;">&lt;!--}';
				foreach (explode("-->", $v1, 2) as $k2 => $v2) {
					if ($k2 > 0)
						$debuglog .= "--&gt;</b>";
					foreach (explode('`', $v2) as $k3 => $v3) {
						if ($k3 > 0)
							$debuglog .= '<b style="color:#ff0000;">`</b>';
						if (($k3 & 1))
							$debuglog .= '<b style="color:#ff0000;">';
						$debuglog .= htmlspecialchars($v3);
						if (($k3 & 1))
							$debuglog .= "</b>";
					}
				}
			}
		}
		$debuglog .= "</p>\n";
	}
	$debuglog .= "</div>\n";
}

for ($phase=0; $phase<2; $phase++) {
	$beforename = "";
	$beforenopost = null;
	$commandparserindex = 0;
	
	$invalid = 0;
	$debuglog .= "\n\n<H1>* phase{$phase}</H1>\n\n";
	$coverage_nextid++;
	$parserstack = array($rootparser = new commandparser("", null, null, "(phase{$phase})"));
	$currenttablename = "";
	$htmloutput = "";
	
	foreach (explode("<!--{", $targethtml) as $key => $chunk) {
		if ($key == 0) {
			$coverage_nextid++;
			new commandparserhtml($chunk, $parserstack[0]);
			continue;
		}
		foreach (explode("<!--}", $chunk) as $key2 => $chunk2) {
			if ($key2 == 0) {
				$a = explode("-->", $chunk2, 2);
				$a2 = explode(" ", $a[0], 2);
				$coverage_nextid++;
				if (class_exists($s = "commandparser_".$a2[0]))
					$obj = new $s(@$a2[1]."", $parserstack[0], null, $a2[0]);
				else if (class_exists($s = "recordholder_".$a2[0]))
					$obj = new commandparserrecordholder(@$a2[1]."", $parserstack[0], null, $a2[0]);
				else
					$obj = new commandparser(@$a2[1]."", $parserstack[0], null);
				array_unshift($parserstack, $obj);
				new commandparserhtml(@$a[1], $parserstack[0]);
				continue;
			}
			if (count($parserstack) > 1)
				$beforeparser = array_shift($parserstack);
			else
				$beforeparser = $parserstack[0];
			$a = explode("-->", $chunk2, 2);
			$coverage_nextid++;
			if (substr($a[0], 0, 1) == "{") {
				$a2 = explode(" ", $a[0], 2);
				if (!class_exists($s = "commandparser__".substr($a2[0], 1)))
					$s = "commandparser";
				array_unshift($parserstack, new $s(@$a2[1]."", $parserstack[0], $beforeparser, substr($a2[0], 1)));
				new commandparserhtml(@$a[1]."", $parserstack[0]);
			} else {
				new commandparserhtml(@$a[1]."", $parserstack[0]);
			}
		}
	}
	$rootparser->parsehtml(new recordholder());
}
if (@$sys->debugdir === null)
	;
else if (count($a = preg_split("/<HEAD>/i", $htmloutput, 2)) == 2) {
	list($s0, $s1) = $a;
	$htmloutput = <<<EOO
{$s0}<HEAD><BASE href="{$sys->urlbase}/{$sys->debugfn}/{$sys->target}.html">
{$s1}
EOO;
} else {
	$htmloutput = <<<EOO
<HEAD><BASE href="{$sys->urlbase}/{$sys->debugfn}/{$sys->target}.html"></HEAD>
{$htmloutput}
EOO;
}

$coverage_nextid++;
$rootparser = new commandparser("", null, null, "(action)");
if ($coverage_list !== null)
	foreach ($coverage_actionlist as $key => $val)
		$coverage_list[$coverage_nextid]["\t".base64_encode($key)] = 0;

if ($actionrecordholder !== null) {
	$rootparser->parsehtml();
	$debuglog .= "\n\n<H1>(action)</H1>\n\n";
$debuglog .= "\n\n<H2>".str_repeat(" |  ", 1).get_class($actionrecordholder)."(".$actionrecordholder->record->tablename.") ".$parserstack[0]->cond."</H2>\n";
	$actionrecordholder->action();
}
adddebuglog();
print $htmloutput;
log_die();

