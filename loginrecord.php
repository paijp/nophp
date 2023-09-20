<?php
#
#	nophp	https://github.com/paijp/nophp
#	
#	Copyright (c) 2021-2023 paijp
#
#	This software is released under the MIT License.
#	http://opensource.org/licenses/mit-license.php
#


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
	function	onload() {
		global	$sys;
		
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
						if (!preg_match('/^[0-9A-Za-z][-_.@+0-9A-Za-z]*$/', $login))
							log_die("invalid character in login.");
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
		
		if ($this->is_login() <= 0)
			$sys->target = "index";
		else if ((@$sys->noredirectonlogin))
			;
		else if (@$sys->target == "index") {
			header("Location: {$sys->urlbase}/index/".@$sys->rootpage.".html");
			log_die();
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
			if ((int)@$this->v_ismaillogin == 0)
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
		if (($ismaillogin)) {
			$this->v_mailkey = "";
			$this->v_salt = $this->getrandom();
			$this->v_pass = "";
		}
		$key = $this->getrandom();
		$this->v_sessionkey = myhash($this->v_salt.$key);
		$this->v_submitkey = $this->getrandom();
		$this->v_lastlogin = $sys->now;
		parent::update();
		setcookie("sessionid", $this->id, 0, $cookiepath, "", (@$_SERVER["HTTPS"] == "on"), true);
		setcookie("sessionkey", $key, 0, $cookiepath, "", (@$_SERVER["HTTPS"] == "on"), true);
		log_die("login success.");
	}
	function	check_loginform() {
		global	$sys;
		
		header("Location: {$sys->url}");
		
		if (@$_GET["mode"] == "1login") {
			$uid = (int)@$_GET["uid"];
			$key = @$_GET["key"]."";
			
			$r = $this->getrecord($uid);
			if ((int)@$r->v_ismaillogin != 0)
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
		$uid = (int)@$_GET["uid"];
		$key = @$_GET["key"]."";
		
		$r = $this->getrecord($uid);
		if ((int)@$r->v_ismaillogin == 0)
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


