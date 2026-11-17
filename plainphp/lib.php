<?php
#
#	nophp	https://github.com/paijp/nophp
#
#	plainphp/lib.php: what an application keeps from nophp when it is rewritten
#	in plain PHP: the database format, the login, the form protection.
#	See plainphp/README.md. Released under the MIT License.
#
# The application defines $SCHEMA (table => column lines, as getconfig() of its
# tables.php gave them, without created/updated/deleted) BEFORE dbopen(), e.g.
#	$SCHEMA = array(
#		"simple" => array("name	text unique not null", "contents	text"),
#		"login" => array("login	text unique not null", "pass	text", "salt	text", "sessionkey	text", "lastlogin	int", "lastlogout	int"),
#		"memo" => array("owner	int	/*indexed*/"),
#	);
# Every table also has id, created, updated, deleted and "mixed": the fields
# that are no columns, as key=rawurlencode(value) separated by tabs.

#[\AllowDynamicProperties]
class	sys {
	var	$sqlpath = "";
	var	$rootpage = "rootpage";
	var	$defaultuser = "";
	var	$defaultpass = "";
	var	$mailcmd = "";			# command that sends the login URL; @addr@ = login name
	var	$mailbody = "";			# its standard input; @url@ = the URL
	var	$mailinterval = 60;
	var	$mailexpire = 1800;
	var	$forcelogoutonupdate = 1;	# an update of the login record ends the session
	var	$now = 0;
	var	$url = "";			# URL of this request without the query
	var	$urlquery = "";
	var	$urlbase = "";			# .../index.php
	var	$target = "";			# page name
	var	$cookiepath = "";
}


function	h($s)
{
	return htmlspecialchars((string)$s, ENT_QUOTES);
}


function	hbr($s)
{
	return nl2br(h($s));
}


if (!function_exists("myhash")) {
	function	myhash($s) {
		return hash("sha256", $s);
	}
}


function	getrandom()
{
	return bin2hex(random_bytes(20));
}


# A value of the query / the form as a string (null when missing or an array).
function	getstr($name)
{
	return is_string($s = @$_GET[$name])? $s : null;
}


function	poststr($name)
{
	return is_string($s = @$_POST[$name])? $s : null;
}


# Digits and commas of a query value: what nophp's ":g" gave (`id__:g`).
function	getnum($name)
{
	return preg_replace("/[^,0-9]/", "", (string)getstr($name));
}


function	sql($sql, $list = array())
{
	global	$db;
	
	$sp = $db->prepare($sql);
	$sp->execute($list);
	return $sp->fetchAll(PDO::FETCH_ASSOC);
}


# Fills in created/updated/deleted, opens the database and, when it is new,
# makes the tables exactly as nophp made them, and the default user.
function	dbopen()
{
	global	$db, $sys, $SCHEMA;
	
	foreach ($SCHEMA as $k => $v)
		if (!in_array("created	int", $v))
			$SCHEMA[$k] = array_merge(array("created	int", "updated	int", "deleted	int"), $v);
	
	$db = new PDO($sys->sqlpath);
	$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	if (count(sql("select name from sqlite_master where type = ? and name = ?", array("table", "login"))) > 0)
		return;
	
	foreach ($SCHEMA as $name => $cols) {
		$s = implode(",", $cols);
		$db->exec("create table if not exists {$name}(\n\tid	integer primary key, \n\tmixed	text, \n\t{$s}\n);");
		foreach ($cols as $line)
			if (strpos($line, "/*indexed*/") !== FALSE) {
				list($field) = preg_split("/[ \t]+/", trim($line), 2);
				$db->exec("create index if not exists {$name}{$field}_index on {$name}({$field});");
			}
	}
	if ((string)$sys->defaultuser == "")
		return;
	$r = rec::get("login");
	$r->f["login"] = $sys->defaultuser;
	if ((string)$sys->defaultpass != "") {
		$r->f["salt"] = getrandom();
		$r->f["pass"] = myhash($sys->defaultpass.$r->f["salt"]);
		$r->f["sessionkey"] = "";
		$r->f["mailkey"] = "";
		$r->save();
	} else
		login_update($r);
}


# A record: $f holds the fields (columns and the fields in "mixed" alike).
class	rec {
	var	$table;
	var	$id = 0;
	var	$f = array();
	static function	get($table, $id = 0) {
		$r = new rec();
		$r->table = $table;
		if (($id = (int)$id) > 0)
			foreach (sql("select * from {$table} where id = ?", array($id)) as $row)
				$r->load($row);
		return $r;
	}
	static function	fromrow($table, $row) {
		$r = new rec();
		$r->table = $table;
		$r->load($row);
		return $r;
	}
	static function	select($table, $cond = "", $list = array()) {
		$a = array();
		foreach (sql("select * from {$table} {$cond}", $list) as $row)
			$a[] = rec::fromrow($table, $row);
		return $a;
	}
	# Like select(), one record at a time (for whole tables).
	static function	each($table, $cond = "", $list = array()) {
		global	$db;
		
		$sp = $db->prepare("select * from {$table} {$cond}");
		$sp->execute($list);
		while (($row = $sp->fetch(PDO::FETCH_ASSOC)) !== FALSE)
			yield rec::fromrow($table, $row);
	}
	function	load($row) {
		$mixed = null;
		foreach ($row as $key => $val)
			if ($key == "id")
				$this->id = (int)$val;
			else if ($key == "mixed")
				$mixed = $val;
			else
				$this->f[$key] = $val;
		foreach (explode("\t", (string)$mixed) as $chunk)
			if (count($a = explode("=", $chunk, 2)) == 2)
				$this->f[$a[0]] = rawurldecode($a[1]);
	}
	# Field value; null when the record has no such field.
	function	v($name) {
		return @$this->f[$name];
	}
	# The columns (and "mixed") as save() writes them.
	function	columns() {
		global	$SCHEMA;
		
		$fieldlist = array();
		foreach ($SCHEMA[$this->table] as $line) {
			list($name) = preg_split("/[ \t]+/", $line, 2);
			$fieldlist[$name] = (string)@$this->f[$name];
		}
		# everything else goes to "mixed", fields this code does not know included
		$mixedlist = array();
		foreach ($this->f as $name => $val)
			if (!array_key_exists($name, $fieldlist) && ((string)$name != "") && (strpbrk((string)$name, "=\t") === FALSE))
				$mixedlist[] = $name."=".rawurlencode((string)$val);
		$fieldlist["mixed"] = implode("\t", $mixedlist);
		return $fieldlist;
	}
	function	save() {
		global	$sys, $db;
		
		if ($this->id <= 0)
			$this->f["created"] = $this->f["updated"] = $sys->now;
		else
			$this->f["updated"] = $sys->now;
		$fieldlist = $this->columns();
		
		if ($this->id <= 0) {
			$skeys = implode(", ", array_keys($fieldlist));
			$svals = implode(", ", array_fill(0, count($fieldlist), "?"));
			sql("insert into {$this->table}({$skeys}) values({$svals});", array_values($fieldlist));
			$this->id = (int)$db->lastInsertId();
		} else {
			$a = array();
			foreach ($fieldlist as $key => $val)
				$a[] = "{$key} = ?";
			$list = array_values($fieldlist);
			$list[] = $this->id;
			sql("update {$this->table} set ".implode(", ", $a)." where id = ?", $list);
		}
	}
}


# ---- login (as loginrecord.php / a_login_table of nophp)

$loginrecord = null;


# Save a login record the way a_login_table::update() did: keeps salt and pass
# of the database and ends the session (forcelogoutonupdate). Whatever the
# login_table of the application's tables.php added in its own update() goes
# into login_beforeupdate($r), if the application defines it.
function	login_update($r)
{
	global	$sys;
	
	if (function_exists("login_beforeupdate"))
		login_beforeupdate($r);
	$r0 = rec::get("login", $r->id);
	$r->f["salt"] = $r0->v("salt");
	$r->f["pass"] = $r0->v("pass");
	if (($sys->forcelogoutonupdate)) {
		$r->f["sessionkey"] = "";
		$r->f["mailkey"] = "";
	}
	$r->save();
}


function	is_login()
{
	global	$loginrecord;
	
	$loginrecord = null;
	if (!is_string($sessionid = @$_COOKIE["sessionid"]))
		return 0;
	if (!is_string($key = @$_COOKIE["sessionkey"]))
		return 0;
	$r = rec::get("login", $sessionid);
	if ($r->id <= 0)
		return 0;
	if ((string)$r->v("submitkey") == "")
		return 0;
	if ((string)$r->v("sessionkey") == "")
		return 0;
	if (!hash_equals((string)$r->v("sessionkey"), myhash($r->v("salt").$key)))
		return 0;
	$loginrecord = $r;
	return 1;
}


# Is this a POST of a form of ours (the submitkey of the session came back)?
function	ispost()
{
	global	$loginrecord;
	
	if ($loginrecord === null)
		return 0;
	if ((string)$loginrecord->v("submitkey") == "")
		return 0;
	if (!is_string($s = @$_POST["submitkey"]))
		return 0;
	return hash_equals((string)$loginrecord->v("submitkey"), $s)? 1 : 0;
}


function	sessioncookie($name, $value)
{
	global	$sys;
	
	setcookie($name, (string)$value, array("expires" => ($value === "")? 1 : 0, "path" => $sys->cookiepath,
		"secure" => (@$_SERVER["HTTPS"] == "on"), "httponly" => TRUE, "samesite" => "Lax"));
}


# End of a request: commit, and nothing more is sent.
# (A plain die() elsewhere leaves the transaction open: the request is undone.)
function	finish($message = "")
{
	global	$db;
	
	if ((@$GLOBALS["checkmode"]))
		throw new Exception("finish");		# check.php: nothing is committed
	if ($db->inTransaction())
		$db->commit();
	if ((@$GLOBALS["pagebuffer"]))
		ob_end_clean();		# a page that was being made is not sent
	die($message);
}


function	redirect($url)
{
	header("Location: {$url}");
	finish();
}


# Back to a page after an action (nophp: "...:popall:curpage__id__id__:g:jump"):
# jump("item", "id", 5)
function	jump($page, $key = null, $val = null)
{
	global	$sys;
	
	$s = "{$sys->urlbase}/nofmt/{$page}.html";
	if ($key !== null)
		$s .= "?{$key}={$val}";
	redirect($s);
}


function	login_start($r, $ismaillogin = 0)
{
	global	$sys;
	
	$key = getrandom();
	$r->f["sessionkey"] = myhash($r->v("salt").$key);
	$r->f["submitkey"] = getrandom();
	$r->f["lastlogin"] = $sys->now;
	if (($ismaillogin)) {
		$r->f["mailkey"] = "";
		$r->f["pass"] = "";
	}
	$r->save();
	sessioncookie("sessionid", $r->id);
	sessioncookie("sessionkey", $key);
	finish("login success.");
}


# The login form (submit name ":login") was sent, or its page is shown.
# This is the behaviour of nophp of 2023-03 (with v_ismaillogin). For the
# differences of later versions see README.md, "ログイン".
# Every answer is a redirect to the login page; the text is only for logs.
function	login_request()
{
	global	$sys;
	
	$uid = (int)getstr("uid");
	$key = (string)getstr("key");
	
	if (@$_POST[":login"] === null) {
		# A link of a mail for a user who logs in by mail only.
		if (getstr("mode") != "1login")
			return;
		$r = rec::get("login", $uid);
		if ((int)$r->v("ismaillogin") == 0)
			return;
		header("Location: {$sys->url}");
		if ((string)$r->v("mailkey") == "")
			finish("mailkey empty.");
		if (($sys->mailexpire > 0)&&((int)$r->v("mailsent") < $sys->now - $sys->mailexpire))
			finish("mailexpire.");
		if (hash_equals((string)$r->v("mailkey"), myhash($r->v("login").$key)))
			login_start($r, 1);
		finish("maillogin fail.");
	}
	
	if ((string)poststr("pass") == "") {
		# No password: send a mail with the URL that sets one.
		header("Location: {$sys->url}?mode=2mailsent");
		if ((string)$sys->mailcmd == "")
			finish("mailcmd empty.");
		if ((string)$sys->mailbody == "")
			finish("mailbody empty.");
		if ($sys->mailinterval <= 0)
			finish("mailinterval <= 0.");
		if (($login = (string)poststr("login")) == "")
			finish("login empty.");
		# $login goes into a shell command: nothing but these characters
		if (!preg_match('/^[0-9A-Za-z][-_.@+0-9A-Za-z]*$/', $login))
			finish("invalid character in login.");
		$list = rec::select("login", "where login = ?", array($login));
		if (count($list) < 1)
			finish("no login found");
		$r = $list[0];
		if ((int)$r->v("mailsent") > $sys->now - $sys->mailinterval)
			finish("mail interval too short.");
		
		$key = getrandom();
		$r->f["mailkey"] = myhash($r->v("login").$key);
		$r->f["mailsent"] = $sys->now;
		$r->save();
		
		$a0 = array("@addr@", "@url@");
		$a1 = array($login, "{$sys->url}?mode=1login&uid={$r->id}&key={$key}&");
		if (($fp = popen(str_replace($a0, $a1, $sys->mailcmd), "w")) === FALSE)
			finish("popen failed.");
		fputs($fp, str_replace($a0, $a1, $sys->mailbody));
		pclose($fp);
		finish("mail sent.");
	}
	
	header("Location: {$sys->url}");
	if (getstr("mode") == "1login") {
		# The URL of the mail: set the password.
		$r = rec::get("login", $uid);
		if ((int)$r->v("ismaillogin") != 0)
			finish("ismaillogin.");
		if ((string)$r->v("mailkey") == "")
			finish("mailkey empty.");
		if (($sys->mailexpire > 0)&&((int)$r->v("mailsent") < $sys->now - $sys->mailexpire))
			finish("mailexpire.");
		if (!hash_equals((string)$r->v("mailkey"), myhash($r->v("login").$key)))
			finish("maillogin fail.");
		$r->f["salt"] = getrandom();
		$r->f["pass"] = myhash(poststr("pass").$r->f["salt"]);
		$r->f["sessionkey"] = "";
		$r->f["mailkey"] = "";
		$r->save();
		finish("password change success.");
	}
	
	if (($login = poststr("login")) === null)
		finish("login null.");
	$list = rec::select("login", "where login = ?", array($login));
	if (count($list) < 1)
		finish("login fail.");
	$r = $list[0];
	if (((int)$r->v("ismaillogin") != 0)||((string)$r->v("pass") == ""))
		finish("login fail.");
	if (!hash_equals((string)$r->v("pass"), myhash(poststr("pass").$r->v("salt"))))
		finish("login fail.");
	login_start($r);
}


function	logout()
{
	global	$loginrecord, $sys;
	
	if ($loginrecord !== null) {
		$loginrecord->f["lastlogout"] = $sys->now;
		$loginrecord->f["sessionkey"] = "";
		login_update($loginrecord);
	}
	sessioncookie("sessionid", "");
	sessioncookie("sessionkey", "");
	redirect("{$sys->urlbase}/logout/{$sys->rootpage}.html");
}


# <form> and </form> of every page (nophp added both to the template's tags):
# the query stays in the action, the submitkey goes along.
function	formopen()
{
	global	$sys;
	
	return '<form method="POST" action="?'.h($sys->urlquery).'">';
}


function	formclose()
{
	global	$loginrecord;
	
	$s = "";
	if ($loginrecord !== null)
		$s = '<INPUT type=hidden name=submitkey value="'.h($loginrecord->v("submitkey")).'">'."\n";
	return $s."</form>";
}


# The front controller: index.php of the application is
#	require("lib.php"); $sys = new sys(); require(<settings>); $SCHEMA = ...;
#	require(<pages>);  run(array("index", "rootpage", ...));
# A page is a function page_<name>() that prints the page and returns the
# action of the pressed button (a callable) or null. The page is made first
# (nophp did so, and making a page may store things), then the action runs;
# an action ends with jump() / redirect(); when it returns, the page is sent.
function	run($pages)
{
	global	$sys, $db, $pagebuffer;
	
	header("X-Content-Type-Options: nosniff");
	header("Referrer-Policy: same-origin");		# URLs hold keys (mail login) and ids
	
	$sys->now = time();
	$sys->url = (@$_SERVER["HTTPS"] == "on")? "https://" : "http://";
	$a = explode("?", (string)@$_SERVER["REQUEST_URI"], 2);
	$sys->url .= @$_SERVER["HTTP_HOST"].$a[0];
	$sys->urlquery = (string)@$a[1];
	
	if (preg_match('%://[^/]+/(.*)/index[.]php%', $sys->url, $a))
		$sys->cookiepath = "/".$a[1];
	if (!preg_match('%^((.*)/index[.]php)(.*)$%', $sys->url, $a)) {
		header("Location: {$sys->url}/index.php");
		die();
	}
	$sys->urlbase = $a[1];
	if (!preg_match('%^/([^/]*)/([0-9A-Za-z]+)[.]html$%', $a[3], $a2)) {
		header("Location: {$sys->urlbase}/nofmt/{$sys->rootpage}.html");
		die();
	}
	$sys->target = $a2[2];
	
	dbopen();
	$db->beginTransaction();
	
	if (is_login() <= 0)
		$sys->target = "index";
	else if ($sys->target == "index")
		redirect("{$sys->urlbase}/index/{$sys->rootpage}.html");
	if (!in_array($sys->target, $pages))
		redirect("{$sys->urlbase}/nopage/{$sys->rootpage}.html");
	
	ob_start();
	$pagebuffer = 1;
	$action = call_user_func("page_".$sys->target);
	$html = ob_get_clean();
	$pagebuffer = 0;
	if ($action !== null)
		call_user_func($action);
	print $html;
	finish();
}
