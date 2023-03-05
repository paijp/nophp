# 'nophp' is a light simple web framework on php.

- HTML specialists can write many pages without asking PHP specialists.
- Designed for inhouse single server web application which anyone have to login and read/write database.
- Built-in password management.
- Powerful log for debug.
- Javascript not required.

## Important changes.

- 2023/03/05 The argument '$par' in recordholder::__construct() is no longer parsed with parsewithbq(). Please parse argument in constructor in user class.
- 2022/07/11 Return value of commandparser::parsehtml() is discarded. Please call commandparser::output() for output.
- 2022/07/11 debuglog directory changed to 'debuglog???????*'. Please create a directory with at least 7 characters that is hard to guess after 'debuglog'.

## How to use.

- The sample below, in your HTML, means 'select name from userlist where id=1;' and escape it.
- The command is HTML comment, then you can see the HTML design without using this framework.
```
 <!--{tableid userlist 1 -->
 `name__:r`
 <!--}-->
```

- This sample means 'insert into userlist(name) values("...");'.
```
 <!--{tableid userlist 0 -->
 <form method="post">
 <input type="text" name="name">
 <input type="submit" name=":curtable:h_update" value="add user">
 </form>
 <!--}-->
```

- This sample means 'update userlist set name = "..." where id = 1;' and jump to top page.
- Of course, the exist value in database will be shown automatically as 'value="..."' in the textbox.
```
 <!--{tableid userlist 1 -->
 <form method="post">
 <input type="text" name="name">
 <input type="submit" name=":curtable:h_update:popall:jump" value="modify user">
 </form>
 <!--}-->
```

- Command in the 'name' is too complex? This will be written as a skeleton by PHP specialist, and HTML specialists can use it everywhere.

- You can use the value of '?id=1'.
```
 <!--{tableid userlist `id__:g` -->
```

- You can list the names in the userlist table like this.
```
 <ul>
 <!--{selectrows from userlist order by id -->
 <li>`name__:r`</li>
 <!--}-->
 </ul>
```

- New table must be added by PHP specialists, but fields in the table can be add by HTML specialists.
- And the 'simple table' that holds a data like items for ``<select><option>``, can be add by HTML specialists.
- New function can be added by the PHP specialists, and HTML specialists can use it.

# File structure.

- db/ (any path)
	- master.sq3 (any name)

- html/ (any path)
	- index.php (this framework)
	- env.php (server dependent settings written by PHP specialists)
	- tables.php (table definition and user script written by PHP specialists)

- html/res/ (any path)
	- *.html (written by HTML specialists)

- html/debuglog/ (any path, optional)
	- *.php (automatically created)

# Samples in English are now ready.

