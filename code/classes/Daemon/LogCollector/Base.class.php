<?php

namespace Daemon\LogCollector;

class Base extends \pinetd\TCP\Base {
	private $accounting = array();

	public function doAccept($sock) {
		$news = @stream_socket_accept($sock, 0, $peer);
		if (!$news) return;
		$buf = '';
		$this->IPC->registerSocketWait($news, array($this, 'receiveLogData'), $foobar = array($news, &$buf));
	}

	public function receiveLogData($fd, &$buf) {
		$data = fread($fd, 8192);
		if (($data === false) || ($data === '')) {
			$this->IPC->removeSocket($fd);
			fclose($fd);
			return;
		}

		$buf .= $data;

		while(($pos = strpos($buf, "\n")) !== false) {
			$lin = substr($buf, 0, $pos);
			$buf = substr($buf, $pos+1);

			if ((isset($this->localConfig['LogCollector'])) && ($lin == $this->localConfig['LogCollector']['key'])) {
				fwrite($fd, serialize($this->accounting));
				$this->accounting = array();
				$this->IPC->removeSocket($fd);
				fclose($fd);
				return;
			}

			parse_str($lin, $data);
			$this->handleData($data);
		}
	}

	protected function handleData($data) {
		if (!is_array($data['headers_in'])) return;
		$headers_in = array();
		foreach($data['headers_in'] as $h => $v) $headers_in[strtolower($h)] = $v;

		// store accounting
		$this->accounting[$data['vhost'].'|'.$data['host']]['time'] += ($data['now'] - $data['request_start']);
		$this->accounting[$data['vhost'].'|'.$data['host']]['vol'] += $data['bytes_sent'];
		++$this->accounting[$data['vhost'].'|'.$data['host']]['hit'];
		foreach(array('u','s','cu','cs') as $var) {
			$this->accounting[$data['vhost'].'|'.$data['host']][$var.'time'] += $data['tms_'.$var.'time_delta'];
		}

		// generated "combined" logline
		$fmt = $data['remote_ip'].' - '.($data['user']?:'-').' ['.date('d/M/Y:H:i:s O', $data['request_start']/1000000).'] "'.addslashes($data['request']).'" '.$data['status'].' '.$data['bytes_sent'].' "'.addslashes($headers_in['referer'][0]?:'-').'" "'.addslashes($headers_in['user-agent'][0]?:'-').'"';

		$domain = $data['host'];
		if (!$domain) return; // no valid host?

		$path = '/home/stats/www/';
		$quart = floor(gmdate('i')/15);
		$path_log = $path.'data/'.$domain[0].'/'.$domain[0].$domain[1].'/'.$domain.'/'.$data['vhost'].'/'.gmdate('Y-m-d_H').'-'.$quart.'.log';
		$path_todo = $path.'todo/'.$domain.'_'.$data['vhost'];

		$dir = dirname($path_log);
		if (!is_dir($dir)) @mkdir($dir, 0777, true);

		file_put_contents($path_log, $fmt."\n", FILE_APPEND);
		if (!file_exists($path_todo)) touch($path_todo);
	}
}

