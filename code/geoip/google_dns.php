<?php
// get infos about a google DNS
// See: http://groups.google.com/group/public-dns-announce/browse_thread/thread/ac769564cb60cab5
// All IPs are currently /24, so let's cheat a bit

function lookup_google_dns($ip) {
	$ip = long2ip(ip2long($ip) & 0xffffff00); // set last part of the ip to 0

	// IP => airport data as provided by google
	$info = array(
		'64.233.162.0' => 'GRU',
		'64.233.168.0' => 'IAD',
		'64.233.180.0' => 'KUL',
		'72.14.202.0' => 'TPE',
		'74.125.126.0' => 'DLS',
		'74.125.152.0' => 'TPE',
		'74.125.154.0' => 'DLS',
		'74.125.38.0' => 'FRA',
		'74.125.42.0' => 'BER',
		'74.125.44.0' => 'ATL',
		'74.125.46.0' => 'ATL',
		'74.125.52.0' => 'DLS',
		'74.125.76.0' => 'GRQ',
		'74.125.78.0' => 'GRQ',
		'74.125.86.0' => 'BUD',
		'74.125.90.0' => 'MRN',
		'74.125.92.0' => 'MRN',
		'74.125.94.0' => 'CBF',
		'209.85.224.0' => 'CBF',
		'209.85.226.0' => 'BRU',
		'209.85.228.0' => 'BRU',
	);
	$info_airport = array(
		// North America
		'IAD' => array('continent_code' => 'NA', 'country_code' => 'US', 'region' => 'DC', 'city' => 'Washington'),
		'DLS' => array('continent_code' => 'NA', 'country_code' => 'US', 'region' => 'WA', 'city' => 'The Dalles'),
		'MRN' => array('continent_code' => 'NA', 'country_code' => 'US', 'region' => 'NC', 'city' => 'Morganton'),
		'CBF' => array('continent_code' => 'NA', 'country_code' => 'US', 'region' => 'IA', 'city' => 'Council Bluffs'),
		'ATL' => array('continent_code' => 'NA', 'country_code' => 'US', 'region' => 'GA', 'city' => 'Atlanta'),
		// South America
		'GRU' => array('continent_code' => 'SA', 'country_code' => 'BR', 'city' => 'Sao Polo'),
		// Europe
		'FRA' => array('continent_code' => 'EU', 'country_code' => 'DE', 'city' => 'Frankfurt'),
		'BER' => array('continent_code' => 'EU', 'country_code' => 'DE', 'city' => 'Berlin'),
		'GRQ' => array('continent_code' => 'EU', 'country_code' => 'NL', 'city' => 'Groningen'),
		'BUD' => array('continent_code' => 'EU', 'country_code' => 'HU', 'city' => 'Budapest'),
		'BRU' => array('continent_code' => 'EU', 'country_code' => 'BE', 'city' => 'Brussels'),
		// Asia
		'KUL' => array('continent_code' => 'AS', 'country_code' => 'MY', 'city' => 'Kuala Lumpur'),
		'TPE' => array('continent_code' => 'AS', 'country_code' => 'TW', 'city' => 'Taiwan'),
	);

	if (!isset($info[$ip])) return NULL;
	$res = $info_airport[$info[$ip]];
	$res['airport'] = $info[$ip];
	return $res;
}

