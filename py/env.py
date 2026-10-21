#
#	Port of env.php.
#

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
	# If the password on the login form is empty, a mail with URL will be sent.
	#

	sys.mailcmd = "mail -s 'login URL.' @addr@"
	sys.mailbody = "@url@"

	#
	# You can send a report to slack or zulip with debuglog URL.
	#

	# sys.reportjsonurl = "https://hooks.slack.com/services/9999"
	sys.reportjsonbase = PhpArray({"text": "@body@\n<@link@>"})
