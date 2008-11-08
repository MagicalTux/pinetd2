<?php

// THIS WILL CONVERT OUR TREE TO LEGACY PHP5 CODE

start_scan_dir('..', '.', true);

function apply_preg_replace(&$str, $regex, $repl) {
	while(1) {
		$old_str = $str;
		$str = preg_replace($regex, $repl, $str);
		if ($str == $old_str) return;
	}
}

function ns_resolve($call, $ns) {
	if ($call == 'self') return $call;
	if ($call == 'parent') return $call;
	if (class_exists($call)) return $call;
	$fil = str_replace('__', '/', $call);
//	var_dump($call);
	if (file_exists('../code/classes/'.$fil.'.class.php')) return $call;
	foreach($ns as $sns) {
		if (substr($call, 0, strlen($sns)) == $sns) return $call; // already contains this namespace
		if (substr($sns, -strlen($call)) == $call) return $sns; // namespace to class
		$snsf = str_replace('__', '/', $sns);
		if (file_exists('../code/classes/'.$snsf.'/'.$fil.'.class.php')) return $sns.'__'.$call;
	}
	return $ns[0] . '__' . $call;
}

function do_replace_func($func) {
	if (function_exists($func)) return $func;
	switch($func) {
		case 'relativeclass': return $func;
		break;
	}
	return '::'.$func;
}

function manage_file_copy($src, $dst) {
	if (substr($src, -4) != '.php') {
		return copy($src, $dst);
	}
	echo $src."\n";
	$dat = file_get_contents($src);

	$ns = array();
	$main_ns = null;
	// "namespace" declarations
	if (preg_match('/^namespace ([^;]+);/m', $dat, $match)) {
		$ns[] = $main_ns = str_replace('::', '__', $match[1]);
	}
	if (preg_match_all('/^use ([^;]+);/m', $dat, $match)) {
		foreach($match[1] as $usens) $ns[] = str_replace('::', '__', $usens);
	}
	apply_preg_replace($dat, '/^namespace ([^;]*);/m', '#namespace \1;');
	apply_preg_replace($dat, '/^use ([^;]*);/m', '#use \1;');

	if (!is_null($main_ns)) {
		// apply only once
		$dat = preg_replace('/^(abstract )?class ([a-zA-Z0-9_]+)/m', '\1class '.$main_ns.'__\2', $dat);
	}

	// apply some regexp
	apply_preg_replace($dat, '/([\'"])::\1/', '\1__\1');
	apply_preg_replace($dat, '#^//\*LEGACY\*//#m', '');
	apply_preg_replace($dat, '#^.*//\*LEGACY\*//#m', '');
	// static calls
	apply_preg_replace($dat, '/([a-zA-Z0-9_]+)::([a-zA-Z0-9_]+)::/', '\1__\2::');
	// root new classes
	apply_preg_replace($dat, '/new ::/', 'new ');
	// new classes
	apply_preg_replace($dat, '/new *([a-zA-Z0-9_]+)::/', 'new \1__');
	// root calls
	apply_preg_replace($dat, '/([^a-zA-Z0-9])::([a-zA-Z0-9_]+)/e', '\'\1\'.do_replace_func(\'\2\')');
	// class extends
	apply_preg_replace($dat, '/extends ([a-zA-Z0-9_]+)::/', 'extends \1__');
	// some extra cases of string building
	apply_preg_replace($dat, '/([a-zA-Z0-9])::([\'"])/', '\1__\2');
	// root extend
	apply_preg_replace($dat, '/extends ::/', 'extends ');
	// extended classes
	apply_preg_replace($dat, '/extends *([a-zA-Z0-9_]+)::/', 'extends \1__');
	// calls within namespace
	if ($ns) {
		// static calls
		$dat = preg_replace('/([^$a-zA-Z0-9_])([a-zA-Z0-9_]+)::/e', '\'\1\'.ns_resolve(\'\2\', $ns).\'::\'', $dat); // only once
		// new instances
		$dat = preg_replace('/new ([a-zA-Z0-9_]+)/e', '\'new \'.ns_resolve(\'\1\', $ns)', $dat); // only once
		// extends
		$dat = preg_replace('/extends ([a-zA-Z0-9_]+)/e', '\'extends \'.ns_resolve(\'\1\', $ns)', $dat); // only once
	}

	// final cleanup
	apply_preg_replace($dat, '/([^a-zA-Z0-9])::/', '\1');

	apply_preg_replace($dat, '/\$([a-z0-9]+)::([a-zA-Z0-9_]+)\(/', 'call_user_func(array($\1, \'\2\'), ');

	apply_preg_replace($dat, '/([\'"])([a-zA-Z0-9_]+)::([a-zA-Z0-9_:]+)\1/', '\1\2__\3\1');

	file_put_contents($dst, $dat);
	return true;
}

function start_scan_dir($dir, $local, $isroot = false) {
	$dh = opendir($dir);
	if (!$dh) die("Could not open dir :-(\n");

	while(($fil = readdir($dh)) !== false) {
		if ($fil[0] == '.') continue; // skip hidden dirs/files
		if (($isroot) && ($fil == 'legacy')) continue; // skip us
		if (($isroot) && ($fil == 'php')) continue; // skip php source
		$origin = $dir . '/' . $fil;
		$full_local = $local . '/' . $fil;
		if (is_dir($origin)) {
			@mkdir($full_local);
			start_scan_dir($origin, $full_local);
		} elseif (is_link($origin)) {
			symlink($full_local, readlink($origin));
		} else {
			manage_file_copy($origin, $full_local);
		}
	}
}

