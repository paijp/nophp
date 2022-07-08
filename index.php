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
	var	$debugdiff = 1;		# 1:show diff on debuglog
	
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
			pclose($log_fp);
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
		$lastid = 0;
		foreach ($list as $id => $val) {
			if ($id == 0)
				continue;
			print '<ul style="background:#ccf;">'."\n";
			while ($lastid < $id)
				print '<a name="'.(++$lastid).'"> </a>'; 
			$level = 1;
			foreach ($titlelist as $k => $v) {
				if ($k > $id)
					break;
				preg_match("/^(\t*)(.*)/", @$titlelist[$k], $a);
				while ($level < strlen($a[1])) {
					print "<ul>\n";
					$level++;
				}
				while ($level > strlen($a[1])) {
					print "</ul>\n";
					$level--;
				}
				print "<li>".htmlspecialchars($a[2])."\n";
			}
			while ($level > 0) {
				print "</ul>\n";
				$level--;
			}
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
						$head .= '<th style="background:#ff8;"><span style="color: #888;">'.htmlspecialchars($pars);
						$pars = "";
						$head .= "</span>:".str_replace($searchlist, $replacelist, htmlspecialchars($cmd));
					}
				}
				print "<table border><tr><th>\n{$head}";
				foreach ($val2 as $key3 => $val3) {
					$a = explode("#", $val3);
					$fn = htmlspecialchars($a[0], ENT_QUOTES);
					if (($v = $a[1] + 0) < 0)
						$v = 1;
					print '<tr><th style="text-align: left;"><a href="'.$fn.'.php#'.$id.".".$v.'">'."{$fn}#{$v}\n";
					foreach (explode("\t", $key3) as $k => $v)
						print "\t".'<td style="text-align: right;">'.htmlspecialchars(base64_decode($v));
				}
				print "<tr><th>\n{$head}</table>\n<p></p>\n";
			}
		}
		if ($id != $actionid) {
			print '<ul style="background:#ccf;">'."\n";
			while ($lastid < $actionid)
				print '<a name="'.(++$lastid).'"> </a>'; 
			$level = 1;
			foreach ($titlelist as $k => $v) {
				if ($k > $actionid)
					break;
				preg_match("/^(\t*)(.*)/", @$titlelist[$k], $a);
				while ($level < strlen($a[1])) {
					print "<ul>\n";
					$level++;
				}
				while ($level > strlen($a[1])) {
					print "</ul>\n";
					$level--;
				}
				print "<li>".htmlspecialchars($a[2])."\n";
			}
			while ($level > 0) {
				print "</ul>\n";
				$level--;
			}
		}
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
					$head .= '<th style="background:#ff8;"><span style="color: #888;">'.htmlspecialchars($pars);
					$pars = "";
					$head .= "</span>:".str_replace($searchlist, $replacelist, htmlspecialchars($cmd));
				}
			}
			print '<table border><tr><th><span style="color: #aaa;">(no log)</span>'."\n{$head}";
			print "</table>\n<p></p>\n";
		}
		die();
	}
	print "<pre><b>".htmlspecialchars(@file_get_contents("{$logview_fn}.errorlog"))."</b></pre>\n";
	
	if ((@$sys->debugdiff)) {
		if ($log_fp === null)
			$content = file_get_contents("{$logview_fn}.php");
		else {
			$content = stream_get_contents($log_fp);
			pclose($log_fp);
			$log_fp = null;
		}
		list($part0, $s) = explode('<div class="srcall">', $content, 2);
		list($part1, $part2) = explode('</div>', $s, 2);
		print $part0;
		
		$a = array(
			0 => array("pipe", "r"), 
			1 => array("pipe", "w")
		);
		$p0 = proc_open("diff -U 99999999 - ".escapeshellarg(@$logview_targetpath), $a, $plist);
		$sinput = substr(html_entity_decode(strip_tags($part1)), 0, -1);
		fputs($plist[0], $sinput);
		fclose($plist[0]);
		$soutput = stream_get_contents($plist[1]);
		proc_close($p0);
		
		$mode = 0;
		if ($soutput == "") {
			$mode = 1;
			$soutput = $sinput;
		}
		
#		print nl2br(htmlspecialchars($soutput));
	print "<pre>\n";
		foreach (preg_split("/\r\n|\r|\n/", $soutput) as $key => $line) {
#			$style = " background:#ccc;";
			$style = "";
			if ($mode == 0) {
				switch ($key) {
					case	0:
						$line = "-Removed at newest file.";
						break;
					case	1:
						$line = "+Added at newest file.";
						break;
					case	2:
						$line = " ";
						break;
				}
				switch (substr($line, 0, 1)) {
					case	"+":
						$style = " background:#cfc; color:#888;";
						print '<p style="margin:0;'.$style.'">';
						print htmlspecialchars($line)."</p>";
						continue 2;
					case	"-":
						$style = " background:#ccc;";
#						$style .= " text-decoration: line-through;";
						break;
					case	" ":
						$line = substr($line, 1);
						break;
				}
			}
			print '<p style="margin:0;'.$style.'">';
			if ($line == "") {
				print " </p>";
				continue;
			}
			foreach (explode("<!--{", $line) as $k0 => $v0) {
				if ($k0 > 0)
					print '<b style="color:#0000ff;">&lt;!--{';
				foreach (explode("<!--}", $v0) as $k1 => $v1) {
					if ($k1 > 0)
						print '<b style="color:#0000ff;">&lt;!--}';
					foreach (explode("-->", $v1, 2) as $k2 => $v2) {
						if ($k2 > 0)
							print "--&gt;</b>";
						foreach (explode('`', $v2) as $k3 => $v3) {
							if ($k3 > 0)
								print '<b style="color:#ff0000;">`</b>';
							if (($k3 & 1))
								print '<b style="color:#ff0000;">';
							print htmlspecialchars($v3);
							if (($k3 & 1))
								print "</b>";
						}
					}
				}
			}
			print "</p>";
		}
	print "</pre>\n";
		
		print $part2;
		
		die();
	}
	
	if ($log_fp !== null) {
		print stream_get_contents($log_fp);
		die();
	}
	return;
}
$coverage_list = null;
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
	
	if ($debugdir === null)
		$debugdir = @$sys->debugdir;
	if ($debugdir !== null) {
		if ($debugfn === null)
			$debugfn = "{$sys->debugfn}.php";
		$fn = "{$debugdir}/{$debugfn}";
		
		if (!file_exists($fn)) {
			$fn_self = __FILE__;
			$targetpath = realpath("{$sys->htmlbase}/{$sys->target}.html");
			$s = <<<EOO
<?php
if ((@\$isinclude))
	return;
\$logview_fn = "{$sys->debugfn}";
\$logview_coverage = "{$targethash}.{$tableshash}";
\$logview_urlbase = "{$sys->urlbase}";
\$logview_targetpath = "{$targetpath}";
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
	else if (($coveragelogbuffer == "")||(strlen($coveragelogbuffer) + strlen($s) < $sys->debugchunksize)) {
		$coveragelogbuffer .= $s;
		return;
	}
	
	if ($sys->debuggz == 0) {
		file_put_contents($fn, $coveragelogbuffer, FILE_APPEND);
		$coveragelogbuffer = $s."";
		return;
	}
	$fn .= ".gz";
	if (function_exists("gzencode")) {
		file_put_contents($fn, gzencode($coveragelogbuffer), FILE_APPEND);
		$coveragelogbuffer = $s."";
		return;
	}
	$fp = popen("gzip >>".escapeshellarg($fn), "w");
	fputs($fp, $coveragelogbuffer);
	pclose($fp);
	$coveragelogbuffer = $s."";
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
		$debuglog .= '<table style="background:#fca;">'."\n";
		foreach (explode("?", $sql) as $key => $val) {
			$debuglog .= '<tr><th align="right">'.htmlspecialchars($val, ENT_QUOTES)."</span>";
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
# If you have added fields in tables.php, you should also use "alter table add" or "create index" on the first access or when you use ?mode=create for the already created tables.
# At this time, if a field with the same name is registered in mixed, the data is moved from that field to the actual field.
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
		$debuglog .= '<table border><tr><th colspan="2" style="background:#fca;">'."{$title}{$this->tablename}\n";
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
## Take three strings from the stack, consider the first as the record ID, the second as the field name, and the third as the table name, and stack the field value of the record ID of the table on the stack.
## For example, `1__name__user__:t_id` is the value of the name field in record 1 of the user table.
		$r = $this->getrecord($s1 + 0);
		return array($r->getfield($s3)."");
	}
	function	t_find__val__field($rh0, $record, $s1, $s3) {
## Take three strings from the stack, consider the first as a field value, the second as a field name, and the third as a table name, and find the record ID whose table contains the field value and stack it on the stack.
## Returns an empty string if no field value was found. If multiple records match, the one with the smallest ID is returned.
## For example, `john__name__user__:t_find` is the record ID of the record whose value in the name field of the user table is john.
		foreach ($this->getrecordidlist() as $id) {
			$r = $this->getrecord($id);
			if (@$r->getfield($s3) == $s1)
				return array($id);
		}
		return array("");
	}
	function	h_set__val__field($rh0, $record, $s1, $s3) {
## Take three strings from the stack, consider them as field value, field name, and table name, respectively, and set them to the current record of the specified table.
## Table names must be declared in advance with "<!--{tableid" etc. must be declared beforehand.
## For example, `1__id__user__:t_set` sets the ID of the current record in the user table to 1.
		if ($s3 == "id")
			$s4 = $s3;
		else
			$s4 = "v_{$s3}";
		$this->$s4 = $s1;
		return array();
	}
	function	hs_update($rh0, $record = null) {
## Take a string from the stack, consider it a table name, and UPDATE or INSERT a record in that table.
## The table must be specified as "<!--{tableid", etc.
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
# <!--{valid len3-5 v1--> if the v1 field of the current record is 0,1,2,6,7 characters. <!--}-->
# <!--{valid len3- v1--> Displayed if the v1 field of the current record is 0, 1, or 2 characters. <!--}-->
# <!--{valid len-5 v1--> Displayed when the v1 field of the current record is 6 or 7 characters long. <!--}-->
		if (!preg_match('/^(-?[0-9]*)-(-?[0-9]*)/', $par, $a2))
			return 0;
		if ((($i = $a2[1]) != "")&&(strlen($s) < $i + 0))
			return 1;
		if ((($i = $a2[2]) != "")&&(strlen($s) > $i + 0))
			return 1;
		return 0;
	}
	function	tv_lenopt($par, $s) {
# <!--{valid lenopt3-5 v1--> Displayed if the v1 field of the current record is 1,2,6,7 characters. <!--}-->
# <!--{valid lenopt3- v1--> Displayed if the v1 field of the current record is 1 or 2 characters. <!--}-->
		if ($s == "")
			return 0;
		return $this->tv_len($par, $s);
	}
	function	tv_num($par, $s) {
# <!--{valid num3-5 v1--> Displayed if the v1 field of the current record is not an integer 3-5. <!--}-->
# <!--{valid num3- v1-->This is displayed if the v1 field of the current record is not greater than or equal to the integer 3. <!--}-->
# <!--{valid num-5 v1-->This is displayed if the v1 field of the current record is not less than or equal to the integer 5. <!--}-->
# <!--{valid num-5--3 v1--> if the v1 field of the current record is not an integer -5 to -3. <!--}-->
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
# <!--{valid numopt3-5 v1--> Displayed if the v1 field of the current record is neither empty nor an integer 3-5. <!--}-->
# <!--{valid numopt3- v1--> Displayed if the v1 field of the current record is neither empty nor greater than or equal to the integer 3. <!--}-->
# <!--{valid numopt-5 v1--> Displayed if the v1 field of the current record is neither empty nor less than the integer 5. <!--}-->
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
## Take three strings from the stack, consider them as field value, field name, and table name, respectively, and set them to the current record of the specified table.
## Table names must be declared in advance with "<!--{tableid" etc. must be declared beforehand.
## For example, `1__id__user__:t_set` sets the ID of the current record in the user table to 1.
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
## Take three strings from the stack, consider the first as the record ID, the second as the field name, and the third as the simple table name, and stack the field value of the record ID of the simple table on the stack.
## For example, `1__name__user__:s_id` is the value of the name field of record 1 in the user simple table.
		$r = $this->getrecord($s1 + 0);
		return array($r->getfield($s3)."");
	}
	function	s_find__val__field($rh0, $record, $s1, $s3) {
## Take three strings from the stack, consider the first as a field value, the second as a field name, and the third as a simple table name, search for a record ID whose field value is in the simple table, and stack them on the stack.
## If no field value is found, it will be an empty string. If multiple records match, it will be the one with the smallest ID.
## For example, `john__name__user__:s_find` is the record ID of the record whose value in the name field of the user table is john.
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
		global	$debuglog;
		
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
		global	$debuglog;
		
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
		global	$debuglog;
		
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
		if (@$coverage_list[$coverage_id][$s] === null) {
			if (($v = @$coverage_count[$coverage_id] + 0) < 1)
				$v = 1;
			$coverage_list[$coverage_id][$s] = $v;
		}
		
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
				$head .= '<th style="background:#ff8;"><span style="color: #888;">'.htmlspecialchars($pars);
				$pars = "";
				$head .= "</span>:".htmlspecialchars($cmd);
			}
		}
		$debuglog .= "<table border><tr>{$head}\n<tr>";
		foreach ($this->coveragelist as $key => $val)
			if ($key > 0)
				$debuglog .= "\t".'<td style="text-align: right;">'.@$this->debugpoplist[$key].nl2br(htmlspecialchars($val))."<br />";
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
## "." on the stack.
## For example, `index__:dot__html__:cat:cat` becomes "index.html".
## Normally, this is not necessary.
						$this->popstack($cmd, "");
						$this->pushstack(array("."));
						break;
					case	"col":
## Stack the ":" on the stack.
## For example, `12__:col__00__:cat:cat` would be "12:00".
## Use when you want to enter ":" as a character, since a description prefixed with ":" is considered a command.
						$this->popstack($cmd, "");
						$this->pushstack(array(":"));
						break;
					case	"sp":
## " " on the stack.
## For example, `abc__:sp__def__:cat:cat` becomes "abc def".
## Use when spaces are not allowed, such as during the name of the input tag.
						$this->popstack($cmd, "");
						$this->pushstack(array(" "));
						break;
					case	"bq":
## "`" on the stack.
## For example, `:bq` becomes "`".
						$this->popstack($cmd, "");
						$this->pushstack(array("`"));
						break;
					case	"null":
## Empty string (zero-length string) on stack.
						$this->popstack($cmd, "");
						$this->pushstack(array(""));
						break;
					case	"hex":
## Take one string from the stack, consider it as a sequence of hexadecimal numbers, convert it to a string, and stack it on the stack.
## For example, `414243__:hex` would be "ABC".
## Use to enter special characters that cannot be entered using the above methods.
						list($s1) = $this->popstack($cmd, "hex");
						$s = "";
						for ($i=0; $i<strlen($s1); $i+=2)
							$s .= chr(filter_var("0x".substr($s1, $i, 2), FILTER_VALIDATE_INT, FILTER_FLAG_ALLOW_HEX));
						$this->pushstack(array($s.""));
						break;
					case	"curid":
## Stack the current record ID on the stack.
## For example, inside "<!--{tableid user 1-->" inside, "1" is stacked on the stack.
						$this->popstack($cmd, "");
						$s = @$this->record->id + 0;
						$this->pushstack(array($s));
						break;
					case	"curtable":
## Stack the current table name on the stack.
## For example, inside "<!--{tableid user 1-->" inside, "user" is stacked on the stack.
						$this->popstack($cmd, "");
						$s = @$this->record->tablename."";
						$this->pushstack(array($s));
						break;
					case	"curpage":
## Stack the current page name obtained from the URL.
## For example, when "g0000.html" is accessed, "g0000" is stacked on the stack.
						$this->popstack($cmd, "");
						$this->pushstack(array($sys->target));
						break;
					case	"ispost":
## Stack 1 if the current request is a POST, or empty string if it is not a POST.
						$this->popstack($cmd, "");
						$this->pushstack(array((ispost())? "1" : ""));
						break;
					case	"isvalid":
## Validation stacks "1" if there are no errors, or an empty string if there are errors.
						$this->popstack($cmd, "");
						$this->pushstack(array(($invalid)? "" : "1"));
						break;
					case	"g":
## Take one string from the stack, consider it a GET name, and stack the resulting GET value on the stack.
## For example, if the URL is "?id=1", then `id__:g` is "1".
## If "?id" is not specified, `id__:g` will be an empty string.
## GET can only yield an empty string or a sequence of numbers and commas for security reasons.
						list($s1) = $this->popstack($cmd, "name");
						$s = @$_GET[$s1]."";
						$s = preg_replace("/[^,0-9]/", "", $s);
						$this->pushstack(array($s));
						break;
					case	"p":
## Take one string from the stack and consider it as a POST name, and pile the resulting POST value on the stack.
## For example, the value submitted with <form method="post"><input name="s1"><input type="submit"> can be obtained with `s1__:p`.
## If it is not a POST or the POST name does not exist, it will be an empty string.
## With POST, you can get an arbitrary string (unlike GET, which has a submitkey).
## However, `` in SQL can only output numbers and (added 190429) ",".
# See also: parsewithbqinsql()
						list($s1) = $this->popstack($cmd, "name");
						if ((ispost())) {
							$postkey = $this->prefix.str_replace(array(" ", "."), "_", $s1);
							$this->pushstack(array(@$_POST[$postkey].""));
						} else
							$this->pushstack(array(""));
						break;
					case	"r":
## Take one string from the stack and consider it a field name, then retrieve the field from the current record and stack it on the stack.
## For example, "<!--{tableid user 1-->" inside, `id__:r` will yield the value of the "id" field of record 1 in the user table.
## If no record is defined or the specified field name does not exist, it will be an empty string.
						list($s1) = $this->popstack($cmd, "field");
						$s = $record->getfield($s1)."";
						$this->pushstack(array($s));
						break;
					case	"int":
## Take one string from the stack, round it off as a number, and stack it on the stack.
## For example, `3.8__:int` is "4".
						list($s1) = $this->popstack($cmd, "val");
						$this->pushstack(array(round($s1 + 0)));
						break;
					case	"isnull":
## Take one string from the stack and stack "1" if it is an empty string, otherwise empty string on the stack.
## `id__:g:isnull:andbreak` will break if there is no specification such as "?id=1".
## It can also be used for logic inversion, in the form `:isvalid:isnull`.
						list($s1) = $this->popstack($cmd, "val");
						$this->pushstack(array(($s1 == "")? "1" : ""));
						break;
					case	"h2z":
## Take a string from the stack, convert half-width alphanumeric characters to full-width alphanumeric characters, and stack them on the stack.
						list($s1) = $this->popstack($cmd, "hankaku");
						$this->pushstack(array(mb_convert_kana($s1, "ASKV", "UTF-8")));
						break;
					case	"z2h":
## Take a string from the stack, convert full-width alphanumeric characters to half-width alphanumeric characters, and stack them on the stack.
						list($s1) = $this->popstack($cmd, "zenkaku");
						$this->pushstack(array(mb_convert_kana($s1, "as", "UTF-8")));
						break;
					case	"sys":
## Take a string from the stack, consider it a system variable name, and stack the variable value on the stack.
## For example, if there is a statement in env.php that $sys->v_limit = 100;, then `limit__:sys` will be "100".
						list($s1) = $this->popstack($cmd, "name");
						$s ="v_{$s1}";
						$this->pushstack(array(@$sys->$s.""));
						break;
					case	"now":
## Place the current time value (in seconds since January 1, 1970 0:00:00 GMT) on the stack.
## Use :todate to convert to a generic date and time.
## For example, `:now__y/m/d H:i:s__:todate` would be "19/02/10 19:52:13", etc.
						$this->popstack($cmd, "");
						$this->pushstack(array($sys->now));
						break;
					case	"ymd2t":
## Take three strings from the stack and stack the current time value (seconds since 0:00:00 GMT on Jan 1, 1970), considering them as year, month, and day, respectively.
## Use :todate to convert to a generic date and time.
						list($s1, $s2, $s3) = $this->popstack($cmd, "year month day");
						$t = mktime(0, 0, 0, $s2, $s3, $s1);
						$this->pushstack(array($t));
						break;
					case	"age2t":
## Take a string from the stack, consider it as a number of years, and add it to the stack as the current time value (seconds since 0:00:00 GMT, Jan 1, 1970) minus the number of years.
## Use :todate to convert to a generic date and time.
						list($s1) = $this->popstack($cmd, "year");
						$t = mktime(date("H", $sys->now), date("i", $sys->now), date("s", $sys->now), date("n", $sys->now), date("j", $sys->now), date("Y", $sys->now) - $s1);
						$this->pushstack(array($t));
						break;
					case	"todate":
## Take a time value (seconds since January 1, 1970 0:00:00 GMT) and a format string from the stack, and put the string with the time in the format on the stack.
## The format string can be from the php date() function.
## Use :now to get the current time value.
## For example, `:now__y/m/d H:i:s__:todate` would be "19/02/10 19:52:13", etc.
						list($s1, $s2) = $this->popstack($cmd, "time dateformat");
						$this->pushstack(array(date($s2, $s1 + 0)));
						break;
					case	"html":
## Indicates HTML escaping of output.
## The stack does not change and can be placed anywhere.
## For example, both `:html__<` and `<__:html` are "&lt;".
					case	"url":
## Indicates rawurl encoding of output.
## The stack does not change and can be placed anywhere.
## For example, both `:url__<` and `<__:url` are "%3C".
					case	"js":
## Indicates that the output is to be converted to a string in a form that can be directly embedded in JavaScript.
## The stack does not change and can be placed anywhere.
## Specifically, for example, `:js__<` and `<__:js` are both "decodeURIComponent("%3C")".
## Also, `:js__A` and `A__:js` are both ""A"".
## This allows us to handle it in JavaScript without quotes, as in "var a = `name__:r:js`;".
## It also allows for less output than if everything were rawurl encoded.
					case	"raw":
						$outputmode = $cmd;
						$this->pushstack(array());
						break;
					case	"cat":
## Take two strings from the stack, concatenate them, and stack them on the stack.
## For example, `a__b__:cat` would be "ab".
						list($s1, $s2) = $this->popstack($cmd, "s0 s1");
						$this->pushstack(array($s1.$s2));
						break;
					case	"rcat":
## Take two strings from the stack, combine them in reverse order, and stack them on the stack.
## For example, `a__b__:rcat` would be "ba".
						list($s1, $s2) = $this->popstack($cmd, "s0 s1");
						$this->pushstack(array($s2.$s1));
						break;
					case	"scat":
## Take two strings from the stack, concatenate them, and stack them on the stack.
## For example, `a__b__:scat` becomes "a b".
						list($s1, $s2) = $this->popstack($cmd, "s0 s1");
						$this->pushstack(array("{$s1} {$s2}"));
						break;
					case	"rscat":
## Take two strings from the stack, combine them in reverse order, and stack them on the stack.
## For example, `a__b__:rscat` becomes "b a".
						list($s1, $s2) = $this->popstack($cmd, "s0 s1");
						$this->pushstack(array("{$s2} {$s1}"));
						break;
					case	"ismatch":
## Take two strings from the stack and stack "1" if the second string is in the first string, otherwise empty string on the stack.
## For example, `abc__b__:match` is "1" because there is a "b" in "abc".
## Conversely, `abc__cb__:match` is an empty string because there is no "cb" in "abc".
						list($s1, $s2) = $this->popstack($cmd, "s0 s1");
						$this->pushstack(array((strpos($s1, $s2) !== FALSE)? 1 : ""));
						break;
					case	"addzero":
## Take two strings from the stack and stack them on the stack with the first string prefixed with "0" until the length specified by the second character count is reached.
## For example, `123__6__:addzero` would be "000123".
## Also, `123__2__:addzero`.
## Note that `-123__6__:addzero` will be "00-123" since it is not treated as a number.
						list($s, $v) = $this->popstack($cmd, "num digits");
						if (($i = strlen($s)) < $v + 0)
							$s = str_repeat("0", $v - $i).$s;
						$this->pushstack(array($s));
						break;
					case	"add":
## Take two strings from the stack, consider each to be a number, and add them to the stack.
## For example, `123__456__:add` would be "579".
						list($s1, $s2) = $this->popstack($cmd, "i j");
						$this->pushstack(array($s1 + $s2));
						break;
					case	"sub":
## Take two strings from the stack and subtract each as a number and stack them on the stack.
## For example, `123__456__:sub` would be "-333".
						list($s1, $s2) = $this->popstack($cmd, "i j");
						$this->pushstack(array($s1 - $s2));
						break;
					case	"rsub":
## Take two strings from the stack, consider each as a number and subtract them in reverse order, and stack them on the stack.
## For example, `123__456__:rsub` would be "333".
						list($s1, $s2) = $this->popstack($cmd, "i j");
						$this->pushstack(array($s2 - $s1));
						break;
					case	"mul":
## Take two strings from the stack, consider each to be a number, and stack them on the stack, adding them up.
## For example, `123__456__:mul` would be "56088".
						list($s1, $s2) = $this->popstack($cmd, "i j");
						$this->pushstack(array($s1 * $s2));
						break;
					case	"div":
## Take two strings from the stack, divide each of them as a number, and stack them on the stack rounded down.
## For example, `123__456__:div` would be "0".
## If the divisor is 0, the divisor is 0.
						list($s1, $s2) = $this->popstack($cmd, "i j");
						if ($s2 == 0)
							$this->pushstack(array(0));
						else
							$this->pushstack(array(floor($s1 / $s2)));
						break;
					case	"rdiv":
## Take two strings from the stack, consider each as a number, divide them in reverse order, and round down to the nearest whole number, and stack them on the stack.
## For example, `123__456__:rdiv` would be "3".
## If the divisor is 0, the divisor is 0.
						list($s1, $s2) = $this->popstack($cmd, "i j");
						if ($s1 == 0)
							$this->pushstack(array(0));
						else
							$this->pushstack(array(floor($s2 / $s1)));
						break;
					case	"mod":
## Take two strings from the stack, consider each to be a number, and stack the remainder of the division on the stack.
## For example, `123__456__:mod` would be "123".
## If the divisor is 0, the divisor is 0.
						list($s1, $s2) = $this->popstack($cmd, "i j");
						if ($s2 == 0)
							$this->pushstack(array(0));
						else
							$this->pushstack(array($s1 % $s2));
						break;
					case	"rmod":
## Take two strings from the stack, consider each to be a number, divide them in reverse order, and stack the remainder on the stack.
## For example, `123__456__:rmod` would be "87".
## If the divisor is 0, the divisor is 0.
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
## Take two strings from the stack and regard each as a number, stacking them on the stack with a "1" if they are equal or an empty string if they are not.
## For example, `1__1__:ieq` or `1__01__:ieq` or `0__ __:ieq` or `0x1__1__:ieq` is "1".
						list($s1, $s2) = $this->popstack($cmd, "i j");
						$this->pushstack(array(($s1 + 0 == $s2 + 0)? 1 : ""));
						break;
					case	"seq":
## Take two strings from the stack, consider each as a string, and pile "1" on the stack if they are equal, or an empty string if they are not equal.
## For example, `1__1__:seq` would be "1".
## The `1__01__:seq` or `0__ __:seq` will be an empty string.
						list($s1, $s2) = $this->popstack($cmd, "i j");
						$this->pushstack(array(($s1."" === $s2."")? 1 : ""));
						break;
					case	"ne":
						list($s1, $s2) = $this->popstack($cmd, "i j");
						$this->pushstack(array(($s1 != $s2)? 1 : ""));
						break;
					case	"ine":
## Take two strings from the stack and regard each as a number, stacking them on the stack with a "1" if they are not equal, or an empty string if they are equal.
## For example, `1__1__:ieq` or `1__01__:ieq` or `0__ __:ieq` or `0x1__1__:ieq` is an empty string.
						list($s1, $s2) = $this->popstack($cmd, "i j");
						$this->pushstack(array(($s1 + 0 != $s2 + 0)? 1 : ""));
						break;
					case	"sne":
## Take two strings from the stack and consider each to be a string, stacking them on the stack with a "1" if they are not equal and an empty string if they are.
## For example, `1__1__:seq` will be an empty string.
## 1__01__:seq` or `0__ __:seq` will be "1".
						list($s1, $s2) = $this->popstack($cmd, "i j");
						$this->pushstack(array(($s1."" !== $s2."")? 1 : ""));
						break;
					case	"lt":
						list($s1, $s2) = $this->popstack($cmd, "i j");
						$this->pushstack(array(($s1 < $s2)? 1 : ""));
						break;
					case	"ilt":
## Take two strings from the stack, consider each as a number, and pile "1" on the stack if the second one is larger, otherwise empty string.
## For example, `1__2__:ilt` would be "1".
## `1__1__:ilt` or `1__0__:ilt` will be an empty string.
						list($s1, $s2) = $this->popstack($cmd, "i j");
						$this->pushstack(array(($s1 + 0 < $s2 + 0)? 1 : ""));
						break;
					case	"slt":
## Take two strings from the stack, consider each as a string, and pile "1" on the stack if the second one is larger, otherwise empty string.
## For example, `1__2__:slt` would be "1".
## `1__1__:slt` and `1__0__:slt` will be empty strings.
						list($s1, $s2) = $this->popstack($cmd, "i j");
						$this->pushstack(array(("_".$s1 < "_".$s2)? 1 : ""));
						break;
					case	"gt":
						list($s1, $s2) = $this->popstack($cmd, "i j");
						$this->pushstack(array(($s1 > $s2)? 1 : ""));
						break;
					case	"igt":
## Take two strings from the stack, consider each as a number, and pile "1" on the stack if the first is larger, otherwise empty string.
## For example, `1__0__:igt` would be "1".
## `1__1__:igt` and `1__2__:igt` will be empty strings.
						list($s1, $s2) = $this->popstack($cmd, "i j");
						$this->pushstack(array(($s1 + 0 > $s2 + 0)? 1 : ""));
						break;
					case	"sgt":
## Take two strings from the stack and consider each to be a string, stacking "1" on the stack if the first is larger, otherwise an empty string.
## For example, `1__0__:sgt` would be "1".
## `1__1__:sgt` and `1__2__:sgt` will be empty strings.
						list($s1, $s2) = $this->popstack($cmd, "i j");
						$this->pushstack(array(("_".$s1 > "_".$s2)? 1 : ""));
						break;
					case	"le":
						list($s1, $s2) = $this->popstack($cmd, "i j");
						$this->pushstack(array(($s1 <= $s2)? 1 : ""));
						break;
					case	"ile":
## Take two strings from the stack, consider each as a number, and stack "1" if they are equal or the second is greater, otherwise empty string on the stack.
## For example, `1__2__:ile` or `1__1__:ile` would be "1".
## `1__0__:ile` will be an empty string.
						list($s1, $s2) = $this->popstack($cmd, "i j");
						$this->pushstack(array(($s1 + 0 <= $s2 + 0)? 1 : ""));
						break;
					case	"sle":
## Take two strings from the stack, consider each as a string, and pile "1" on the stack if they are equal or the second is greater, otherwise empty string.
## For example, `1__2__:sle` or `1__1__:sle` would be "1".
## `1__0__:sle` will be an empty string.
						list($s1, $s2) = $this->popstack($cmd, "i j");
						$this->pushstack(array(("_".$s1 <= "_".$s2)? 1 : ""));
						break;
					case	"ge":
						list($s1, $s2) = $this->popstack($cmd, "i j");
						$this->pushstack(array(($s1 >= $s2)? 1 : ""));
						break;
					case	"ige":
## Take two strings from the stack, consider each as a number, and stack "1" if they are equal or the first is greater, otherwise empty string on the stack.
## For example, `1__0__:ige` or `1__1__:ige` is "1".
## `1__2__:ige` will be an empty string.
						list($s1, $s2) = $this->popstack($cmd, "i j");
						$this->pushstack(array(($s1 + 0 >= $s2 + 0)? 1 : ""));
						break;
					case	"sge":
## Take two strings from the stack, consider each as a string, and stack "1" if they are equal or the first is larger, otherwise empty string on the stack.
## For example, `1__0__:sge` or `1__1__:sge` would be "1".
## `1__2__:sge` will be an empty string.
						list($s1, $s2) = $this->popstack($cmd, "i j");
						$this->pushstack(array(("_".$s1 >= "_".$s2)? 1 : ""));
						break;
					case	"dup":
## Take one string from the stack and stack two identical ones on the stack.
## This is used, for example, in `id__:g:dup:isnull:andbreak__table__field__:tableid`, when you want to use "id__:g" once obtained for both ":isnull" and ":tableid".
						$this->popstack($cmd, "");
						$s1 = $this->stack[count($this->stack) - 1];
						$this->pushstack(array($s1));
						break;
					case	"andbreak":
## Retrieve a string from the stack and continue if it is an empty string or "0", otherwise terminate evaluation of the expression there and return an empty string.
## For example, `0__:andbreak__a` would be "a".
## On the other hand, `1__:andbreak__a` will be an empty string (no evaluation after __a).
## This is used, for example, `id__:g:dup:isnull:andbreak__table__field__:tableid` to terminate evaluation there if "id__:g" is an empty string.
						list($s1) = $this->popstack($cmd, "val");
						if ($s1 != 0) {
							$this->pushstack(array("BREAK"));
							$this->flush_coverage();
							return "";
						}
						$this->pushstack(array());
						break;
					case	"iandbreak":
## If the string in the stack is an empty string or "0", continue; otherwise, terminate evaluation of the expression there and return an empty string.
## For example, `0__:iandbreak` would be "0".
## On the other hand, `1__:iandbreak` will be an empty string (not evaluated thereafter).
## This is used, for example, `id__:g:dup:isnull:andbreak__table__field__:tableid` to terminate evaluation there if "id__:g" is an empty string.
						list($s1) = $this->popstack($cmd, "val");
						if ($s1 != 0) {
							$this->pushstack(array("BREAK"));
							$this->flush_coverage();
							return "";
						}
						$this->pushstack(array());
						break;
					case	"orbreak":
## Retrieve a string from the stack, and if it is an empty string or "0", terminate evaluation of the expression there and return an empty string, otherwise continue.
## For example, `1__:orbreak__a` would be "a".
## On the other hand, `0__:orbreak__a` will be an empty string (no evaluation after __a).
## This is used, for example, in `id__:g:int:dup:orbreak__id__:set` to terminate evaluation there if "id__:g:int" is zero or an empty string.
						list($s1) = $this->popstack($cmd, "val");
						if ($s1 == 0) {
							$this->pushstack(array("BREAK"));
							$this->flush_coverage();
							return "";
						}
						$this->pushstack(array());
						break;
					case	"iorbreak":
## If the string in the stack is an empty string or "0", then the evaluation of the expression ends there and an empty string is returned, otherwise it continues.
## For example, `1__:iorbreak` would be "1".
## On the other hand, `0__:iorbreak` will be an empty string (and will not be evaluated thereafter).
## This is used, for example, in `id__:g:int:dup:orbreak__id__:set` to terminate evaluation there if "id__:g:int" is zero or an empty string.
						list($s1) = $this->popstack($cmd, "val");
						if ($s1 == 0) {
							$this->pushstack(array("BREAK"));
							$this->flush_coverage();
							return "";
						}
						$this->pushstack(array($s1));
						break;
					case	"break":
## So it terminates evaluation of the expression and returns an empty string.
						$this->popstack($cmd, "");
						$this->pushstack(array("BREAK"));
						$this->flush_coverage();
						return "";
					case	"andreturn0":
## Takes one string from the stack and continues if it is an empty string or "0", otherwise terminates evaluation of the expression there and returns "0".
## For example, `0__:andreturn0__a` would be "a".
## On the other hand, `1__:andreturn0__a` will result in "0" (no evaluation after __a).
					case	"andreturn1":
## Takes one string from the stack and continues if it is an empty string or "0", otherwise terminates evaluation of the expression there and returns "1".
## For example, `0__:andreturn1__a` would be "a".
## On the other hand, `1__:andreturn1__a` will result in "1" (no evaluation after __a).
					case	"andreturn2":
## Takes one string from the stack and continues if it is an empty string or "0", otherwise terminates evaluation of the expression there and returns "2".
## For example, `0__:andreturn2__a` would be "a".
## On the other hand, `1__:andreturn2__a` would be "2" (no evaluation after __a).
					case	"andreturn3":
## Takes one string from the stack and continues if it is an empty string or "0", otherwise terminates evaluation of the expression there and returns "3".
## For example, `0__:andreturn3__a` would be "a".
## On the other hand, `1__:andreturn3__a` would be "3" (no evaluation after __a).
					case	"andreturn4":
## Takes one string from the stack and continues if it is an empty string or "0", otherwise terminates evaluation of the expression there and returns "4".
## For example, `0__:andreturn4__a` would be "a".
## On the other hand, `1__:andreturn4__a` would be "4" (no evaluation after __a).
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
## Retrieve a string from the stack, and if it is an empty string or "0", terminate evaluation of the expression there and return "0", otherwise continue.
## For example, `1__:orreturn0__a` would be "a".
## On the other hand, `0__:orreturn0__a` will be "0" (no evaluation after __a).
					case	"orreturn1":
## Retrieve a string from the stack, and if it is an empty string or "0", terminate evaluation of the expression there and return "1", otherwise continue.
## For example, `1__:orreturn1__a` would be "a".
## On the other hand, `0__:orreturn1__a` will result in "1" (no evaluation after __a).
					case	"orreturn2":
## Retrieve a string from the stack, and if it is an empty string or "0", terminate evaluation of the expression there and return "2", otherwise continue.
## For example, `1__:orreturn2__a` would be "a".
## On the other hand, `0__:orreturn2__a` would be "2" (no evaluation after __a).
					case	"orreturn3":
## Retrieve a string from the stack, and if it is an empty string or "0", terminate evaluation of the expression there and return "3", otherwise continue.
## For example, `1__:orreturn3__a` would be "a".
## On the other hand, `0__:orreturn3__a` would be "3" (no evaluation after __a).
					case	"orreturn4":
## Retrieve a string from the stack, and if it is an empty string or "0", terminate evaluation of the expression there and return "4", otherwise continue.
## For example, `1__:orreturn4__a` would be "a".
## On the other hand, `0__:orreturn4__a` would be "4" (no evaluation after __a).
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
## Finish evaluating the expression and redirect to the root page.
						$this->popstack($cmd, "");
						$this->pushstack(array("HOME"));
						$this->flush_coverage();
						header("Location: {$sys->urlbase}/nofmt/{$sys->rootpage}.html");
						log_die();
					case	"andhome":
## Take one string from the stack and continue if it is an empty string or "0", otherwise terminate evaluation of the expression there and redirect to the root page.
## For example, `0__:andhome__a` would be "a".
## On the other hand, `1__:andhome__a` redirects to the root page (no evaluation after __a).
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
## If the string in the stack is an empty string or "0", continue as is; otherwise, terminate expression evaluation there and redirect to the root page.
## For example, `0__:iandhome` would be "0".
## On the other hand, `1__:iandhome` redirects to the root page (and is not evaluated thereafter).
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
## Take one string from the stack and if it is an empty string or "0", terminate evaluation of the expression there and redirect to the root page, otherwise continue.
## For example, `1__:orhome__a` would be "a".
## On the other hand, `0__:orhome__a` redirects to the root page (no evaluation after __a).
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
## If the string in the stack is an empty string or "0", then stop evaluating the expression there and redirect to the root page, otherwise continue.
## For example, `1__:iorhome` would be "1".
## On the other hand, `0__:iorhome` redirects to the root page (and is not evaluated thereafter).
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
## Take one string from the stack and discard it.
## This is used when the record ID obtained is not used, for example in :h_update.
						$this->popstack($cmd, "drop");
						$this->pushstack(array());
						break;
					case	"authwithpass":
					case	"reportbody":
					case	"popall":
## Empty the stack by discarding the entire stack.
						$this->popstack($cmd, "");
						$this->stack = array();
						$this->pushstack(array());
						break;
					case	"jump":
## Take all elements from the stack, convert them to URLs, and jump to those URLs.
## For example, `a0__:jump` will jump to "a0.html".
## Also, `a0__id__1__:jump` will jump to "a0.html?id=1".
## Furthermore, `a0__id__1__mode__new__:jump` jumps to "a0.html?id=1&mode=new".
## This can continue for as long as needed.
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
## Take two strings from the stack and stack the first string if the second string is empty, otherwise stack the second string on the stack.
## For example, `empty__name__:g:sor` is "empty" if `name__:g` is an empty string, otherwise `name__:g`.
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
## Take two strings from the stack, consider each as a number, and pile the first number on the stack if the second number is 0, otherwise the second number.
## For example, `2__1__:ior` becomes "1" and `2__0__:ior` becomes "2".
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
## Take one string from the stack, consider it a field name, and stack the field values of the record in the login table corresponding to the logged-in user for this session.
## Empty string if there is no login for this session.
## For example, `login__:loginrecord` will be the login name for this session.
						list($s1) = $this->popstack($cmd, "field");
						$s = "";
						if ($loginrecord !== null)
							$s = $loginrecord->getfield($s1)."";
						$this->pushstack(array($s));
						break;
					case	"set":
## Take two strings from the stack, consider them as field values and field names, respectively, and set them to the current record.
## For example, `1__id__:set` sets the ID of the current record to 1.
						list($s1, $s2) = $this->popstack($cmd, "val field");
						if ($s2 == "id")
							$s3 = $s2;
						else
							$s3 = "v_{$s2}";
						$this->record->$s3 = $s1;
						$this->pushstack(array());
						break;
					case	"sqlisnull":
## Take one string from the stack, consider it a field name, and add "and field name is null" to the corresponding SQL statement.
## This is a tablegrid that is a tablegrid that contains both the "<!--{tablegrid" parameter section and "<!--}--" up to and including "<!--{selectrows" parameter section.
						list($s1) = $this->popstack($cmd, "field");
						$this->whereargs["{$s1} is null"] = array();
						$this->pushstack(array());
						break;
					case	"sqlisnotnull":
## Take one string from the stack, consider it a field name, and add "and field name is not null" to the corresponding SQL statement.
## This is a tablegrid that is a tablegrid that contains both the "<!--{tablegrid" parameter section and "<!--}--" up to and including "<!--{selectrows" parameter section.
						list($s1) = $this->popstack($cmd, "field");
						$this->whereargs["{$s1} is not null"] = array();
						$this->pushstack(array());
						break;
					case	"sqlisempty":
## Take one string from the stack, consider it a field name, and add "and (field name is null or field name = "")" to the corresponding SQL statement.
## This is a tablegrid that is a tablegrid that contains both the "<!--{tablegrid" parameter section and "<!--}--" up to and including "<!--{selectrows" parameter section.
						list($s1) = $this->popstack($cmd, "field");
						$this->whereargs["({$s1} is null or {$s1} = ?)"] = array("");
						$this->pushstack(array());
						break;
					case	"sqlisnotempty":
## Take one string from the stack, consider it a field name, and add "and field name is not null and field name <> "" to the corresponding SQL statement.
## This is a tablegrid that is a tablegrid that contains both the "<!--{tablegrid" parameter section and "<!--}--" up to and including "<!--{selectrows" parameter section.
						list($s1) = $this->popstack($cmd, "field");
						$this->whereargs["{$s1} is not null"] = array();
						$this->whereargs["{$s1} <> ?"] = array("");
						$this->pushstack(array());
						break;
					case	"sqllike":
## Take two strings from the stack, consider each to be a search string and a field name, and add "and field name like "%search string%"" to the corresponding SQL statement.
## This is a tablegrid that is a tablegrid that contains both the "<!--{tablegrid" parameter section and "<!--}--" up to and including "<!--{selectrows" parameter section.
						list($s1, $s2) = $this->popstack($cmd, "val field");
						$this->whereargs["{$s2} like ?"] = array("%{$s1}%");
						$this->pushstack(array());
						break;
					case	"sqllike2":
## Take three strings from the stack, consider each as a search string, field name 1, and field name 2, and add "and (field name 1 like "%search string%" or field name 2 like "%search string%")" to the corresponding SQL statement.
## This is a tablegrid that is a tablegrid that contains both the "<!--{tablegrid" parameter section and "<!--}--" up to and including "<!--{selectrows" parameter section.
						list($s1, $s2, $s3) = $this->popstack($cmd, "val field1 field2");
						$this->whereargs["({$s2} like ? or {$s3} like ?)"] = array("%{$s1}%", "%{$s1}%");
						$this->pushstack(array());
						break;
					case	"sqllike3":
## Take four strings from the stack, consider each as a search string, field name 1, field name 2, and field name 3, and add "and (field name 1 like "%Search String%" or field name 2 like "%Search String%" or field name 3 like "% Search String%")" is added.
## This is a tablegrid that is a tablegrid that contains both the "<!--{tablegrid" parameter section and "<!--}--" up to and including "<!--{selectrows" parameter section.
						list($s1, $s2, $s3, $s4) = $this->popstack($cmd, "val field1 field2 field3");
						$this->whereargs["({$s2} like ? or {$s3} like ? or {$s4} like ?)"] = array("%{$s1}%", "%{$s1}%", "%{$s1}%");
						$this->pushstack(array());
						break;
					case	"sqllike4":
## Take 5 strings from the stack and consider each as a search string, field name 1, field name 2, field name 3, field name 4, and add "and (field name 1 like "%search string%" or field name 2 like "%search string%" or field name 3 like "%search string%" or field name 4 like "%search string")".
## This is a tablegrid that is a tablegrid that contains both the "<!--{tablegrid" parameter section and "<!--}--" up to and including "<!--{selectrows" parameter section.
						list($s1, $s2, $s3, $s4, $s5) = $this->popstack($cmd, "val field1 field2 field3 field4");
						$this->whereargs["({$s2} like ? or {$s3} like ? or {$s4} like ? or {$s5} like ?)"] = array("%{$s1}%", "%{$s1}%", "%{$s1}%", "%{$s1}%");
						$this->pushstack(array());
						break;
					case	"sqlnotlike":
## Take two strings from the stack, consider them as a search string and a field name, respectively, and add "and field name not like "%search string%"" to the corresponding SQL statement.
## This is a tablegrid that is a tablegrid that contains both the "<!--{tablegrid" parameter section and "<!--}--" up to and including "<!--{selectrows" parameter section.
						list($s1, $s2) = $this->popstack($cmd, "val field");
						$this->whereargs["{$s2} not like ?"] = array("%{$s1}%");
						$this->pushstack(array());
						break;
					case	"sqleq":
## Take two strings from the stack, consider each to be a string and a field name, and add "and "string" = field name" to the corresponding SQL statement.
## This is a tablegrid that is a tablegrid that contains both the "<!--{tablegrid" parameter section and "<!--}--" up to and including "<!--{selectrows" parameter section.
						list($s1, $s2) = $this->popstack($cmd, "val field");
						$this->whereargs["? = {$s2}"] = array($s1);
						$this->pushstack(array());
						break;
					case	"sqlne":
## Take two strings from the stack, consider each to be a string and a field name, and add "and "string" <> field name" to the corresponding SQL statement.
## This is a tablegrid that is a tablegrid that contains both the "<!--{tablegrid" parameter section and "<!--}--" up to and including "<!--{selectrows" parameter section.
						list($s1, $s2) = $this->popstack($cmd, "val field");
						$this->whereargs["? <> {$s2}"] = array($s1);
						$this->pushstack(array());
						break;
					case	"sqllt":
## Take two strings from the stack, consider each to be a string and a field name, and add "and "string" < field name" to the corresponding SQL statement.
## This is a tablegrid that is a tablegrid that contains both the "<!--{tablegrid" parameter section and "<!--}--" up to and including "<!--{selectrows" parameter section.
						list($s1, $s2) = $this->popstack($cmd, "val field");
						$this->whereargs["? < {$s2}"] = array($s1);
						$this->pushstack(array());
						break;
					case	"sqlle":
## Take two strings from the stack, consider each to be a string and a field name, and add "and "string" <= field name" to the corresponding SQL statement.
## This is a tablegrid that is a tablegrid that contains both the "<!--{tablegrid" parameter section and "<!--}--" up to and including "<!--{selectrows" parameter section.
						list($s1, $s2) = $this->popstack($cmd, "val field");
						$this->whereargs["? <= {$s2}"] = array($s1);
						$this->pushstack(array());
						break;
					case	"sqlgt":
## Take two strings from the stack, consider each to be a string and a field name, and add "and "string" > field name" to the corresponding SQL statement.
## This is a tablegrid that is a tablegrid that contains both the "<!--{tablegrid" parameter section and "<!--}--" up to and including "<!--{selectrows" parameter section.
						list($s1, $s2) = $this->popstack($cmd, "val field");
						$this->whereargs["? > {$s2}"] = array($s1);
						$this->pushstack(array());
						break;
					case	"sqlge":
## Take two strings from the stack, consider each to be a string and a field name, and add "and "string" >= field name" to the corresponding SQL statement.
## This is a tablegrid that is a tablegrid that contains both the "<!--{tablegrid" parameter section and "<!--}--" up to and including "<!--{selectrows" parameter section.
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
	function	striphighlight($highlight = "") {
		$ret = "";
		foreach (explode("`", $highlight) as $key => $val) {
			if ($key <= 0) {
				$ret .= $val;
				continue;
			}
			if (substr($val, 0, 1) == "!")
				$ret .= "`";
			$ret .= substr($val, 1);
		}
		return $ret;
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
				$taghighlight = "";
				$body = $chunk;
			} else if (count($a = explode(">", $chunk, 2)) < 2) {
				$taghighlight = "";
				$body = "<".$chunk;
			} else
				list($taghighlight, $body) = $a;
			
			$tag = $this->striphighlight($taghighlight);
			preg_match('#^(/?[A-Za-z]*)#i', $tag, $a);
			$tagtype = strtolower($a[1]);
			$type = "";
			if (preg_match('/type="?([a-zA-Z]+)/', $tag, $a))
				$type = strtolower($a[1]);
			if (($tagtype == "form")&&(!preg_match("/action=/i", $tag)))
				$taghighlight .= '`| action="?'.$sys->urlquery.'"`|';
			else if (($tagtype == "/form")&&($loginrecord !== null))
				$output .= '`|<INPUT type=hidden name=submitkey value="'.($loginrecord->v_submitkey).'">`|'."\n";
			else if (($tagtype == "input")&&($type == "submit") && preg_match('/name="([^"]+)"/', $tag, $a)) {
				if ($this->prefix != "")
					$taghighlight = preg_replace('/name="/', 'name="`|'.$this->prefix."`|", $taghighlight, 1);
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
					$taghighlight = preg_replace('/name="/', 'name="`|'.$this->prefix."`|", $taghighlight, 1);
				$this->postname($a[1], $a2[1]);
				if ($this->parsename($a[1]) == $a2[1])
					$taghighlight .= " checked";
			} else if (($tagtype == "input")&&($type == "radio") && preg_match('/name="([^"]+)/', $tag, $a) && preg_match('/value="([^"]+)/', $tag, $a2)) {
				if ($this->prefix != "")
					$taghighlight = preg_replace('/name="/', 'name="`|'.$this->prefix."`|", $taghighlight, 1);
				$this->postname($a[1], $a2[1], 1);
				if ($this->parsename($a[1]) == $a2[1])
					$taghighlight .= " checked";
			} else if (($tagtype == "input") && preg_match('/name="([^"]+)"/', $tag, $a) && preg_match('/x-value="([^"]*)"/', $tag, $a2)) {
				if ($this->prefix != "")
					$taghighlight = preg_replace('/name="/', 'name="`|'.$this->prefix."`|", $taghighlight, 1);
				$this->postname($a[1], null, 0, $a2[1]);
				$taghighlight .= ' `|value="'.htmlspecialchars(@$this->parsename($a[1]), ENT_QUOTES).'"`|';
			} else if (($tagtype == "input") && preg_match('/name="([^"]+)"/', $tag, $a) && preg_match('/value=/', $tag)) {
				if ($this->prefix != "")
					$taghighlight = preg_replace('/name="/', 'name="`|'.$this->prefix."`|", $taghighlight, 1);
				$this->postname($a[1]);
			} else if (($tagtype == "input") && preg_match('/name="([^"]+)"/', $tag, $a)) {
				if ($this->prefix != "")
					$taghighlight = preg_replace('/name="/', 'name="`|'.$this->prefix."`|", $taghighlight, 1);
				$this->postname($a[1]);
				$taghighlight .= ' `|value="'.htmlspecialchars(@$this->parsename($a[1]), ENT_QUOTES).'"`|';
			} else if (($tagtype == "select") && preg_match('/name="([^"]+)"/', $tag, $a) && preg_match('/x-value="([^"]*)"/', $tag, $a2)) {
				if ($this->prefix != "")
					$taghighlight = preg_replace('/name="/', 'name="`|'.$this->prefix."`|", $taghighlight, 1);
				$beforename = $a[1];
				$beforenopost = $a2[1];
			} else if (($tagtype == "select") && preg_match('/name="([^"]+)"/', $tag, $a)) {
				if ($this->prefix != "")
					$taghighlight = preg_replace('/name="/', 'name="`|'.$this->prefix."`|", $taghighlight, 1);
				$beforename = $a[1];
				$beforenopost = null;
			} else if (($tagtype == "option") && preg_match('/value="([^"]*)"/', $tag, $a)) {
				$this->postname($beforename, $a[1], 1, $beforenopost);
				if (@$this->parsename($beforename) == $a[1])
					$taghighlight .= " `|selected`|";
			} else if (($tagtype == "textarea") && preg_match('/name="([_0-9A-Za-z]+)"/', $tag, $a) && preg_match('/x-value="([^"]*)"/', $tag, $a2)) {
				if ($this->prefix != "")
					$taghighlight = preg_replace('/name="/', 'name="`|'.$this->prefix."`|", $taghighlight, 1);
				$this->postname($a[1], null, 0, $a2[1]);
				if ($body == "")
					$body = "`|".htmlspecialchars(@$this->parsename($a[1]), ENT_QUOTES)."`|";
			} else if (($tagtype == "textarea") && preg_match('/name="([_0-9A-Za-z]+)"/', $tag, $a)) {
				if ($this->prefix != "")
					$taghighlight = preg_replace('/name="/', 'name="`|'.$this->prefix."`|", $taghighlight, 1);
				$this->postname($a[1]);
				if ($body == "")
					$body = "`|".htmlspecialchars(@$this->parsename($a[1]), ENT_QUOTES)."`|";
			}
			if ($taghighlight != "")
				$output .= "<{$taghighlight}>";
			$output .= $body;
		}
		return $output;
	}
	function	action() {
		$this->parsebq($this->actioncommand, $this->record, 1);
	}
}


class	recordholder_tableid extends recordholder {
# The "<!--{tableid Table name id-->" to "<!--}-->", the record specified by the table name and id becomes the "current record".
# The id can contain expressions using placeholders (``).
# If id is omitted or set to 0, a new record is created on UPDATE (below).
# Tableids can be nested. For example, "<!--{tableid customer `CD__:r`-->" will make the CD field of the outer current record the id of the customer table.
# If "table name__:h_update" is specified in the submit name, the specified table can be updated with the input contents.
# Note that SUBMIT can specify multiple tables, so there is no need to place it inside tableid.
# Also, if you use several tableids in one page, the field names (which accept input with "<INPUT name="field name">") will conflict. In this case, use "<!--{tableid alias a=table name id-->" and "<!--{tableid alias_a=table name id-->", the prefix "alias_a" and "alias_i" will be automatically added to each name to avoid the conflict. This can be used not only when there are fields with the same name in different tables, but also when different records from one table are handled on one page.
# When the same table name (or alias if an alias is specified) is specified in two or more tableids, the record specified in the first tableid is the target. This can be used to describe a field while switching between two records on a single page.
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
# The "<!--{stableid Table name id-->" to "<!--}-->", the simple record specified by the table name and id becomes the "current record".
# The id can contain expressions using placeholders (``).
# If "table name__:h_update" is specified in the submit name, the specified table can be updated with the input contents.
# Also, if you use several stableids in one page, the field names (which accept input with "<INPUT name="field name">") will conflict. In this case, use "<!--{stableid alias a=table name id-->" and "<!--{stableid alias_" and "--{stableid alias_i=table_name id-->" will automatically add the prefix "alias_a_" and "alias_i_" to each name to avoid the conflict. This can be used not only when there are fields with the same name in different tables, but also when different records from one table are handled on one page.
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
		$debuglog .= '<table border><tr><th colspan="2" style="background:#fca;">'."{$title}{$this->tablename}\n";
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
	function	__construct($par = "", $parent = null, $before = null, $name = "") {
		global	$commandparserindex;
		global	$coverage_title;
		
		$this->par = $par;
		$this->parent = $parent;
		$this->before = $before;
		$this->name = $name;
		$this->children = array();
		$this->index = $commandparserindex++;
		if ($name != "") {
			$s = "";
			$p = $this;
			while (($p = $p->parent))
				$s .= "\t";
			$coverage_title[$this->index] = "{$this->index}_{$s}{$name} {$par}";
		}
		if ($this->parent !== null)
			$this->parent->addchild($this);
	}
	function	addchild($commandparser = null) {
		$this->children[] = $commandparser;
	}
	function	gettree($index) {
		global	$phase;
		global	$coverage_id;
		global	$coverage_count;
		
		if ($index == 0)
			return "";
		if ($index < $this->index)
			return "";
		$ret = "";
		if ($this->parent === null) {
			$ret .= '<ul style="background:#c0c0ff">';
			if (($frag = @$coverage_count[$index] + 0) <= 0)
				$frag = 1;
			$ret .= ' <a href="?coverage=1#'.$index.'" name="'."{$index}.{$frag}".'">coverage</a>';
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
		if ($this->name == "")
			;
		else if (preg_match('/^[(]/', $this->name))
			$ret .= '<li style="color:#f00; '.$cond.'">'.htmlspecialchars($this->name." ".$this->par)."</li>";
		else
			$ret .= '<li style="'.$cond.'">'.htmlspecialchars($this->name." ".$this->par)."</li>";
		if ($index == $this->index) {
			if ($ret != "")
				return $ret;
			if (($count = @$coverage_count[@$index] + 0) > 1)
				return "<li>... #{$count}";
			return "<li>...";
		}
		
		if (count($this->children) > 0) {
			$ret .= "<ul>\n";
			foreach ($this->children as $child)
				$ret .= $child->gettree($index);
			$ret .= "</ul>\n";
		}
		return $ret;
	}
	function	parsehtml($rh = null, $record = null) {
		global	$debuglog;
		global	$rootparser;
		global	$coverage_id;
		global	$coverage_count;
		
		$coverage_id = $this->index;
		
		$debuglog .= $rootparser->gettree($this->index);
		$this->parsehtmlinner($rh, $record);
		$debuglog .= "<hr />\n";
	}
	function	parsehtmlinner($rh = null, $record = null) {
		global	$coverage_id;
		global	$coverage_count;
		
		$coverage_id = $this->index;
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
	function	parsehtml($rh = null, $record = null, $index = 1) {
		global	$sys;
		global	$phase;
		global	$debuglog;
		global	$rootparser;
		global	$coverage_id;
		global	$coverage_count;
		
		$coverage_id = $this->index;
		@$coverage_count[$coverage_id]++;
		
		$debuglog .= $rootparser->gettree($this->index);
		
		$debuglog .= '<pre class="srcpartfirst" style="margin:0; background:#c0c0ff;">';
		foreach (explode("`", $this->par) as $key => $chunk) {
			$chunk = htmlspecialchars($chunk);
			if (($key & 1))
				$debuglog .= '<b style="color: #ff0000;">`'.$chunk.'`</b>';
			else
				$debuglog .= $chunk;
		}
		$debuglog .= '</pre>';
		
		$highlight = $rh->parsehtmlhighlight($rh->parsewithbqhighlight($this->par, $record));
		$debuglog .= '<pre class="outphase'.$phase.'" style="margin:0; background:#c0ffc0;">';
		
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
		$debuglog .= "</pre><hr />\n";
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
# The "<!--{selectrows SQL clause-->" to "<!--}-->" is repeated as many times as the number of search results obtained with the specified SQL clause.
# For example, "<!--{selectrows from customer limit 10-->`id__:r`<!--}-->", "select * from customer limit 10" is executed and each line obtained is output with "`id__:r`".
# In placeholders, the row of the search result is treated as the current record, but for ":curtable", ":set", etc., the outer current record is accessed.
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
# The "<!--{stablerows simple table name-->" to "<!--}-->" is repeated for the number of records in the specified simple table.
# It is the same as the selectrows command, except that it handles simple table names instead of SQL.
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
<table border>
<tr><th colspan="2" style="background:#8f8;">GET

EOO;

foreach (@$_GET as $key => $val) {
	$debuglog .= <<<EOO
<tr><th>{$key}<td>{$val}
	
EOO;
}
$debuglog .= <<<EOO
</table>

<table border>
<tr><th colspan="2" style="background:#8f8;">POST

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

$rootparser = new commandparser();
$lastparser = null;

for ($phase=0; $phase<2; $phase++) {
	$beforename = "";
	$beforenopost = null;
	
	$invalid = 0;
	$parserstack = array($lastparser = new commandparser("", $rootparser, null, "(phase{$phase})"));
	$currenttablename = "";
	$htmloutput = "";
	
	foreach (explode("<!--{", $targethtml) as $key => $chunk) {
		if ($key == 0) {
			new commandparserhtml($chunk, $parserstack[0]);
			continue;
		}
		foreach (explode("<!--}", $chunk) as $key2 => $chunk2) {
			if ($key2 == 0) {
				$a = explode("-->", $chunk2, 2);
				$a2 = explode(" ", $a[0], 2);
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
	$lastparser->parsehtml(new recordholder());
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

$lastparser = new commandparser("", $rootparser, null, "(action)");
if ($coverage_list !== null)
	foreach ($coverage_actionlist as $key => $val)
		$coverage_list[$lastparser->index]["\t".base64_encode($key)] = 0;

if ($actionrecordholder !== null) {
	$lastparser->parsehtml($actionrecordholder);
	$actionrecordholder->action();
}
adddebuglog();
print $htmloutput;
log_die();

