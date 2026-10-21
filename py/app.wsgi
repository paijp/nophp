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
_sys.path.insert(0, BASEDIR)

import engine		# noqa: E402
import tables		# noqa: E402
import debuglog		# noqa: E402


def application(environ, start_response):
	r = debuglog.viewer(environ)
	os.chdir(BASEDIR)		# relative paths are relative to the directory of index.php in PHP
	status, headers, body = r if r is not None else engine.main(environ, tables, BASEDIR)
	start_response(status, headers)
	return [body if isinstance(body, bytes) else body.encode("utf-8")]
