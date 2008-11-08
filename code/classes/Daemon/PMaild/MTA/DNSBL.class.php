<?php

namespace Daemon::PMaild::MTA;

class DNSBL {
	static private $dnsbl = array(
		'spews1' => array(
			'.l1.spews.dnsbl.sorbs.net',
		),
		'spews2' => array(
			'.l2.spews.dnsbl.sorbs.net',
		),
		'spamcop' => array(
			'.bl.spamcop.net',
		),
		'spamhaus' => array(
			'.sbl-xbl.spamhaus.org', // http://www.spamhaus.org/sbl/howtouse.html
		),
	);

	static function check($peer, &$mail, &$localConfig) {
		// $peer[0] contains ip
		// $peer[2] contains host
		$ip = explode('.', $peer[0]);
		$rev_ip = $ip[3] . '.' . $ip[2] . '.' . $ip[1] . '.' . $ip[0];
		// access dnsbl settings
		$SQL = ::pinetd::SQL::Factory($localConfig['Storage']);
		$DAO_domains = $SQL->DAO('domains', 'domainid');
		$domain = $DAO_domains[$mail['domainid']];

		$checks = explode(',', $domain->dnsbl);
		foreach($checks as $bl) {
			$dns = $rev_ip . self::$dnsbl[$bl][0];
			$resolved = gethostbyname($dns);
			if ($resolved == $dns) continue; // nothing found
			if (!isset(self::$dnsbl[$bl][1])) {
				return 'You were found in bl '.$bl.' - possible spam source, mail denied!';
			}
		}
		return false;
	}
}

