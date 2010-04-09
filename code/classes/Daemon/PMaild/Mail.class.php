<?php

namespace Daemon\PMaild;

class Mail {
	private $info; // domain
	private $data; // mail
	private $file; // mail file
	private $sql;
	const MIME_CACHE_MAGIC = 0xcafe;

	public function __construct($info, $mail_data, $file, $sql) {
		$this->info = $info;
		$this->data = $mail_data;
		$this->file = $file;
		$this->sql = $sql;
	}

	public function __get($offset) {
		return $this->data->$offset;
	}

	public function getId() {
		return $this->data->mailid;
	}

	public function size() {
		return filesize($this->file);
	}

	public function creationTime() {
		return filectime($this->file);
	}

	public function DAO($table) {
		switch($table) {
			case 'mime': $key = 'mimeid'; break;
			case 'mime_header': $key = 'headerid'; break;
			default: var_dump($table);exit;
		}
		return $this->sql->DAO('z'.$this->info['domainid'].'_'.$table, $key);
	}

	public function where() {
		return array('mailid' => $this->data->mailid, 'userid' => $this->data->userid);
	}

	public function clearMimeCache() {
		$this->DAO('mime')->delete($this->where());
		$this->DAO('mime_header')->delete($this->where());
		$this->data->mime_cache = 0;
		$this->data->commit();
	}

	public function generateMimeCache() {
		$m = mailparse_msg_parse_file($this->file);
		$struct = mailparse_msg_get_structure($m);

		$info_keep = array(
			'charset','transfer_encoding','content_name','content_type','content_disposition','content_base','content_id','content_description','content_boundary','disposition_filename',
			'starting_pos','starting_pos_body','ending_pos','ending_pos_body','line_count','body_line_count','content_language','content_charset'
		);
		$info_keep = array_flip($info_keep); // index on values

		foreach($struct as $part) {
			$p = mailparse_msg_get_part($m, $part);
			$info = mailparse_msg_get_part_data($p);

			$insert = array(
				'userid' => $this->data->userid,
				'mailid' => $this->data->mailid,
				'part' => $part,
			);
			foreach($info as $var => $val) {
				if ($var == 'headers') continue;
				$var = str_replace('-', '_', $var);
				if (!isset($info_keep[$var])) {
					var_dump($var);
					continue;
				}
				$insert[$var] = $val;
			}
			if (!$this->DAO('mime')->insertValues($insert)) return false;
			$id = $this->sql->insert_id;

			// now, insert headers
			foreach($info['headers'] as $header => $values) {
				if (!is_array($values)) $values = array($values);
				foreach($values as $value) {
					$insert = array(
						'mimeid' => $id,
						'userid' => $this->data->userid,
						'mailid' => $this->data->mailid,
						'header' => $header,
						'content' => $value,
					);
					$this->DAO('mime_header')->insertValues($insert);
				}
			}
		}

		$this->data->mime_cache = self::MIME_CACHE_MAGIC;
		$this->data->commit();
		return true;
	}

	public function needMime() {
		if ($this->data->mime_cache != self::MIME_CACHE_MAGIC) {
			if ($this->data->mime_cache != 0) $this->clearMimeCache();
			$this->generateMimeCache();
		}
	}

	public function getHeaders($part = '1') {
		$this->needMime();
		$part = $this->DAO('mime')->loadByField($this->where()+array('part' => $part));
		if (!$part) return NULL;
		$part = $part[0];
		$list = $this->DAO('mime_header')->loadByField($this->where()+array('mimeid' => $part->mimeid));
		$res = array();
		foreach($list as $h) $res[$h->header][] = $h->content;
		return $res;
	}

	public function getStructure() {
		$this->needMime();
		// moo!
		var_dump('hi');
		return array();
	}

	public function fetchBody($param) {
		if (count($param) == 0)
			$param = array('');
		$len = sizeof($param);
		$var = array();
		$res = array();

		for($i=0;$i<$len;$i++) {
			$p = $param[$i];
			switch(strtoupper($p)) {
				case 'HEADER.FIELDS':
					$list = $param[++$i];
					foreach($list as &$ref) $ref = strtoupper($ref); // toupper
					unset($ref); // avoid overwrite
					$list = array_flip($list);
					$head = "";
					$add = false;

					// read file
					$fp = fopen($this->file, 'r'); // read headers
					if (!$fp) break;

					while(!feof($fp)) {
						$lin = fgets($fp);
						if (trim($lin) === '') break;
						if (($lin[0] == "\t") || ($lin[0] == ' ')) {
							if ($add) $head .= $lin;
							continue;
						}
						$add = false;
						$pos = strpos($lin, ':');
						if ($pos === false) continue;
						$h = strtoupper(rtrim(substr($lin, 0, $pos)));
						if (!isset($list[$h])) continue;
						$head .= $lin;
						$add = true;
					}
					$var[] = 'HEADER.FIELDS';
					$var[] = array_flip($list);
					$res[] = $head;
					break;
				case 'HEADER':
					$head = "";

					// read file
					$fp = fopen($this->file, 'r'); // read headers
					if (!$fp) break;

					while(!feof($fp)) {
						$lin = fgets($fp);
						if (trim($lin) === '') break;
						$head .= $lin;
					}
					$var[] = 'HEADER';
					$res[] = $head;
					break;
				case 'TEXT':
				case '1':
					// fetch body text
					// read file
					$fp = fopen($this->file, 'r'); // read headers
					if (!$fp) break;

					$str = '';
					$start = false;

					while(!feof($fp)) {
						$lin = fgets($fp);
						if (!$start) {	
							if (trim($lin) == '') $start = true;
							continue;
						}
						$str .= $lin;
					}
					$var[] = strtoupper($p);
					$res[] = $str;
					break;
				case '':
					// fetch whole file
					$var[] = '';
					$res[] = file_get_contents($this->file);
					break;
				default:
					var_dump('BODY UNKNOWN: '.$p);
			}
		}
		$var = array('BODY' => $var);
		foreach($res as $r) $var[] = $r;
		return $var;
	}
}


