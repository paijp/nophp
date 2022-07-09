<?php

class	recordholder_sendmail extends recordholder {
	var	$mailbody = "";
	function	h_sendmail() {
		$contents = trim($this->mailbody);
		$body = "";
		$arg = "";
		$option = "";
		$isbody = 0;
		foreach (preg_split("/\r\n|\r|\n/", $contents) as $line) {
			if (($isbody))
				$body .= $line."\n";
			else if (preg_match('/^TO:(.*)/', $line, $a))
				$arg .= " ".escapeshellarg(trim($a[1]));
			else if (preg_match('/^CC:(.*)/', $line, $a))
				$option .= " -c ".escapeshellarg(trim($a[1]));
			else if (preg_match('/^BCC:(.*)/', $line, $a))
				$option .= " -b ".escapeshellarg(trim($a[1]));
			else if (preg_match('/^SUB:(.*)/', $line, $a))
				$option .= " -s '".str_replace(array('"', "'", "\\"), " ", trim($a[1]))."'";
			else if (preg_match('/^FROM:(.*)/', $line, $a))
				$option .= " -r ".escapeshellarg(trim($a[1]));
			else {
				$isbody = 1;
				$body .= $line."\n";
			}
		}
		$fp = popen("mail {$option} {$arg}", "w");
		fputs($fp, $body);
		pclose($fp);
		return array();
	}
}


class	commandparser_sendmail extends	commandparser {
	var	$buf = "";
	function	parsehtml($tableholder = null, $record = null) {
		global	$recordholderlist;
		
		$a = explode(" ", trim($this->par));
		$rh = new recordholder_sendmail();
		$this->buf = "";
		parent::parsehtml($tableholder, $record);
		$rh->mailbody = $tihs->buf;
		$recordholderlist[@$a[0]] = $rh;
	}
	function	output($s = "") {
		$this->buf .= $s;
	}
}
