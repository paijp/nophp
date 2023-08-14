<?php

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

#$sys->rootpage = "g9999";
# move to tables.php.

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
# You can send a report to slack or zulip with debuglog URL.
# Please use these HTML.
#	<FORM method=POST>
#	<INPUT type=text name=":reportbody" size=60>
#	<INPUT type=submit value="send report">
#	</FORM>
#

# $sys->reportjsonurl = "https://hooks.slack.com/services/9999";
# $sys->reportjsonurl = "https://____.zulipchat.com/api/v1/external/slack_incoming?api_key=____&stream=log&topic=log";
$sys->reportjsonbase = array("text" => "@body@\n<@link@>");

?>
