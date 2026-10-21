# nophp Python port (proof of concept)

Rocky Linux 9: `dnf install httpd python3.12 python3.12-mod_wsgi`

```
WSGIDaemonProcess nophppy processes=2 threads=1 python-path=/path/to/py
WSGIScriptAlias /nophp-py /path/to/py/app.wsgi process-group=nophppy application-group=%{GLOBAL}
```

| file | from |
|---|---|
| phpcompat.py | PHP semantics: (int)/(float) casts, float to string, round(), loose ==/<, htmlspecialchars, PhpArray, class_exists/new $s |
| engine.py | index.php: commandparser*, recordholder::parsebq, main loop |
| builtinfunc.py | builtinfunc.php (non-database functions) |
| kana.py / phpdate.py | mb_convert_kana("ASKV"/"as"), date()/mktime() |
| tables.py | tables.php |
| res/t0001.html | comparison test page (output is identical to PHP except login/submitkey) |

Not ported yet: database tables (table, a_table, simpletable, tableid, selectrows, ...), login, valid, debuglog/coverage, importer, sendmail.
