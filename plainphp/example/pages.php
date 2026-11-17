<?php
# The pages of the example. In nophp these were res/index.html, res/rootpage.html, res/memo.html.


# index.html: the login form (shown for every page while not logged in).
function	page_index()
{
	login_request();
	
	print "<html><head><title></title></head><body>\n".formopen()."\n<ul>\n";
	switch (getnum("mode")) {
		case	"0":		# `0__mode__:g:seq`
			print "\t<LI>login name <INPUT type=text name=login>\n";
			print "\t".'<LI><INPUT type=submit name=":login" value="send mail">'."\n";
			break;
		case	"1":		# the URL of the mail has mode=1login; ":g" keeps the digits
			print "\t<LI>new password <INPUT type=password name=pass>\n";
			print "\t".'<LI><INPUT type=submit name=":login" value="set password">'."\n";
			break;
		default:
			print "\t<LI>login name <INPUT type=text name=login>\n";
			print "\t<LI>password <INPUT type=password name=pass>\n";
			print "\t".'<LI><INPUT type=submit name=":login" value="Login">'."\n";
			print "\t".'<LI><A href="?mode=0">Forget password?</A>'."\n";
			break;
	}
	print "</ul>\n".formclose()."\n</body></html>\n";
	return null;
}


# rootpage.html
#   <!--{selectrows from memo where 1 `id__:loginrecord__owner__:sqleq` order by id desc-->
#     <li><a href="memo.html?id=`id__:r`">`title__:r`</a>
#   <!--}-->
#   <!--{tableid memo 0--> <input type="text" name="title">
#     <input type="submit" name="id__:loginrecord__owner__:set:H_update:popall:curpage:jump" value="add"> <!--}-->
function	page_rootpage()
{
	global	$loginrecord;
	
	print formopen()."\n<ul>\n";
	foreach (rec::select("memo", "where owner = ? order by id desc", array($loginrecord->id)) as $r)
		print "\t".'<li><a href="memo.html?id='.$r->id.'">'.h($r->v("title"))."</a>\n";
	print "</ul>\n";
	print '<input type="text" name="title" value="">'."\n";
	print '<input type="submit" name="do_add" value="add">'."\n";
	print '<input type="submit" name="do_logout" value="logout">'."\n";
	print formclose()."\n";
	
	if (!ispost())
		return null;
	if (@$_POST["do_logout"] !== null)
		return "logout";
	if (@$_POST["do_add"] !== null)
		return function() {
			global	$loginrecord;
			
			$r = rec::get("memo");
			$r->f["title"] = (string)poststr("title");		# no column: goes to "mixed"
			$r->f["owner"] = $loginrecord->id;
			$r->save();
			jump("rootpage");
		};
	return null;
}


# memo.html?id=N
#   <!--`id__:g:orhome`--> <!--{tableid memo `id__:g`-->
#   <textarea name="body"></textarea>
#   <input type="submit" name=":H_update:popall:curpage__id__id__:g:jump" value="save"> <!--}-->
function	page_memo()
{
	global	$sys, $loginrecord;
	
	if (($id = (int)getnum("id")) == 0)
		jump($sys->rootpage);
	$r = rec::get("memo", $id);
	if ((int)$r->v("owner") != $loginrecord->id)		# (nophp had no such check unless the template made one)
		jump($sys->rootpage);
	
	print '<a href="rootpage.html">all</a>'."\n".formopen()."\n";
	print "<h1>".h($r->v("title"))."</h1>\n";
	print '<textarea name="body" cols="60" rows="5">'.h($r->v("body"))."</textarea>\n";
	print '<input type="submit" name="do_save" value="save">'."\n".formclose()."\n";
	
	if (ispost() && (@$_POST["do_save"] !== null))
		return function() use ($r, $id) {
			$r->f["body"] = (string)poststr("body");
			$r->save();
			jump("memo", "id", $id);
		};
	return null;
}
