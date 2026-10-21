#
#	WSGI entry point (replacement of index.php top level).
#	Apache: WSGIScriptAlias /nophp-py /path/to/py/app.wsgi
#	URL:    /nophp-py/nofmt/g0000.html
#

import os
import re
import sys as _sys
import time
import urllib.parse

_sys.path.insert(0, os.path.dirname(__file__))

import engine		# noqa: E402
import tables		# noqa: E402,F401

HTMLBASE = os.environ.get("NOPHP_HTMLBASE", os.path.join(os.path.dirname(__file__), "res"))


def application(environ, start_response):
	sys = engine.Sys()
	sys.rootpage = "g9999"
	sys.mid = "master0"
	sys.now = int(time.time())
	sys.debugfn = time.strftime("%y%m%d_%H%M%S_")
	scheme = "https://" if environ.get("HTTPS") == "on" else "http://"
	sys.urlbase = scheme + environ.get("HTTP_HOST", "") + environ.get("SCRIPT_NAME", "")
	sys.urlquery = environ.get("QUERY_STRING", "")
	sys.url = sys.urlbase + environ.get("PATH_INFO", "")
	try:
		m = re.match(r'^/([^/]*)/([0-9A-Za-z]+)[.]html$', environ.get("PATH_INFO", ""))
		if m is None:
			raise engine.redirect("%s/nofmt/%s.html" % (sys.urlbase, sys.rootpage))
		if m.group(1) == "nopost":
			raise engine.Exit(body="<h1>view only.</h1>")
		sys.target = m.group(2)
		try:
			with open(os.path.join(HTMLBASE, sys.target + ".html"), encoding="utf-8") as f:
				targethtml = f.read()
		except OSError:
			raise engine.redirect("%s/nopage/%s.html" % (sys.urlbase, sys.rootpage))
		get = dict(urllib.parse.parse_qsl(sys.urlquery, keep_blank_values=True))
		g = engine.Request(sys, get)
		body = engine.renderpage(targethtml, g)
		status, headers = "200 OK", []
	except engine.Exit as e:
		status, headers, body = e.status, e.headers, e.body
	start_response(status, [("Content-Type", "text/html; charset=UTF-8")] + headers)
	return [body.encode("utf-8")]
