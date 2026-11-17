# Rewriting a nophp application in plain PHP

How to rewrite an application that runs on nophp in plain PHP, without templates and
expressions, **keeping its database, its URLs and its logins**. The work is meant to be done
by Claude (an AI coding agent), and this guide is written for Claude to read. A person only
needs "Steps" and "Switching".

It is what was done, and what was learned, when chatmail (mail and task management, five
templates, about 86,000 records) was rewritten in 2026-11. About 4700 lines (nophp itself and
tables.php) became about 1400.

- [lib.php](lib.php) … what stays after the rewrite (database format, login, form protection, front controller)
- [example/](example/) … a small application on lib.php (a list of memos)
- [check.php](check.php) … checks that the new code understands a production database (prints counts only)
- [test/](test/) … the test that shows the behaviour is the same before and after

For moving to Python (same templates, the engine in Python) see [../py/MIGRATION.md](../py/MIGRATION.md).
This guide is for giving up the templates and the expressions themselves.

## When to rewrite

nophp lets people who write HTML add pages without asking people who write PHP. A rewrite
makes the application easier to work on when:

- there are few pages (a handful), and whoever adds pages writes PHP anyway (or is Claude);
- the features to come are mostly logic (external APIs, reports, links to other systems);
- expressions like `name="newtask__:p:T_newtask:popall:curpage__rid__rid__:g:jump"` have
  become a burden to maintain without misreading them.

With many pages and people who write HTML, stay on nophp (or its Python version).

## Steps

**Change the construction, not the behaviour.** If features are added at the same time, a
difference can no longer be told apart as "bug" or "intended". When a defect turns up, first
write the same behaviour, switch, and fix it afterwards (security problems are the exception,
see "What may be changed on purpose").

1. **Inventory**: read all of the nophp code the application uses (`index.php` etc.),
   `tables.php`, `res/*.html` and `env.php`. **Read the copy that is installed with the
   application, not the latest version in this repository**: behaviour differs between
   versions ("Differences between versions"). List the pages and the buttons (submit) of each page.
2. **Record the present behaviour**: with `test/golden.py`, record the answer of every page
   and every button of the nophp version, and what changes in the database ("Test"). This is
   the reference. Take it before writing anything. The fine points of nophp are found more
   reliably in the records than by reading its code.
3. **Write the new code**: page functions on top of `lib.php` ("From templates to PHP").
   Run the same scenario against the new code and fix it until `test/compare.py` shows no difference.
4. **Check with production data**: have a person run `check.php` on production ("Check").
5. **Switch**: point the same URL at the new code. Keep the old code so that going back is
   immediate ("Switching").

## What nophp does without being asked

Things that are not written in the templates but that nophp does. They disappear in a
rewrite, so write them out. "lib" marks what `lib.php` contains.

### The course of a request

| nophp | Rewrite |
|---|---|
| The URL is `.../index.php/<anything>/<page>.html`. Without `index.php` it redirects to `{URL}/index.php`; with another shape, to `{urlbase}/nofmt/{rootpage}.html` | lib `run()` |
| The second part (`nofmt` etc.) is a name for the debug log and may be anything, even empty (`index.php//page.html`) | lib accepts anything and uses `nofmt` itself |
| While not logged in, `index.html` is shown whatever the page name is | lib `run()` |
| Logged in and asking for `index.html`: redirect to `{urlbase}/index/{rootpage}.html` (unless `noredirectonlogin`) | lib `run()` |
| No file for the page: redirect to `{urlbase}/nopage/{rootpage}.html` | lib `run()` (page not in the list) |
| **The whole page is made first**, then the expression of the pressed button runs. If that ends with `:jump` etc., the page is thrown away | lib `run()`: a page function returns the action of the pressed button, which runs after the page was made |
| Functions that store a record while a page is made (caching a value for display, …) therefore run before the action on a POST too | make the page on every POST as well, to keep the order |
| While logged in, the whole request is one transaction. `log_die()` commits. A plain `die()` (a mail that cannot be sent, …) does not: what the request wrote is undone | lib `finish()` commits; a plain `die()` rolls back |

### Forms

| nophp | Rewrite |
|---|---|
| Adds `action="?<the present query>"` to a `<form>` without `action`. **The query is not escaped** | lib `formopen()` (escaped) |
| While logged in, adds `<INPUT type=hidden name=submitkey value="...">` before `</form>` | lib `formclose()` |
| A POST is handled only when `submitkey` equals the value in the login record (`ispost()`); otherwise the page is just shown | lib `ispost()`. **Check it in the action of every button** |
| A button runs when its `<input type=submit name="...">` is output while the page is made and the same name was posted. A button inside a condition or a loop is valid only when that row is shown | in the page function, test `ispost() && isset($_POST[name])` where the button is printed |
| `<input name="x">`, `<textarea name="x">`, checkboxes and selects show field `x` of the current record and put the posted value into field `x` of the current record (stored by `:h_update` etc.) | show `h($r->v("x"))`; in the action `$r->f["x"] = poststr("x")` |
| **So every input inside the same `tableid` as the button is stored in the record, related or not** (e.g. an empty field `newtask` ends up in `mixed` through the `:H_update` of another button) | do not store them. What is already in the database is kept ("Database") |

### Expressions (between backquotes)

Split at `__`; a part that does not start with `:` is pushed on the stack, a part that does
is a list of commands. The output is the bottom of the stack, by default through
`htmlspecialchars(…, ENT_QUOTES)`.

| Expression | Meaning | PHP |
|---|---|---|
| `` `name__:r` `` | field of the current record | `h($r->v("name"))` |
| `` `id__:g` `` | **the digits and commas** of `$_GET["id"]` | `getnum("id")` |
| `` `x__:p` `` | `$_POST["x"]` (only when `ispost()`, else empty) | `ispost() ? poststr("x") : ""` |
| `` `f__:loginrecord` `` | field of the login record | `h($loginrecord->v("f"))` |
| `` `body__:r:nl2br` `` / `:raw` / `:url` / `:js` | how it is output | `hbr(…)` / as it is / `rawurlencode` / a JS string |
| `` `a__b__:seq` `` / `:ieq` | 1 when equal as strings / as integers | `===` / `(int) ==` |
| `` `r__:aid:r_name` `` | field `name` of the table of `$sys->aid`, the id being field `aid` of the current record | `rec::get("…", $r->v("aid"))->v("name")` |
| `` `id__:g:orhome` `` | when the value is 0, go to `{urlbase}/nofmt/{rootpage}.html` | `if ((int)getnum("id") == 0) jump($sys->rootpage);` |
| `` `v__field__:sqleq` `` | adds `field = ?` to the where of the enclosing `selectrows` | write it in the SQL (see below) |
| `:xxx` (others) | `function bq_xxx__a__b($a, $b)` of `tables.php`: arguments from the stack, the returned array is pushed | an ordinary function |
| `:t_xxx` / `:T_xxx` | `t_xxx__a__b($rh, $record, $a, $b)` of the table named on the stack / of the current record; for a submit `ts_xxx` comes first | an ordinary function |
| `:h_xxx` / `:H_xxx` | `h_xxx` of the record holder (`tableid` …) / of the current record, `hs_xxx` for a submit. `:H_update` stores the record | `$r->save()` |
| `:set` | `value__field__:set` assigns to the current record | `$r->f["field"] = value` |
| `:popall:curpage__k__v__:g:jump` | go to `{urlbase}/{debugfn}/{page}.html?k=v` | `jump("page", "k", getnum("v"))` |

Watch out:

- **`:g` drops everything but digits and commas.** `?mode=1login` becomes `1` (the login page
  relies on this: `` `1__mode__:g:seq` ``). A string is taken as it is only where a function
  of `tables.php` reads `$_GET` itself.
- **`{if}` is true when the value is not 0 as an integer.** The empty string, `"0"` and
  strings that do not start with a digit are false; `"1@abc"` is true.
- **The where of `selectrows`**: `` `v__f__:sqleq` `` puts `f = ?` at the **front** of the
  where, joined with `and`. `from t where 1 or x = 0 `v__f__:sqleq`` becomes
  `where f = ? and 1 or x = 0`, i.e. `(f = ?) or x = 0`. `where 1` is padding for this mechanism.
- **A row of `selectrows`** gives the fields inside `mixed` as well as the columns.
- When the same table name (alias) appears in more than one **`tableid`** of a page, **the
  record of the first one** is used. A `tableid` without an id is a new empty record (storing it inserts).
- **Double escaping**: a `bq_` function that returns a string already passed through
  `htmlspecialchars()`, in an expression without `:raw`, is escaped again (`&` shows as
  `&amp;`). The opposite also happens: a `:raw` expression that carries a string from the
  query unescaped (XSS). Both are found by putting special characters into the records (test).
- There are expressions that are written but do nothing: a `bq_` function whose return value
  is not an array, a `T_` call that does not have the record it means. Write what the
  records show, not what reading the code suggests.
- The second part of a `:jump` URL is `$sys->debugfn`, empty when no debug log is used
  (`index.php//page.html`). It makes no difference.

## Database

It does not change. The rewritten code reads and writes the same file, and the old and the
new code can run on the same database in turns.

- Every table has `id`, `mixed`, `created`, `updated`, `deleted` and the columns of `getconfig()`.
- **Fields that are no columns are in `mixed`**: `key=rawurlencode(value)` separated by tabs.
  This is how inputs of a template, or `$r->v_xxx = …`, add fields without adding columns.
  Often the main data is in `mixed`.
- On every save all column values become strings (NULL becomes the empty string). `created`
  (on insert) and `updated` are `$sys->now`.
- `rec` of lib does exactly this. `$r->f` holds columns and `mixed` fields alike; `save()`
  puts them where they belong. **Fields the new code does not know are written back as they
  were read** (`debuglogfn` of the debug log, the leftovers of inputs described above, …).
- `$SCHEMA` takes the lines of `getconfig()` of `tables.php` as they are. It is used to make
  the tables of an empty database. An existing database is not altered (to add a column, run
  an explicit `alter table`).
- The `simple` table (`__dbinfo` etc.) is neither read nor written, so the old code still
  works when it is brought back.
- To read a whole big table use `rec::each()` (one record at a time). `rec::select()` holds all records in memory.

## Login

`login_request()` etc. of lib behave like **the version of 2023-03** (the one with
`v_ismaillogin`). Compare with `loginrecord.php` of the application's version (inside
`index.php` in old versions) and adjust.

Common to all versions:

- The cookies are `sessionid` (id of the login record) and `sessionkey`. The path is the part before `index.php`.
- The login record holds `sessionkey = myhash(salt . the cookie sessionkey)`, `submitkey`
  (for forms) and `pass = myhash(password . salt)`. `myhash` is sha256 (replaceable in `env.php`).
- **At the same URL, a user logged in through the old code stays logged in through the new
  code** (and back). Nobody has to log in again when the code is switched.
- An `update()` of the login record clears `sessionkey` because of `forcelogoutonupdate`
  (on by default): the user is logged out. This is why changing a profile or an API key logs
  out. lib `login_update()`. Login, setting a password and sending the mail call
  `parent::update()` directly and do not log out.
- A login form sent with an empty password mails the URL that sets a password
  (`?mode=1login&uid=…&key=…&`) with `$sys->mailcmd`. **The login name goes into a shell
  command: keep the check of its characters.**
- If the `login_table` of the application (`tables.php`) overrides `update()`, put what it
  does into `login_beforeupdate($r)`.

Differences between versions (check them):

| | version of 2023-03 | 2024-09 and later (this repository now) |
|---|---|---|
| Login is handled | when the submit named `:login` is output (i.e. while index.html is made) | before the page is made (`onload()`), when `$_POST[":login"]` exists and nobody is logged in |
| `v_ismaillogin` | exists (users who log in with the mailed URL only) | removed. Sending the page of the mailed URL with an empty password logs in without a password |
| Cookie path | `$cookiepath` | both `$cookiepath` and `$cookiepath."/"` |
| Logout | the expression `:logout` | when `ispost()` and `$_POST[":logout"]` exists |
| Transaction | `execsql("begin;")` | PDO `beginTransaction()`, replaceable with `bq_dbbegin` etc. |

## From templates to PHP

One page is one function. It prints the page and returns the action of the pressed button
(a callable) or null. `example/pages.php` shows examples with the original template in comments.

```php
function	page_memo()
{
	global	$sys, $loginrecord;
	
	if (($id = (int)getnum("id")) == 0)		# `id__:g:orhome`
		jump($sys->rootpage);
	$r = rec::get("memo", $id);			# {tableid memo `id__:g`}
	
	print formopen()."\n";
	print '<textarea name="body" cols="60" rows="5">'.h($r->v("body"))."</textarea>\n";
	print '<input type="submit" name="do_save" value="save">'."\n".formclose()."\n";
	
	if (ispost() && (@$_POST["do_save"] !== null))	# name=":H_update:popall:curpage__id__id__:g:jump"
		return function() use ($r, $id) {
			$r->f["body"] = (string)poststr("body");
			$r->save();
			jump("memo", "id", $id);
		};
	return null;
}
```

- Give buttons ordinary names like `do_save`. Do not change what they show (value). The test
  finds buttons by their value, so the same scenario works although the names change. Keep
  `:login` in the form for users who are not logged in.
- Everything that is output goes through `h()` (`htmlspecialchars(…, ENT_QUOTES)`), except
  HTML the code builds itself.
- A button inside a loop (an "add" per row) gets the id in its name (`do_add12`) and is
  tested where its row is printed.
- `t_xxx` / `bq_xxx` of `tables.php` become functions almost as they are: `$this->v_x`
  becomes `$r->f["x"]`, `$tablelist["t"]->getrecord($id)` becomes `rec::get("t", $id)`,
  `getrecordidlist("where …", …)` becomes `rec::select("t", "where …", …)`.
- Read `$_GET` / `$_POST` with `getstr()` / `poststr()` (a string or null, also when an array is sent).
- Let the new code read the original `env.php` as it is (it has the form `$sys->xxx = …;`,
  which works with the class `sys`). What the new code does not use is ignored. Production's
  settings file need not be touched for the switch.
- Split the files by role (entry, lib, logic, pages), not by page.

### What may be changed on purpose

The rule is the same behaviour, but fix the following. List what was fixed and tell the person.

- Strings from the query that were output unescaped (XSS): the `action` of forms, `:raw` expressions.
- Double escaping (where the display is broken).
- `SameSite=Lax` on the cookies, `hash_equals()` for keys and hashes, `X-Content-Type-Options: nosniff`.
- Unrelated inputs stored in records (do not store them).
- `?mode=sql`, `?mode=import`, the debug log and coverage are not carried over (use other means if needed).

Other "odd behaviour" (expressions without effect, confusing operations) is reproduced as it
is, listed, and left to the person to decide.

## Test

`test/golden.py` starts from an **empty database** and, for every step of a scenario, writes
one file with: status, Location, the names of the cookies set, the HTML, the mails sent, and
every record of every table (times, random keys and hashes masked). It runs the code under
`php -S`; no web server is needed.

```
python3 -I test/golden.py <copy of the old code> /tmp/old 8901 <scenario dir>
python3 -I test/golden.py <the new code>         /tmp/new 8902 <scenario dir>
python3 -I test/compare.py /tmp/old/out /tmp/new/out
```

- The scenario (`scenario.py`) is Python: a list of `get()`, `submit()` (send the form of the
  button with a given value) and `post()` (send as it is: APIs, forged POSTs without
  submitkey). `test/scenario.py` is the one of the example.
- Settings are in `ENV` of `config.py`. The code under test must load the file named by an
  environment variable (default `APP_ENV`) as its settings. For the nophp version, copy the
  code and replace `env.php` by `<?php require(getenv("APP_ENV"));`.
- An application that sends mail writes it to a file during tests (the directory in the
  environment variable `OUTBOX`; golden.py records the files there and removes them). For the
  login mail set `mailcmd` to `cat > {work}/loginmail.txt` and take the URL from it.

What goes into the scenario (95 steps for chatmail):

- every page while not logged in; every way to log in (mailed URL, setting a password, wrong password, unknown user);
- every page with no data, with data, with an id that does not exist, without an id;
- **every button**: empty input, several lines, **strings with `<b> & " '`**, CRLF; on pages
  with several buttons of the same kind, the second and later ones too;
- both sides of every condition of the display (closed / open, flag set / not set);
- a POST without submitkey and with a wrong one; after logout; for an API: the right key, a wrong key, while logged in;
- places where looking at a page changes the database (compare `---- db` with the step before).

`compare.py` ignores only: the names of buttons, empty lines and indentation of the HTML,
`&` / `&amp;` in `action`, and `index.php//` in redirects. Any other difference is fixed, or,
when it is a change on purpose, the record is replaced (keep the records of the nophp version
in the repository as `expected/` for later regression tests, and write down why a record was replaced).

When a feature is added later: add steps to the scenario first, then update `expected/`.

## Check (production data)

Test data is made up. Production data has shapes the test does not have (fields left by old
versions, NULLs, huge records). **Production data must not appear in the conversation**, so a
person runs `check.php`, which prints counts only.

- It reads every record and counts those that "would change when saved" (if not 0, `rec` does not handle that data correctly).
- It prints the names of the fields in `mixed` with their counts (unknown fields become visible).
- It makes every page (as the first user) and counts exceptions and warnings. Nothing is
  stored: it rolls back at the end (`finish()` throws instead of committing when `$checkmode` is set).
- Tables are read row by row. Reading a table at once runs out of memory with production's amounts.

Write the part of `check.php` marked "APPLICATION" for the application. All is well when the
last line is `ok: nothing would change, every page was made`.

## Switching

1. Put the new code at **the same URL** as the old code (in the web server's settings,
   exchange the PHP file of the entry). Cookies and login records are shared: users stay logged in.
2. Keep the old code in another directory. Going back is pointing the entry at the old code
   again (database and sessions are shared). Do go back once, to see that it works.
3. Back up the database before the switch (`sqlite3 db ".backup …"`).
4. After the switch run `check.php`, and try what cannot be undone (sending a mail) once with a harmless recipient.
5. After one or two weeks without problems, remove the old code and the backup.

Running the new code next to the old one at another URL is not recommended: the cookie path
differs, so it is another login, and logging in there ends the session of the other one (the
login record is shared).

## How much work it is

chatmail: five templates (about 300 lines), `tables.php` of about 760 lines, about 15 kinds of
buttons. Inventory, records (95 steps), rewrite, comparison, check and switch were done in one
session of Claude. The time went into the inventory (reading how nophp handles expressions)
and into fitting the scenario to the order of the real data. With the records at hand, the
rewrite itself is fixing until no difference is left.
