<?php
$server = '{localhost:10143/novalidate-cert}';
$mb = 'INBOX';

echo 'Connect to imap: ';
$mbox = @imap_open($server.$mb, 'magicaltux@localhost', 'password');
var_dump($mbox);
if (!$mbox) die(); // we can't do anything in this case

echo 'List mailboxes: ';
$res = imap_getmailboxes($mbox, $server, '*');
echo 'found '.count($res)."\r\n";

var_dump($res);

echo 'Create FOO: ';
var_dump(imap_createmailbox($mbox, '{localhost:10143}FOO'));
echo 'Create FOO/BAR: ';
var_dump(imap_createmailbox($mbox, '{localhost:10143}FOO/BAR'));

echo 'Delete FOO: ';
var_dump(imap_deletemailbox($mbox, '{localhost:10143}FOO'));

var_dump(imap_getmailboxes($mbox, $server, '*'));

echo 'Delete FOO/BAR: ';
var_dump(imap_deletemailbox($mbox, '{localhost:10143}FOO/BAR'));
var_dump(imap_list($mbox, $server, '*'));
echo 'Delete FOO: ';
var_dump(imap_deletemailbox($mbox, '{localhost:10143}FOO'));

echo 'PING: ';
var_dump(imap_ping($mbox));

$info = imap_check($mbox);

echo 'Got '.$info->Nmsgs.' messages on server '.$info->Mailbox."\r\n";

//var_dump($info);
$res = imap_search($mbox, 'ALL', SE_UID, 'UTF-8');
echo 'IMAP search found '.count($res).' mails (should be '.$info->Nmsgs.')'."\n";
//$res = imap_search($mbox, 'ALL');
//var_dump($res);

$res = imap_body($mbox, 1);
var_dump($res);

var_dump(imap_fetch_overview($mbox, '1'));

/*
var_dump(imap_headerinfo($mbox, 1));
*/

