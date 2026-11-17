# Test settings of plainphp/example (see golden.py).
ENV = '''<?php
date_default_timezone_set("Asia/Tokyo");
header('Content-Type: text/html; charset="utf-8"');
$sys->defaultuser = "sample";
$sys->htmlbase = "./res/";
$sys->sqlpath = "sqlite:{db}";
$sys->rootpage = "rootpage";
$sys->auth_user = "";
$sys->mailcmd = "cat > {work}/loginmail.txt";
$sys->mailbody = "@addr@ @url@";
$sys->mailinterval = 1;
'''
