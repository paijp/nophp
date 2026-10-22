#
#	WSGI entry point with the old layout (env.py + tables.py + app.wsgi in one directory).
#	New sites: point WSGIScriptAlias to env_xxx.py (see nophp.py and MIGRATION.md).
#	Apache: WSGIScriptAlias /nophp/index.php /path/to/py/app.wsgi
#

import os
import sys as _sys

BASEDIR = os.path.dirname(os.path.abspath(__file__))
# The directory of index.php in PHP: relative paths (res/, imeilog/, ...) are relative to it.
# Set it when the Python files are put outside of the document root.
WORKDIR = BASEDIR
_sys.path.insert(0, BASEDIR)

import nophp		# noqa: E402
import env		# noqa: E402
import tables		# noqa: E402

application = nophp.make_application(env.env, tables, WORKDIR)
