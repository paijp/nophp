<?php
# Example of an application on plainphp/lib.php: a list of memos.
#   .../index.php/nofmt/rootpage.html
# Settings: env.php next to this file, or the file named by APP_ENV.
require(__DIR__."/../lib.php");
$sys = new sys();
require(($s = getenv("APP_ENV"))? $s : __DIR__."/env.php");

$SCHEMA = array(
	"simple" => array("name	text unique not null", "contents	text"),
	"login" => array("login	text unique not null", "pass	text", "salt	text", "sessionkey	text", "lastlogin	int", "lastlogout	int", "ismaillogin	int"),
	"memo" => array("owner	int	/*indexed*/"),
);
require(__DIR__."/pages.php");
run(array("index", "rootpage", "memo"));
