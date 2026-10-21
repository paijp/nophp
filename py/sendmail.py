#
#	Port of sendmail.php.
#

import re
import subprocess

from engine import G, recordholder, commandparser
from phpcompat import escapeshellarg, php_trim


class recordholder_sendmail(recordholder):
	mailbody = ""

	def h_sendmail(self, rh0=None, record=None):
		contents = php_trim(self.mailbody)
		body = ""
		arg = ""
		option = ""
		isbody = 0
		for line in re.split(r'\r\n|\r|\n', contents):
			if isbody:
				body += line + "\n"
			elif (m := re.match(r'^TO:(.*)', line)):
				arg += " " + escapeshellarg(php_trim(m.group(1)))
			elif (m := re.match(r'^CC:(.*)', line)):
				option += " -c " + escapeshellarg(php_trim(m.group(1)))
			elif (m := re.match(r'^BCC:(.*)', line)):
				option += " -b " + escapeshellarg(php_trim(m.group(1)))
			elif (m := re.match(r'^SUB:(.*)', line)):
				option += " -s '" + re.sub(r'["\'\\\\]', " ", php_trim(m.group(1))) + "'"
			elif (m := re.match(r'^FROM:(.*)', line)):
				option += " -r " + escapeshellarg(php_trim(m.group(1)))
			else:
				isbody = 1
				body += line + "\n"
		subprocess.run(["/bin/sh", "-c", ("mail %s %s" % (option, arg)).encode("utf-8", "surrogateescape")], input=body.encode(), timeout=60)
		return []


class commandparser_sendmail(commandparser):
	def parsehtml(self, tableholder=None, record=None, index=1):
		a = php_trim(self.par).split(" ")
		rh = recordholder_sendmail()
		self.buf = ""
		super().parsehtml(tableholder, record)
		rh.mailbody = self.buf
		G().recordholderlist[a[0]] = rh

	def output(self, html="", htmlhighlight=""):
		super().output(" ", '<span style="color:#888;">' + htmlhighlight + "<span>")
		self.buf += html
