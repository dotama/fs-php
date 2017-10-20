<?php

class LocalBucket {
	private $path;
	private $acls;
	public function __construct($acls, $path) {
		$this->path = $path;
		$this->acls = $acls;

		if (!is_dir($this->path)) {
			mkdir($this->path, 0777, true);
		}
	}

	public function listObjects($prefix, $showCommonPrefixes, &$outObjects, &$outCommonsPrefixes) {
		$path = $this->toDiskPath($prefix);
		$files = glob($path . '*', GLOB_MARK | GLOB_NOSORT | GLOB_NOESCAPE);

		#echo json_encode($files) . "\n"
		foreach ($files as $file) {
			#echo "# $file\n";
			if (substr($file, -1) == '/') {
				if ($showCommonPrefixes) {
					$outCommonsPrefixes[] = $this->toBucketKey($file);
				} else {
					$this->listObjects($this->toBucketKey($file), $showCommonPrefixes, $outObjects, $outCommonsPrefixes);
				}
			} else {
				$outObjects[] = $this->getObjectInfo($this->toBucketKey($file));
			}
		}
	}

	public function getObjectInfo($key) {
		$diskPath = $this->toDiskPath($key);
		if (!is_file($diskPath)) {
			return NULL;
		}
		$stat = stat($diskPath);

		$time = new DateTime('now', new DateTimeZone('UTC'));
		$time->setTimestamp($stat['mtime']);

		$mode = fileperms($diskPath) & 0777;
		$acl = $this->acls->byMode($mode);

		return array(
			'key' => $key,
			'size' => $stat['size'],
			'acl' => $acl['name'],
			'mime' => mime_content_type($diskPath),
			'mtime' => $time->format(DATE_ATOM)
		);
	}

	public function putObject($key, $data, $aclName = NULL) {
		$diskPath = $this->toDiskPath($key);

		if ($aclName == NULL) {
			$acl = $this->acls->defaultACL();
		} else {
			$acl = $this->acls->byName($aclName);
			if ($acl == NULL) {
				throw new Exception("Invalid ACL: $aclName", 400);
			}
		}

		if (is_dir($diskPath)) {
			throw new Exception("Cannot create key with same name as common-prefix: $key", 400);
		}

		$dir = dirname($diskPath);
		@mkdir($dir, 0777, true);

		if (($handle = @fopen($diskPath, 'wb')) === false) {
			throw new Exception('Could not write object', 500);
		}
		if (false === fwrite($handle, $data)) {
			fclose($handle);
			throw new Exception('Error writing object', 500);
		}
		fflush($handle);
		fclose($handle);

		chmod($diskPath, $acl['mode']);
	}

	public function updateObjectACL($key, $aclName) {
		$acl = $this->acls->byName($aclName);
		if ($acl == NULL) {
			throw new Exception("Invalid ACL: $aclName", 400);
		}
		$diskPath = $this->toDiskPath($key);

		chmod($diskPath, $acl['mode']);
	}

	public function getObject($path) {
		$diskPath = $this->toDiskPath($path);
		if (!file_exists($diskPath)) {
			return NULL;
		}
		if (is_dir($diskPath)) {
			return NULL;
		}
		return fopen($diskPath, 'r');
	}

	public function deleteObject($path) {
		$diskPath = $this->toDiskPath($path);

		if (!file_exists($diskPath)) {
			return false;
		}

		unlink($diskPath);
		return true;
	}

	public function toDiskPath($path) {
		if ($path == "/") {
			return $this->path . "/";
		}

		if (substr($path, -7) == ".ignore") {
			$path = substr($path, 0, -7);
		}

		if ($path[0] != "/") {
			throw new Exception("Invalid path - must start with slash (/)", 400);
		}

		$pathElements = explode("/", $path);
		#echo json_encode($pathElements)."\n";
		foreach ($pathElements AS $index => $s) {
			#echo "$index => $s\n";
			if ($index == 0) {
				if ($s != "") {
					throw new Exception('Invalid path element', 400);
				}
			}
			else if ($index == (sizeof($pathElements) - 1) && $s == "") {
				continue;
			}
			else if ($s == "" || $s == "..") {
				throw new Exception("Invalid empty path element", 400);
			}
		}
		return $this->path.$path;
	}
	private function toBucketKey($diskPath) {
		return substr($diskPath, strlen($this->path));
	}

	public function getMetrics() {
		$this->listObjects("/", false, $allObjects, $_commonPrefixes);
		usort($allObjects, function($a, $b) {
			return $a['size'] - $b['size'];
		});

		$allObjectsSum = array_sum(array_map(function($o) { return $o['size']; }, $allObjects));

		return [
			# Bucket Stats
			array('type' => 'summary', 'help' => 'Insight into the stored objects',
			      'name' => 'bucket_object_size_bytes',  'tags'=>'quantile="0.5"',
						'value' => $allObjects[(int)(sizeof($allObjects) * 0.5)]['size']),
			array('name' => 'bucket_object_size_bytes', 'tags'=>'quantile="0.9"',
					'value' => $allObjects[(int)(sizeof($allObjects) * 0.9)]['size']),
			array('name' => 'bucket_object_size_bytes', 'tags'=>'quantile="0.99"',
						'value' => $allObjects[(int)(sizeof($allObjects) * 0.99)]['size']),
			array('name' => 'bucket_object_size_bytes', 'tags'=>'quantile="1"',
						'value' => $allObjects[sizeof($allObjects) -1]['size']),

			array('name' => 'bucket_object_size_bytes_count', 'value' => sizeof($allObjects) ),
			array('name' => 'bucket_object_size_bytes_sum', 'value' => $allObjectsSum)
		];
	}
}
