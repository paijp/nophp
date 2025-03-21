<?php
#
#	nophp	https://github.com/paijp/nophp
#	
#	Copyright (c) 2021-2024 paijp
#
#	This software is released under the MIT License.
#	http://opensource.org/licenses/mit-license.php
#


/*jp.pa-i/html
<p><a href="https://github.com/paijp/diagram-in-comment">How to generate this?</a></p>
*/
/* output: https://paijp.github.io/nophp/index.html */


class	sys {
	var	$htmlbase = "./res/nofmt/";
	var	$rootpage = "g0000";
#	var	$sqlpath = "sqlite:/var/www/db/v1.sq3";
##	var	$sqlpath = "mysql:dbname=test";
	var	$debugdir = null;
	var	$debugmaxlogrecords = 500;
	var	$debugchunksize = 4000;	# atomic writable size: 4000 for linux, 900 for windows.
	var	$debuggz = 1;		# 1:compress debuglog and coveragelog
	var	$debugdiff = 1;		# 1:show diff on debuglog
	
	var	$mailinterval = 60;
	var	$mailexpire = 1800;
	var	$forcelogoutonupdate = 1;
	var	$noredirectonlogin = 0;		# 1: don't redirect from index.html to rootpage on login status.
	
	var	$importlist = array();
	
	var	$urlbase = null;
	var	$target = null;
	function	__construct() {
		foreach (glob("debuglog???????*", GLOB_ONLYDIR) as $val) {
			$this->debugdir = $val;
			break;
		}
		list($m, $s) = explode(" ", microtime());
		$this->debugfn = date("ymd_His_", (int)$s).substr($m, 2);
		$this->now = $s;
	}
}
$sys = new sys();

if (@$_SERVER["HTTPS"] == "on")
	$sys->url = "https://";
else
	$sys->url = "http://";
$a = explode("?", @$_SERVER["REQUEST_URI"], 2);
$sys->url .= @$_SERVER["HTTP_HOST"].$a[0];
$sys->urlquery = @$a[1];


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
	
	if ($debugdir === null) {
		if (@$sys->debugdir === null)
			return;
		if (!is_dir($debugdir = @$sys->debugdir)) {
			$sys->debugdir = null;
			$sys->debugfn = null;
			return;
		}
	}
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
		file_add_contents($fn, "<!-- ".date("ymd_His", (int)$a[1]).substr($a[0], 1)." -->{$debuglog}", $sys->debuggz);
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


$db0 = null;


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
		execsql("commit;", null, 0, 1);
	
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
		if (count($idlist) > $sys->debugmaxlogrecords)
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
				$s0 .= "\t".base64_encode($v)."\t".base64_encode((int)@$coverage_count[$k]);
		addcoveragelog($fn, $s0."\n");
		foreach ($coverage_list as $key => $val)
			foreach ($val as $key2 => $val2)
				addcoveragelog($fn, "{$sys->debugfn}#{$val2}\t{$key}{$key2}\n");
		addcoveragelog($fn);
	}
	die($message);
}


function	execsql($sql = null, $array = null, $returnid = 0, $ignoreerror = 0)
{
	global	$db0;
	global	$sys;
	global	$debuglog;
	
	if ($db0 === null) {
		$db0 = new PDO($sys->sqlpath);
		$db0->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
	}
	if ($sql === null)
		return null;
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
					$this->setfield($key, $val);
					continue 2;
				case	"id":
					$this->id = (int)$val;
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
				$this->setfield($key2, rawurldecode($val2));
			}
	}
	function	getconfig() {
		return <<<EOO
created	int
updated	int
deleted	int

EOO;
	}
	function	onload() {
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
			$idlist[] = (int)$record["id"];
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
	function	getfield($field) {
		if ($field != "id")
			$field = "v_{$field}";
		return @$this->$field;
	}
	function	setfield($field, $val) {
		if ($field != "id")
			$field = "v_{$field}";
		$this->$field = $val;
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
			$this->id = (int)execsql($sql, $a, 1);
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
		execsql();
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
		$r = $this->getrecord((int)$s1);
		return array($r->getfield($s3)."");
	}
	function	t_find__val__field($rh0, $record, $val, $field) {
## Take three strings from the stack, consider the first as a field value, the second as a field name, and the third as a table name, and find the record ID whose table contains the field value and stack it on the stack.
## Returns an empty string if no field value was found. If multiple records match, the one with the smallest ID is returned.
## For example, `john__name__user__:t_find` is the record ID of the record whose value in the name field of the user table is john.
		foreach ($this->getrecordidlist() as $id) {
			$r = $this->getrecord($id);
			if (@$r->getfield($field) == $val)
				return array($id);
		}
		return array("");
	}
	function	h_set__val__field($rh0, $record, $val, $field) {
## Take three strings from the stack, consider them as field value, field name, and table name, respectively, and set them to the current record of the specified table.
## Table names must be declared in advance with "<!--{tableid" etc. must be declared beforehand.
## For example, `1__id__user__:t_set` sets the ID of the current record in the user table to 1.
		$this->setfield($field, $val);
		return array();
	}
	function	hs_update($rh0, $record = null) {
## Take a string from the stack, consider it a table name, and UPDATE or INSERT a record in that table.
## The table must be specified as "<!--{tableid", etc.
		$s = "";
		if ((int)$this->update() == 0)
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
		if ((($i = $a2[1]) != "")&&(strlen($s) < (int)$i))
			return 1;
		if ((($i = $a2[2]) != "")&&(strlen($s) > (int)$i))
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
		if ((($i = $a2[1]) != "")&&((int)$s < (int)$i))
			return 1;
		if ((($i = $a2[2]) != "")&&((int)$s > (int)$i))
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
			foreach ($list as $key => $val)
				$this->setfield($key, $val);
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
	function	getfield($field) {
		if ($field != "id")
			$field = "v_{$field}";
		return @$this->$field;
	}
	function	setfield($field, $val) {
		if ($field != "id")
			$field = "v_{$field}";
		$this->$field = $val;
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
	function	h_set__val__field($rh0, $record, $val, $field) {
## Take three strings from the stack, consider them as field value, field name, and table name, respectively, and set them to the current record of the specified table.
## Table names must be declared in advance with "<!--{tableid" etc. must be declared beforehand.
## For example, `1__id__user__:t_set` sets the ID of the current record in the user table to 1.
		$this->setfield($field, $val);
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
		$r = $this->getrecord((int)$s1);
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


$loginrecord = null;
class	rootrecord {
	var	$tablename = "root";
	var	$methodlist = null;
	function	getfield($field) {
		if ($field != "id")
			$field = "v_{$field}";
		return @$this->$field;
	}
	function	setfield($field, $val) {
		if ($field != "id")
			$field = "v_{$field}";
		$this->$field = $val;
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
				if (@$rh->methodlist[$a[0]] !== null)
					trigger_error("duplexed method({$name} in ".get_class($rh).")");
				$rh->methodlist[$a[0]] = $name;
			}
		}
		if (($rh->record !== null)&&($rh->record->methodlist === null)) {
			$rh->record->methodlist = array();
			foreach (get_class_methods($rh->record) as $name) {
				if (!preg_match('/^[a-z]s?_/', $name))
					continue;
				$a = explode("__", $name);
				if (@$rh->record->methodlist[$a[0]] !== null)
					trigger_error("duplexed method({$name} in ".get_class($rh->record).")");
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
				if (@$t->methodlist[$a[0]] !== null)
					trigger_error("duplexed method({$name} in ".get_class($t).")");
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
				if (@$t->methodlist[$a[0]] !== null)
					trigger_error("duplexed method({$name} in ".get_class($t).")");
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
			if (($v = (int)@$coverage_count[$coverage_id]) < 1)
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

/*jp.pa-i/html
<h2>backtick syntax</h2>
*/

/*jp.pa-i/syntaxdiagram
(`
{{|[literal
|{(:
[command
r}r(__
}(`
*/

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
		$this->remaincmds = array();
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
							$id2 = round((float)$id2);
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
								if ((preg_match('/^bq_(.*)/', $s, $a0)))
									;
								else if ((preg_match('/^bq2_(.*)/', $s, $a0)))
									;
								else 
									continue;
								list($s0) = explode("__", $a0[1], 2);
								if (@$funclist[$s0] !== null)
									trigger_error("duplexed function({$s})");
								$funclist[$s0] = $s;
							}
						}
						if (($s = @$funclist[$cmd]) === null) {
							trigger_error($s = "unknown command({$cmd} in ".get_class($this).")");
							$debuglog .= "<h3>".htmlspecialchars($s)."</h3>";
							$this->pushstack(array());
							break;
						}
						$a = explode("__", $s);
						array_shift($a);
						$a0 = $this->popstack($cmd, implode(" ", $a));
						if (preg_match('/^bq2_/', $s)) {
							array_unshift($a0, $record);
							array_unshift($a0, $this);
						}
						$a = call_user_func_array($s, $a0);
						if (!is_array($a))
							return $a;		# avoid debuglog
						$this->pushstack($a);
						break;
#* bq
					case	"nl2br":
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
					case	"andbreak":
## Retrieve a string from the stack and continue if it is an empty string or "0", otherwise terminate evaluation of the expression there and return an empty string.
## For example, `0__:andbreak__a` would be "a".
## On the other hand, `1__:andbreak__a` will be an empty string (no evaluation after __a).
## This is used, for example, `id__:g:dup:isnull:andbreak__table__field__:tableid` to terminate evaluation there if "id__:g" is an empty string.
						list($s1) = $this->popstack($cmd, "val");
						if ((int)$s1 != 0) {
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
						if ((int)$s1 != 0) {
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
						if ((int)$s1 == 0) {
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
						if ((int)$s1 == 0) {
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
						if ((int)$s1 != 0) {
							$val = (int)substr($cmd, -1);
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
						if ((int)$s1 == 0) {
							$val = (int)substr($cmd, -1);
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
						if ((int)$s1 != 0) {
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
						if ((int)$s1 != 0) {
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
						if ((int)$s1 == 0) {
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
						if ((int)$s1 == 0) {
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
			case	"nl2br":
				$output .= nl2br(htmlspecialchars($s, ENT_QUOTES));
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
	function	parsenamehtmlhighlight($text) {
		return str_replace("`", "`!", $this->parsename($text));
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
#		$s = "v_".$name;
#		$this->record->$s = $val;
		$this->record->setfield($name, $val);
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
	function	parsehtmlhighlight($htmlhighlight = "") {
		global	$tablelist;
		global	$actionrecordholder;
		global	$beforename;
		global	$beforenopost;
		global	$loginrecord;
		global	$sys;
		global	$coverage_actionlist;
		
		$output = "";
		foreach (explode("<", $htmlhighlight) as $key => $chunk) {
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
				$taghighlight .= '`| action="?'.str_replace("`", "`!", $sys->urlquery).'"`|';
			else if (($tagtype == "/form")&&($loginrecord !== null))
				$output .= '`|<INPUT type=hidden name=submitkey value="'.str_replace("`", "`!", $loginrecord->v_submitkey).'">`|'."\n";
			else if (($tagtype == "input")&&($type == "submit") && preg_match('/name="([^"]+)"/', $tag, $a)) {
				if ($this->prefix != "")
					$taghighlight = preg_replace('/name="/', 'name="`|'.str_replace("`", "`!", $this->prefix)."`|", $taghighlight, 1);
				$coverage_actionlist[$a[1]] = 1;
				$postkey = $this->prefix.str_replace(array(" ", "."), "_", $a[1]);
				if ((ispost())&&(@$_POST[$postkey] !== null)) {
					$actionrecordholder = $this;
					$this->actioncommand = $a[1];
				} else if (($loginrecord === null)&&($a[1] == ":login")&&(@$_POST[":login"] === null))
					$tablelist["login"]->check_maillogin();
				else if (($loginrecord === null)&&($a[1] == ":login")) {
					if (@$_POST["pass"] == "")
						bq_login("emptypass");
					else
						$tablelist["login"]->check_loginform();
					log_die();
				}
			} else if (($tagtype == "input")&&($type == "checkbox") && preg_match('/name="([^"]+)/', $tag, $a) && preg_match('/value="([^"]+)/', $tag, $a2)) {
				if ($this->prefix != "")
					$taghighlight = preg_replace('/name="/', 'name="`|'.str_replace("`", "`!", $this->prefix)."`|", $taghighlight, 1);
				$this->postname($a[1], $a2[1]);
				if ($this->parsename($a[1]) == $a2[1])
					$taghighlight .= "`| checked`|";
			} else if (($tagtype == "input")&&($type == "radio") && preg_match('/name="([^"]+)/', $tag, $a) && preg_match('/value="([^"]+)/', $tag, $a2)) {
				if ($this->prefix != "")
					$taghighlight = preg_replace('/name="/', 'name="`|'.str_replace("`", "`!", $this->prefix)."`|", $taghighlight, 1);
				$this->postname($a[1], $a2[1], 1);
				if ($this->parsenamehtmlhighlight($a[1]) == $a2[1])
					$taghighlight .= "`| checked`|";
			} else if (($tagtype == "input") && preg_match('/name="([^"]+)"/', $tag, $a) && preg_match('/x-value="([^"]*)"/', $tag, $a2)) {
				if ($this->prefix != "")
					$taghighlight = preg_replace('/name="/', 'name="`|'.str_replace("`", "`!", $this->prefix)."`|", $taghighlight, 1);
				$this->postname($a[1], null, 0, $a2[1]);
				$taghighlight .= ' `|value="'.htmlspecialchars(@$this->parsenamehtmlhighlight($a[1]), ENT_QUOTES).'"`|';
			} else if (($tagtype == "input") && preg_match('/name="([^"]+)"/', $tag, $a) && preg_match('/value=/', $tag)) {
				if ($this->prefix != "")
					$taghighlight = preg_replace('/name="/', 'name="`|'.str_replace("`", "`!", $this->prefix)."`|", $taghighlight, 1);
				$this->postname($a[1]);
			} else if (($tagtype == "input") && preg_match('/name="([^"]+)"/', $tag, $a)) {
				if ($this->prefix != "")
					$taghighlight = preg_replace('/name="/', 'name="`|'.str_replace("`", "`!", $this->prefix)."`|", $taghighlight, 1);
				$this->postname($a[1]);
				$taghighlight .= ' `|value="'.htmlspecialchars(@$this->parsenamehtmlhighlight($a[1]), ENT_QUOTES).'"`|';
			} else if (($tagtype == "select") && preg_match('/name="([^"]+)"/', $tag, $a) && preg_match('/x-value="([^"]*)"/', $tag, $a2)) {
				if ($this->prefix != "")
					$taghighlight = preg_replace('/name="/', 'name="`|'.str_replace("`", "`!", $this->prefix)."`|", $taghighlight, 1);
				$beforename = $a[1];
				$beforenopost = $a2[1];
			} else if (($tagtype == "select") && preg_match('/name="([^"]+)"/', $tag, $a)) {
				if ($this->prefix != "")
					$taghighlight = preg_replace('/name="/', 'name="`|'.str_replace("`", "`!", $this->prefix)."`|", $taghighlight, 1);
				$beforename = $a[1];
				$beforenopost = null;
			} else if (($tagtype == "option") && preg_match('/value="([^"]*)"/', $tag, $a)) {
				$this->postname($beforename, $a[1], 1, $beforenopost);
				if (@$this->parsename($beforename) == $a[1])
					$taghighlight .= " `|selected`|";
			} else if (($tagtype == "textarea") && preg_match('/name="([_0-9A-Za-z]+)"/', $tag, $a) && preg_match('/x-value="([^"]*)"/', $tag, $a2)) {
				if ($this->prefix != "")
					$taghighlight = preg_replace('/name="/', 'name="`|'.str_replace("`", "`!", $this->prefix)."`|", $taghighlight, 1);
				$this->postname($a[1], null, 0, $a2[1]);
				if ($body == "")
					$body = "`|".htmlspecialchars(@$this->parsenamehtmlhighlight($a[1]), ENT_QUOTES)."`|";
			} else if (($tagtype == "textarea") && preg_match('/name="([_0-9A-Za-z]+)"/', $tag, $a)) {
				if ($this->prefix != "")
					$taghighlight = preg_replace('/name="/', 'name="`|'.str_replace("`", "`!", $this->prefix)."`|", $taghighlight, 1);
				$this->postname($a[1]);
				if ($body == "")
					$body = "`|".htmlspecialchars(@$this->parsenamehtmlhighlight($a[1]), ENT_QUOTES)."`|";
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
		$this->record = $t->getrecord((int)$rh->parsewithbq($par, $record));
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
		$this->record = $t->getrecord((int)$rh->parsewithbq($par, $record));
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
			if (($frag = (int)@$coverage_count[$index]) <= 0)
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
			if (($count = (int)@$coverage_count[@$index]) > 1)
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
#		$a = explode(" ", trim($rh->parsewithbq($this->par, $record)), 2);
		$a = explode(" ", $this->par, 2);
		$s0 = $rh->parsewithbq($a[0], $record);
		
		if (count($a2 = explode("=", $s0, 2)) == 1) {
			$tablename = $alias = $s0;
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

/*jp.pa-i/html
<h2>if section</h2>
*/

/*jp.pa-i/syntaxdiagram
(<!--{if
[expr
(-->
[html block
{|(<!--}{elseif
[expr
(-->
[html block
r}{|(<!--}{else-->
[html block
}(<!--}-->
*/

class	commandparser_if extends commandparser {
	function	parsehtmlinner($rh = null, $record = null) {
		$this->cond = ((int)$rh->parsewithbq($this->par, $record) == 0)? 0 : 1;
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
		$this->cond = ((int)$rh->parsewithbq($this->par, $record) == 0)? 0 : 1;
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

/*jp.pa-i/html
<h2>selectrows section</h2>
*/

/*jp.pa-i/syntaxdiagram
(<!--{selectrows
[from-clause
(-->
[html block
{|(<!--}{else-->
[html block
}(<!--}-->
*/

class	commandparser_selectrows extends commandparser {
# The "<!--{selectrows SQL clause-->" to "<!--}-->" is repeated as many times as the number of search results obtained with the specified SQL clause.
# For example, "<!--{selectrows from customer limit 10-->`id__:r`<!--}-->", "select * from customer limit 10" is executed and each line obtained is output with "`id__:r`".
# In placeholders, the row of the search result is treated as the current record, but for ":curtable", ":set", etc., the outer current record is accessed.
	function	parsehtmlinner($rh = null, $record = null) {
		if (($record === null)&&($rh !== null))
			$record = $rh->record;
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

/*jp.pa-i/html
<h2>stablerows section</h2>
*/

/*jp.pa-i/syntaxdiagram
(<!--{stablerows
[simple table name
(-->
[html block
{|(<!--}{else-->
[html block
}(<!--}-->
*/

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

/*jp.pa-i/html
<h2>dayrows section</h2>
*/

/*jp.pa-i/syntaxdiagram
(<!--{dayrows
[UNIX time
{|( 
[count
{|( 
[date format
}}(-->
[html block
(<!--}-->
*/

class	commandparser_dayrows extends commandparser {
	function	parsehtmlinner($rh = null, $record = null) {
		$a = explode(" ", $rh->parsewithbq($this->par, $record), 3);
		$t = (int)$a[0];
		if (($count = (int)@$a[1]) == 0)
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

/*jp.pa-i/html
<h2>wdayrows section</h2>
*/

/*jp.pa-i/syntaxdiagram
(<!--{wdayrows
[UNIX time
{|( 
[count
{|( 
[start
{|( 
[date format
}}}(-->
[html block
(<!--}-->
*/

class	commandparser_wdayrows extends commandparser {
	function	parsehtmlinner($rh = null, $record = null) {
		$a = explode(" ", $rh->parsewithbq($this->par, $record), 4);
		$t = (int)$a[0];
		if (($count = (int)@$a[1]) == 0)
			$count = 1;
		$start = (int)@$a[2];
		
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

/*jp.pa-i/html
<h2>valid section</h2>
*/

/*jp.pa-i/syntaxdiagram
(<!--{valid
[validation command
{|( 
[field name
}(-->
[html block
{|(<!--}{else-->
[html block
}(<!--}-->
*/

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


if (!function_exists("myhash")) {
	function	myhash($s) {
		return hash("sha256", $s);
	}
}
if (trim(myhash("")) == "")
	die("hash not work");

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
			$key = (int)$a[1];
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
					if (($v = (int)$a[1]) < 0)
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


new innersimpletable();

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


foreach ($tablelist as $obj)
	$obj->onload();


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
			else {
				trigger_error("stack empty.");
				$beforeparser = $parserstack[0];
			}
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

