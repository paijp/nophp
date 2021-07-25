# nophp is a light simple web framework on php.

- HTML specialists can do many things without asking PHP specialists.
- Designed for inhouse single server web application which anyone have to login and read/write database.
- Powerful log for debug.
- Javascript not required.

## How to use.

```

- The sample below, in your HTML, means 'select name from userlist where id=1;' and escape it.
- The command is HTML comment, then you can see the HTML design without using this framework.
 <!--{tableid userlist 1 -->
 `name__:r`
 <!--}-->

- This sample means 'insert into userlist(name) values("...");'.
 <!--{tableid userlist 0 -->
 <form method="post">
 <input type="text" name="name">
 <input type="submit" name=":curtable:h_update" value="add user">
 </form>
 <!--}-->

- This sample means 'update userlist set name = "..." where id = 1;' and jump to top page.
- Of course, the exist value in database will be shown automatically as 'value="..."' in the textbox.
 <!--{tableid userlist 1 -->
 <form method="post">
 <input type="text" name="name">
 <input type="submit" name=":curtable:h_update:popall:jump" value="modify user">
 </form>
 <!--}-->

- Command in the 'name' is too complex? This will be written as a skeleton by PHP specialist, and HTML specialists can use it everywhere.

- You can use the value of '?id=1'.
 <!--{tableid userlist `id__:g` -->

- You can list the names in the userlist table like this.
 <ul>
 <!--{selectrows from userlist order by id -->
 <li>`name__:r`</li>
 <!--}-->
 </ul>

```

# Documents in Japanese is not translated to English yet.

- Please wait.
