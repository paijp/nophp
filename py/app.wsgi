#
#	WSGI entry point (replacement of index.php).
#	Apache: WSGIScriptAlias /nophp-py /path/to/py/app.wsgi
#	URL:    /nophp-py/nofmt/g0000.html
#	To replace index.php with the same URLs (and cookies):
#	        WSGIScriptAlias /nophp/index.php /path/to/py/app.wsgi
#

import os
import sys as _sys

BASEDIR = os.path.dirname(os.path.abspath(__file__))
# The directory of index.php in PHP: relative paths (res/, imeilog/, ...) are relative to it.
# Set it when the Python files are put outside of the document root.
WORKDIR = BASEDIR
_sys.path.insert(0, BASEDIR)

import engine		# noqa: E402
import tables		# noqa: E402
import debuglog		# noqa: E402


class _Response(list):
	"""The body; close() (called by the server after sending it) runs the calls queued by engine.later()."""

	def __init__(self, body, later):
		super().__init__([body])
		self.later = later

	def close(self):
		engine.run_later(self.later)


def application(environ, start_response):
	r = debuglog.viewer(environ)
	os.chdir(WORKDIR)
	later = []
	status, headers, body = r if r is not None else engine.main(environ, tables, BASEDIR, later)
	start_response(status, headers)
	return _Response(body if isinstance(body, bytes) else body.encode("utf-8"), later)
