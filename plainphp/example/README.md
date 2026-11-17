# example: a small application on lib.php

A list of memos. Every function of `pages.php` has, in comments, what the nophp template would have been.

```
php -S 127.0.0.1:8900 -t plainphp/example        # http://127.0.0.1:8900/index.php
python3 -I plainphp/test/golden.py plainphp/example /tmp/run1 8901     # the test (24 steps)
APP_ENV=/tmp/run1/env.php php plainphp/check.php                      # the check
```

The user `defaultuser` of `env.php` (sample) is made on the first request. Set its password
with "Forget password?" on the login page (the URL is mailed with `mailcmd`), or set
`$sys->defaultpass` where mail cannot be used.
