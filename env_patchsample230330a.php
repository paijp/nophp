<?php

#
# ======== 2023/03/30: Security patch sample. ======== 
#
# What is this security risk type?
#	- Shell command injection on httpd user.
#
# At what point does this security risk become apparent?
#	- Send password setting mail or mail-login mail.
#	- Maybe many system have a 'forget password?' link.
#
# Who can use this security risk?
#	- Users who can add or modify the login field in login table to invalid mail address.
#	- In many system, admin user will be able to create or modify a user.
#	- If your system allow users to change login name to invalid mail address, the user can use this security risk. 
#
# How to fix it?
#	- Please use the newest index.php or add this bq_login() function to your env.php.
#
# Important line is here:
# <			if (!preg_match('/^[0-9A-Za-z][-_.@+0-9A-Za-z]*$/', $login))
# <				log_die("invalid character in login.");
#

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


#
# ======== Original env.php ========
#


date_default_timezone_set("Asia/Tokyo");
putenv("LANG=ja_JP.UTF-8");		# for sendmail.php

#
# In first time, this user will be created.
#

$sys->defaultuser = "nobody@something.com";

## If you cant use mail(for setting password), use this.
# $sys->defaultpass = "sample";

#
# Filesystem settings.
#

$sys->htmlbase = "./res/";
$sys->sqlpath = "sqlite:/var/www/db/nophp.sq3";
# $sys->sqlpath = "mysql:dbname=test";		# sorry, not tested.

$sys->rootpage = "g9999";

#
# If you want to use 'index.php?mode=sql', you have to set this basic authentication.
# $sys->auth_pass = sha1( $sys->auth_salt . $pass );
#

$sys->auth_user = "admin";
$sys->auth_salt = "235ba225813f156690b798dc978099e1e76441ee";
# $sys->auth_pass = "";

#
# If the password on the login form is empty, a mail with URL will be sent.
#

$sys->mailcmd = "mail -s 'login URL.' @addr@";
$sys->mailbody = "@url@";

#
# You can send a report to slack with debuglog URL.
#

# $sys->reportjsonurl = "https://hooks.slack.com/services/9999";
$sys->reportjsonbase = array("text" => "@body@\n<@link@>");


?>
