<?php

namespace Daemon\PMaild;

class Mail {
	private $info; // domain
	private $data; // mail
	private $file; // mail file
	private $sql;
	const MIME_CACHE_MAGIC = 0xb0ca1;

	public function __construct($info, $mail_data, $file, $sql) {
		$this->info = $info;
		$this->data = $mail_data;
		$this->file = $file;
		$this->sql = $sql;
	}

	public function __get($offset) {
		return $this->data->$offset;
	}

	public function valid() {
		return file_exists($this->file);
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

	public function delete() {
		$this->clearMimeCache();
		return $this->data->delete();
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
		$depth = array();
		$imap_count = array();
		$part_info = array();

		$info_keep = array(
			'charset','transfer_encoding','content_name','content_type','content_disposition','content_base','content_id','content_description','content_boundary','disposition_filename',
			'starting_pos','starting_pos_body','ending_pos','ending_pos_body','line_count','body_line_count','content_language','content_charset'
		);
		$info_keep = array_flip($info_keep); // index on values

		foreach($struct as $part) {
			$p = mailparse_msg_get_part($m, $part);
			$info = mailparse_msg_get_part_data($p);
			$part_info[$part] = $info;
			list($type, $subtype) = explode('/', strtolower($info['content-type']));

			$pos = strrpos($part, '.');
			if ($pos !== false) {
				$parent = substr($part, 0, $pos);
			} else {
				$parent = NULL;
			}

			if (($type == 'multipart') && ($part == '1')) {
				$depth[$part] = 0;
				$imap_part = 'TEXT';
			} elseif (($type == 'multipart') && (($part == '1') || ($part_info[$parent]['content-type'] == 'message/rfc822'))) {
				$depth[$part] = 0;
				$imap_part = $part_info[$parent]['imap-part'].'.TEXT';
			} else {
				$depth[$part] = 1;
				$cur_depth = 0;
				$part_p = explode('.', $part);
				$tmp = '';
				foreach($part_p as $n) {
					$tmp .= ($tmp == ''?'':'.').$n;
					$cur_depth += $depth[$tmp];
				}
				if (!isset($imap_count[$cur_depth-1])) $imap_count[$cur_depth-1] = 0;
				$imap_count[$cur_depth-1]++;
				$imap_part = '';
				for($i = 0; $i < $cur_depth; $i++) {
					$imap_part .= ($i?'.':'').$imap_count[$i];
				}
			}
			$part_info[$part]['imap-part'] = $imap_part;

			$insert = array(
				'userid' => $this->data->userid,
				'mailid' => $this->data->mailid,
				'part' => $part,
				'imap_part' => $imap_part
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

	public function fetchRfc822Headers() {
		$head = "";

		// read file
		$fp = fopen($this->file, 'r'); // read headers
		if (!$fp) break;

		while(!feof($fp)) {
			$lin = fgets($fp);
			if (trim($lin) === '') break;
			$head .= $lin;
		}
		return $head;
		break;
	}

	public function getStructure($add_extra = false) {
		$this->needMime();
		$stack = array();
		$append = array();
		$append2 = array();

		$parts = $this->DAO('mime')->loadByField($this->where());
		foreach($parts as $part_bean) {
			$part = $part_bean->part;
			$info = $part_bean->getProperties();

			$list = explode('.', $part);
			$tmp = 'p';
			$prev = array(); // avoid warnings/notices
			while($list) {
				$tmp .= ($tmp=='p'?'':'.').array_shift($list);
				if (!isset($stack[$tmp])) {
					$new = array();
					$prev[] = &$new;
					$stack[$tmp] = &$new;
					$prev = &$new;
					unset($new);
					continue;
				}
				$prev = &$stack[$tmp];
			}
			unset($prev);

			$type = explode('/', $info['content_type']);
			if ($type[0] == 'multipart') {
				$append['p'.$part] = new Quoted(strtoupper($type[1]));
				continue;
			}

			$props = array();
			if (isset($info['charset'])) {
				$props[] = new Quoted('CHARSET');
				$props[] = new Quoted($info['charset']);
			}
			if (isset($info['content_name'])) {
				$props[] = new Quoted('NAME');
				$props[] = new Quoted($info['content_name']);
			}

			$cid = NULL;
			if (isset($info['content_id'])) $cid = new Quoted('<'.$info['content_id'].'>');
			$desc = NULL;
			if (isset($info['content_description'])) $desc = new Quoted($info['content_description']);

			$res = array(new Quoted(strtoupper($type[0])), new Quoted(strtoupper($type[1])), $props, $cid, $desc, new Quoted(strtoupper($info['transfer_encoding'])), ($info['ending_pos_body'] - $info['starting_pos_body']));
			if (strtolower($type[0]) == 'text') {
				$res[] = $info['body_line_count'];
			}
			if ($info['content_type'] == 'message/rfc822') {
				$append2['p'.$part][] = $info['body_line_count'];
				$res[] = $this->getEnvelope($part.'.1'); // get envelope of contents
			}
			if ($add_extra) {
				// get disposition if any
				$disposition = NULL;
				if (!is_null($info['content_disposition'])) {
					// ("ATTACHMENT" ("FILENAME" "test1.png"))
					$disposition = array(new Quoted(strtoupper($info['content_disposition'])), array());
					if (!is_null($info['disposition_filename'])) {
						$disposition[1][] = new Quoted('FILENAME');
						$disposition[1][] = new Quoted($info['disposition_filename']);
					}
				}
				if ($type[0] == 'multipart') {
					$multipart_props = array();
					if (!is_null($info['content_boundary'])) {
						$multipart_props[] = new Quoted('BOUNDARY');
						$multipart_props[] = new Quoted($info['content_boundary']);
					}
					if (!$multipart_props) $multipart_props = null;
					$append2['p'.$part][] = $multipart_props; // array of parameters, eg ("TYPE" "multipart/alternative" "BOUNDARY" "=-qHsc855UymA7s4jLqdMw") as defined in [MIME-IMB]
					$append2['p'.$part][] = $disposition; // array of disposition as defined in [DISPOSITION] (RFC2183)
					$append2['p'.$part][] = $info['content_language']; // body language as defined in [LANGUAGE-TAGS] RFC3066
//					$append2['p'.$part][] = null; // body location as defined in [LOCATION] RFC2557
				} else {
					$append2['p'.$part][] = null; // content_md5 [MD5]
					$append2['p'.$part][] = $disposition; // body disposition (array) as defined in [DISPOSITION]
					$append2['p'.$part][] = $info['content_language']; // Content-Language as defined in [LANGUAGE-TAGS]
//					$append2['p'.$part][] = null; // content location as defined in [LOCATION]
				}
			}

			$stack[$tmp] = $res;
		}

		foreach($append as $part => $what) {
			$stack[$part] = array(new ArrayList($stack[$part]), $what);
		}
		foreach($append2 as $part => $what) {
			foreach($what as $swhat) {
				$stack[$part][] = $swhat;// = array(new ArrayList($stack[$part]), $what);
			}
		}
		return $stack['p1'];
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
					$var[] = strtoupper($p);
					$res[] = $head;
					break;
				case 'TEXT':
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
					// check if this is a part request
					$this->needMime();
					$part = $this->DAO('mime')->loadByField($this->where()+array('imap_part' => $p));
					if ($part) {
						// partial body request, answer it!
						$part = $part[0];

						$fp = fopen($this->file, 'r');
						if (!$fp) break;
						fseek($fp, $part->starting_pos_body);
						$var[] = $p;
						$res[] = fread($fp, $part->ending_pos_body - $part->starting_pos_body);
						break;
					}
					var_dump('BODY UNKNOWN: '.$p);
			}
		}
		$var = array('BODY' => $var);
		foreach($res as $r) $var[] = $r;
		return $var;
	}

	public function getEnvelope($part = '1') {
		$fields = array(
			'date' => 's', // string
			'subject' => 's', // string
			'from' => 'm', // list
			'sender' => 'm',
			'reply-to' => 'm',
			'to' => 'm',
			'cc' => 'm',
			'bcc' => 'm',
			'in-reply-to' => 's',
			'message-id' => 's',
		);

		// load mail headers
		$headers = $this->getHeaders($part);

		// RFC 3501, page 77
		if (!isset($headers['sender'])) $headers['sender'] = $headers['from'];
		if (!isset($headers['reply-to'])) $headers['reply-to'] = $headers['from'];

		$envelope = array();
		foreach($fields as $head => $type) {
			if (!isset($headers[$head])) {
				$envelope[] = null;
				continue;
			}
			switch($type) {
				case 's':
					$envelope[] = new Quoted($headers[$head][0]);
					break;
				case 'm':
					$tmp = array();
					foreach($headers[$head] as $h) {
						$infolist = imap_rfc822_parse_adrlist($h, '');
						foreach($infolist as $info) {
							if ($info->host === '') $info->host = null;
							$tmp[] = array(new Quoted($info->personal), new Quoted($info->adl), new Quoted($info->mailbox), new Quoted($info->host));
						}
					}
					$envelope[] = $tmp;
					break;
				case 'l':
					$tmp = array();
					foreach($headers[$head] as $h) {
						$tmp[] = new Quoted($h);
					}
					$envelope[] = $tmp;
					break;
				default:
					$envelope[] = $head;
					break;
			}
		}

		return $envelope;
	}
}

