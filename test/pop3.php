<?php

$error = 0;

function read_answer() {
	global $sock, $error;
	while(1) {
		$lin = fgets($sock);
		if ($lin === false) die("Link seems to be lost\n");
		$lin = rtrim($lin);
		if ($lin[0] != '+') {
			echo '! ';
			$error++;
		} else {
			echo 'I:';
		}
		echo $lin."\n";
		if ($lin[3] != '-') break;
	}
	return $error?false:true;
}

function send_cmd($cmd) {
	global $sock;
	echo 'O:'.$cmd."\n";
	if (!fputs($sock, $cmd."\r\n")) die('Link seems to be lost'."\n");
	usleep(21000);
	return read_answer();
}

function read_data() {
	global $sock;
	while(1) {
		$lin = fgets($sock);
		if ($lin === false) die("Link seems to be lost\n");
		$lin = rtrim($lin);
		echo 'I>'.$lin."\n";
		if ($lin == '.') break;
	}
}

$sock = fsockopen('localhost', 10110);
if (!$sock) die("E:Unable to connect\n");
read_answer();
send_cmd('USER magicaltux@localhost');
send_cmd('PASS password');

send_cmd('QUIT');
while(!feof($sock)) {
	$lin = fgets($sock);
	if ($lin === false) continue;
	echo '! '.$lin;
}
fclose($sock);

echo "--Retrying with AUTH PLAIN\n";

$sock = fsockopen('localhost', 10110);
if (!$sock) die("E:Unable to connect\n");
read_answer();
send_cmd('AUTH PLAIN');
send_cmd(base64_encode("\x00magicaltux@localhost\x00password"));

send_cmd('STAT');
send_cmd('LIST 1');
send_cmd('LIST');
read_data();
send_cmd('LIST 1');
send_cmd('UIDL');
read_data();
send_cmd('RETR 1');
read_data();
send_cmd('TOP 1');
read_data();
send_cmd('TOP 1 1');
read_data();
send_cmd('DELE 1');
send_cmd('DELE 1');
send_cmd('LIST');
read_data();
send_cmd('RSET');
send_cmd('LIST');
read_data();
send_cmd('DELE 1');


send_cmd('QUIT');
while(!feof($sock)) {
	$lin = fgets($sock);
	if ($lin === false) continue;
	echo '! '.$lin;
}
fclose($sock);

