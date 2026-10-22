# nophp Python port

Python 3.12 port of nophp. The same HTML templates (res/*.html) and the same SQLite database can be used.

## Install (Rocky Linux 9)

```
dnf install httpd python3.12 python3.12-mod_wsgi
```

```
WSGIDaemonProcess nophppy processes=2 threads=1 python-path=/path/to/py
WSGIScriptAlias /nophp-py /path/to/py/app.wsgi process-group=nophppy application-group=%{GLOBAL}
WSGIPassAuthorization On
```

- URL: `/nophp-py/nofmt/g0000.html` (instead of `/index.php/nofmt/g0000.html`).
- Settings: `env.py` (env.php), tables: `tables.py` (tables.php).
- The database directory must be writable by the apache user.
- Restart (or touch app.wsgi) after editing *.py; mod_wsgi keeps modules loaded.
- To replace a PHP site with the same URL (`/app/index.php`), see [MIGRATION.md](MIGRATION.md)
  (Japanese): `/etc/httpd/conf.d/` file, `WORKDIR`, env.php to env.py (`tools/genenv.php`), disabling php-fpm.

## Files

| file | from |
|---|---|
| engine.py | index.php |
| builtinfunc.py | builtinfunc.php |
| loginrecord.py | loginrecord.php |
| sendmail.py | sendmail.php |
| importer.py | importer.php |
| env.py / tables.py | env.php / tables.php |
| phpcompat.py | PHP semantics: (int)/(float) casts, float to string, round(), loose ==/<, htmlspecialchars, PhpArray, class_exists/new $s |
| kana.py / phpdate.py | mb_convert_kana("ASKV"/"as"), date()/mktime() |
| app.wsgi | entry point |

## Writing tables.py

- `class xxx_table(a_table)` and `xxx_table()` in `tables()` as in PHP.
- Classes are found by name (`class_exists`, `new $s`): just define a subclass of
  commandparser, recordholder, simpletable, ... and it is registered.
- Functions for backquotes: `@bqfunc def bq_name__arg1__arg2(arg1, arg2): return [...]`.
- Statements at the top level of tables.php go into `tables(sys)`; it runs on every request.
- getconfig(): a PHP heredoc does not include the newline before `EOO;`.
  Keep the string identical, or the tables are recreated when the database is shared with PHP.

## Tests

```
python3.12 tests/semantics.py php      # phpcompat etc. vs real PHP (718 cases)
python3.12 tests/compare.py http://host/nophp/index.php http://host/nophp-py g0000 g0001 ...
```

compare.py runs the same scenario (login, every form on every page, logout) against both and compares the responses.

## Differences from PHP (bug fixes)

- `?mode=sql` works (PHP: fatal error, myhash() is called before it is defined) and "create" creates the tables.
- `?mode=import` lists `$sys->importlist` (PHP refers an undefined global).

## Not ported yet

- debuglog files, coverage and the log viewer (`debuglog???????*` directory).
  The hook points are in debuglog.py (called only when sys.debugdir is set).
- mysql (sqlite only).

See MIGRATION.md for the steps to replace index.php (Japanese).
