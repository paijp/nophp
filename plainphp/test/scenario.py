# Scenario of plainphp/example (executed by golden.py). Every call writes one file.
P = BASE
get("first_index", P + "index.html")
get("index_mode0", P + "index.html?mode=0")
get("rootpage_nologin", P + "rootpage.html")
get("noindexphp", "/")

# set the password with the URL of the mail, then log in
submit("mail_request", P + "index.html?mode=0", "send mail", {"login": "sample"})
url = open(work + "/loginmail.txt").read().split(" ", 1)[1].strip()
path = url[url.index("/index.php"):]
get("mail_link", path)
submit("set_password", path, "set password", {"pass": "testpass1"})
submit("login_bad", P + "index.html", "Login", {"login": "sample", "pass": "wrong"})
submit("login", P + "index.html", "Login", {"login": "sample", "pass": "testpass1"})
get("index_loggedin", P + "index.html")
get("nopage", P + "nothing.html")

# the pages and their buttons; texts with characters that must be escaped
get("rootpage_empty", P + "rootpage.html")
submit("add", P + "rootpage.html", "add", {"title": "first <b>memo</b> & \"quotes\""})
submit("add2", P + "rootpage.html", "add", {"title": "second"})
get("rootpage_2", P + "rootpage.html")
get("memo_noid", P + "memo.html")
get("memo_1", P + "memo.html?id=1")
get("memo_99", P + "memo.html?id=99")
submit("save", P + "memo.html?id=1", "save", {"body": "line1\n</textarea><script>x</script>\nline3"})
get("memo_1_saved", P + "memo.html?id=1")

# forged posts: no submitkey, a wrong one
post("post_nokey", P + "rootpage.html", [("title", "no key"), ("do_add", "add")])
post("post_badkey", P + "rootpage.html", [("title", "bad key"), ("submitkey", "0" * 40), ("do_add", "add")])

submit("logout", P + "rootpage.html", "logout")
get("after_logout", P + "rootpage.html")
