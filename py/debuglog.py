#
#	nophp (python port)	https://github.com/paijp/nophp
#
#	Hook points of the debuglog / coverage feature of index.php (not ported yet).
#
#	engine.py calls these functions at the same places where index.php writes
#	$debuglog or the coverage log. They are called only when sys.debugdir is not None,
#	and sys.debugdir is always None for now (index.php sets it from glob("debuglog???????*")).
#
#	To restore the feature:
#	- set sys.debugdir in engine.Sys.__init__ like sys::__construct() in index.php,
#	- implement these functions (index.php: adddebuglog(), addcoveragelog(), log_die(),
#	  execsql(), commandparser::gettree(), commandparserhtml::parsehtml(),
#	  recordholder::flush_coverage(), *::dumpfields() and the "$logview_fn" viewer),
#	- serve the viewer from app.wsgi (the PHP log file was a PHP script that includes index.php).
#
#	g.debuglog, g.coverage_* and recordholder.coveragelist/debugpoplist are still maintained
#	by engine.py, so the data is available here.
#


def enabled(g):
	return g.sys.debugdir is not None


def on_request(g, targethtml):
	"""After the target HTML is read (index.php: "orgdebuglog:", GET/POST tables, "* source")."""


def on_sql(g, sql, array):
	"""execsql(): before execution."""


def on_sqlresult(g, error=None, lastinsertid=None, rows=None):
	"""execsql(): after execution."""


def on_parse(g, parser):
	"""commandparser::parsehtml(): $rootparser->gettree($this->index)."""


def on_html(g, parser, html, htmlhighlight):
	"""commandparserhtml::parsehtml(): source part and output part."""


def on_fields(g, record, title):
	"""*::dumpfields()"""


def on_coverage(g, rh):
	"""recordholder::flush_coverage()"""


def on_die(g, message):
	"""log_die(): table changes, coverage log and adddebuglog()."""


def viewer(environ):
	"""Return (status, headers, body) for a log viewer URL, or None."""
	return None
