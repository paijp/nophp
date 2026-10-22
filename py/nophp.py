#
#	WSGI application of nophp (replacement of index.php).
#
#	A site is one file, env_xxx.py, that is both the settings (env.php) and the WSGI entry point:
#
#		import nophp
#		import tables_xxx
#
#		def env(sys):
#			sys.sqlpath = "sqlite:/var/www/db/xxx.sq3"
#			...
#
#		application = nophp.make_application(env, tables_xxx, "/var/www/html/xxx")
#
#	tables_xxx.tables(sys) calls sys.env(sys) where tables.php did require("env.php").
#	Classes and bq_* functions are registered by name (as PHP), so a process can run only one site:
#	give each site its own WSGIDaemonProcess.
#

import os
import sys as _sys

import engine
import debuglog

_site = None


class _Response(list):
	"""The body; close() (called by the server after sending it) runs the calls queued by engine.later()."""

	def __init__(self, body, later):
		super().__init__([body])
		self.later = later

	def close(self):
		engine.run_later(self.later)


class _Site:
	def __init__(self, env, tables):
		self.env = env
		self.module = tables

	def tables(self, sys):
		sys.env = self.env		# tables(sys) calls sys.env(sys) (require("env.php"))
		self.module.tables(sys)


def make_application(env, tables, workdir):
	"""WSGI application of the site: env(sys) = env.php, tables = the module with tables(sys),
	workdir = the directory of index.php (res/ and other relative paths are relative to it)."""
	global _site
	if _site is not None and _site.module is not tables:
		raise RuntimeError("nophp: %s and %s in one process; use a WSGIDaemonProcess for each site"
			% (_site.module.__name__, tables.__name__))
	site = _site = _Site(env, tables)

	def application(environ, start_response):
		r = debuglog.viewer(environ)
		os.chdir(workdir)
		later = []
		status, headers, body = r if r is not None else engine.main(environ, site, workdir, later)
		if not isinstance(body, bytes):
			body = body.encode("utf-8")
		if not any(h[0].lower() == "content-length" for h in headers):
			headers = list(headers) + [("Content-Length", str(len(body)))]		# as PHP-FPM: not chunked (devices)
		start_response(status, headers)
		return _Response(body, later)
	return application


def serve(app):
	"""Development server: python3 env_xxx.py 8000 -> http://localhost:8000/index.php/nofmt/xxx.html"""
	from wsgiref.simple_server import make_server, WSGIRequestHandler

	def wrap(environ, start_response):
		# like Apache: WSGIScriptAlias /index.php, DirectoryIndex index.php, REQUEST_URI
		p = environ.get("PATH_INFO", "")
		environ["REQUEST_URI"] = p + ("?" + environ["QUERY_STRING"] if environ.get("QUERY_STRING") else "")
		q = p.lstrip("/")
		if q.startswith("index.php"):
			environ["SCRIPT_NAME"] = p[:len(p) - len(q)] + "index.php"
			environ["PATH_INFO"] = q[len("index.php"):]
		elif p.endswith("/"):
			environ["SCRIPT_NAME"] = p + "index.php"
			environ["PATH_INFO"] = ""
		return app(environ, start_response)

	class Quiet(WSGIRequestHandler):
		def log_message(self, *a):
			pass

	port = int(_sys.argv[1]) if len(_sys.argv) > 1 else 8000
	print("serving on http://localhost:%d/index.php" % port)
	make_server("127.0.0.1", port, wrap, handler_class=Quiet).serve_forever()
