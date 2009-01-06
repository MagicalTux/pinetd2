<?php

namespace Daemon\HTTPd_PHP;
use \Runkit_Sandbox;

class Client extends \Daemon\HTTPd\Client {
	protected $headers_sent = false;

	public function handleRequest($request, $headers, $cookies) {
		// We got a request, prepare a sandbox :)
		$base = $this->IPC->getFilesBase();
		$options = array(
			'open_basedir' => $base.':/tmp',
			'allow_url_fopen' => false,
			'runkit.internal_override' => false,
		);

		$sandbox = new Runkit_Sandbox($options);
		$sandbox['output_handler'] = array($this, '_outputHandler');

		$path = parse_url($request['path']);

		// build vars for $_SERVER
		$s_vars = array(
			'REMOTE_ADDR' => $this->peer[0],
			'SERVER_SIGNATURE' => $this->getVersionString(),
			'SERVER_SOFTWARE' => 'PInetd',
			'DOCUMENT_ROOT' => $base,
			'REMOTE_PORT' => $this->peer[1],
			'GATEWAY_INTERFACE' => 'CGI/1.1',
			'SERVER_PROTOCOL' => 'HTTP/'.$request['version'],
			'REQUEST_METHOD' => $request['method'],
			'QUERY_STRING' => $path['query'],
			'REQUEST_URI' => $request['path'],
			'SCRIPT_NAME' => $path['path'],
			'PHP_SELF' => $path['path'],
		);
		foreach($headers as $head => $data) {
			$head = 'HTTP_'.preg_replace('/[^A-Z0-9_]/', '_', strtoupper($head));
			$s_vars[$head] = $data[0][1];
		}
		$g_vars = array();
		parse_str((string)$path['query'], $g_vars);
		$sandbox->eval('foreach('.var_export($s_vars, true).' as $var => $val) $_SERVER[$var] = $val; unset($var, $val);');
		$sandbox->eval('$_GET = '.var_export($g_vars, true).';');
		$sandbox->eval('$_ENV = array();'); // avoid providing data from $_ENV
		$sandbox->eval('chdir('.var_export($base, true).');');

		$sandbox->require($base.'/index.php');

		//$sandbox->eval('phpinfo();');
		//$sandbox->eval('echo \'Hello \'.$_SERVER[\'REMOTE_ADDR\'].\'!<br /><pre>\'; var_dump($_SERVER);');

		$this->close();

//		var_dump($request);
	}

	public function _outputHandler($str) {
		// check if headers sent
		if (!$this->headers_sent) {
			$headers = 'HTTP/1.0 200 Ok'."\r\n";
			$headers.= 'Server: '.$this->getVersionString()."\r\n";
			$headers.= 'Content-Type: text/html'."\r\n";
			$this->sendMsg($headers."\r\n");
			$this->headers_sent = true;
		}

		$this->sendMsg($str);
		return '';
	}
}

