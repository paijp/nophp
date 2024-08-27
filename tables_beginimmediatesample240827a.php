<?php

function	bq_dbbegin($db, $returnid = 0, $ignoreerror = 0)
{
	global	$sys;
	global	$debuglog;

	$retry = 10;
	while ($retry > 0) {
		if ($db->beginTransaction() == 0) {
			$retry--;
			error_log("bq_dbbegin retry0.");
			if (@$sys->debugdir !== null) {
				$debuglog .= "<P><B>bq_dbbegin retry0.</B></P>\n";
				adddebuglog();
			}
			sleep(1);
			continue;
		}
		execsql("rollback;;");		# ';;' to not use db->rollback()
		while ($retry > 0) {
			if (execsql("begin immediate;", null, 1, -1) == 0) {
				$retry--;
				error_log("bq_dbbegin retry1.");
				if (@$sys->debugdir !== null) {
					$debuglog .= "<P><B>bq_dbbegin retry1.</B></P>\n";
					adddebuglog();
				}
				sleep(1);
				continue;
			}
			if ($ignoreerror < 0) {
				if (@$sys->debugdir !== null) {
					$debuglog .= "<P><B>success.</B></P>\n";
					adddebuglog();
				}
				return 1;               # success.
			}
			if (($returnid))
				return 1;
			return array();
		}
		break;
	}
	$a = $db->errorInfo();
	if (@$sys->debugdir !== null) {
		$debuglog .= "<P><B>".htmlspecialchars($a[2], ENT_QUOTES
)."</B></P>\n";
		adddebuglog();
	}
	if (!$ignoreerror)
		log_die($a[2]." : ".$sql);
	if (($returnid))
		return 0;
	return array();
}

