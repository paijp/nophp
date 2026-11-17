<?php
# Settings of the example (the env.php of a nophp application works as it is:
# it sets $sys->...; what the new code does not use is ignored).
date_default_timezone_set("Asia/Tokyo");
header('Content-Type: text/html; charset="utf-8"');
$sys->defaultuser = "sample";
$sys->sqlpath = "sqlite:/var/tmp/plainphp-example.sq3";
$sys->rootpage = "rootpage";
$sys->mailcmd = "mail -s 'login URL.' @addr@";
$sys->mailbody = "@url@";
