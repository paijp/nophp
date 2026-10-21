#
#	WSGI entry point (replacement of index.php).
#	Apache: WSGIScriptAlias /nophp-py /path/to/py/app.wsgi
#	URL:    /nophp-py/nofmt/g0000.html
#

import os
import sys as _sys

BASEDIR = os.path.dirname(os.path.abspath(__file__))
_sys.path.insert(0, BASEDIR)

import engine		# noqa: E402
import tables		# noqa: E402


def application(environ, start_response):
	status, headers, body = engine.main(environ, tables, BASEDIR)
	start_response(status, headers)
	return [body.encode("utf-8")]
