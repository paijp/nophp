#
#	Port of env.php, and the WSGI entry point of the site (see nophp.py).
#	Apache: WSGIScriptAlias /nophp/index.php /path/to/py/env.py
#	Development: python3 env.py 8000
#

import os

import nophp
import tables

from phpcompat import PhpArray


def env(sys):
	#
	# In first time, this user will be created.
	#

	sys.defaultuser = "nobody@something.com"

	## If you cant use mail(for setting password), use this.
	# sys.defaultpass = "sample"

	#
	# Filesystem settings.
	#

	sys.htmlbase = "./res/"
	sys.sqlpath = "sqlite:/var/www/db/nophp.sq3"

	#
	# If you want to use '?mode=sql', you have to set this basic authentication.
	# sys.auth_pass = myhash(sys.auth_salt + pass)
	# (mod_wsgi needs "WSGIPassAuthorization On".)
	#

	sys.auth_user = "admin"
	sys.auth_salt = "235ba225813f156690b798dc978099e1e76441ee"
	# sys.auth_pass = ""
	sys.useimporter = 1		# code for ?mode=sql.
	# sys.importlist = PhpArray(["/path/to/data.txt"])

	#
	# If the password on the login form is empty, a mail with URL will be sent.
	#

	sys.mailcmd = "mail -s 'login URL.' @addr@"
	sys.mailbody = "@url@"

	#
	# You can send a report to slack or zulip with debuglog URL.
	#

	# sys.reportjsonurl = "https://hooks.slack.com/services/9999"
	sys.reportjsonbase = PhpArray({"text": "@body@\n<@link@>"})


application = nophp.make_application(env, tables, os.path.dirname(os.path.abspath(__file__)))

if __name__ == "__main__":
	nophp.serve(application)
