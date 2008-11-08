<?php

$error = 0;

function read_answer($expect) {
	global $sock, $error;
	while(1) {
		$lin = fgets($sock);
		if ($lin === false) die("Link seems to be lost\n");
		$lin = rtrim($lin);
		if (substr($lin, 0, 3) != $expect) {
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

function send_cmd($cmd, $expect) {
	global $sock;
	echo 'O:'.$cmd."\n";
	if (!fputs($sock, $cmd."\r\n")) die('Link seems to be lost'."\n");
	return read_answer($expect);
}

$sock = fsockopen('localhost', 10025);
if (!$sock) die("E:Unable to connect\n");
read_answer(220);
send_cmd('EHLO foobartest', 250);
echo "--NEGOCIATING SSL\n";
send_cmd('STARTTLS', 220);
stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
echo "--ENABLED SSL FROM THIS POINT\n";
send_cmd('EHLO myrealname.com', 250);
send_cmd('AUTH PLAIN', 334);
send_cmd(base64_encode("\0magicaltux@localhost\0password"), 235);
send_cmd('MAIL FROM:<>', 250);
send_cmd('MAIL FROM:<>', 503);
send_cmd('RCPT TO:<magicaltux_test@ookoo.org>', 250); // allowed because we did auth
send_cmd('RCPT TO:<evilone@localhost>', 503);
send_cmd('RCPT TO:<magicaltux@localhost>', 250);
send_cmd('DATA', 354);
fputs($sock, "Date: ".date(DATE_RFC2822)."\r\nSubject: pInetd 2 test email\r\nFrom: test <magicaltux@ookoo.org>\r\nTo: magicaltux@localhost\r\nContent-Language: en_US\r\n\r\nPlease ignore this test email.\r\nfoobar\r\n\r\ntest\r\n");
send_cmd('.', 250);
send_cmd('TXLG', 250);
send_cmd('QUIT', 221);
while(!feof($sock)) {
	$lin = fgets($sock);
	if ($lin === false) continue;
	echo '! '.$lin;
}
fclose($sock);


