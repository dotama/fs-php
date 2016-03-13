<?php

class ACLs {
	private $acls;
	private $default;
	public function ACLs() {
		$this->acls = array();
	}

	public function define($name, $mode, $default = false) {
		$this->acls[$name] = array(
			'name' => $name,
			'mode' => $mode
		);

		if ($default) {
			$this->default = $name;
		}
	}

	public function defaultACL() {
		return $this->acls[$this->default];
	}

	public function byName($name) {
		return $this->acls[$name];
	}

	public function byMode($mode) {
		foreach ($this->acls AS $acl) {
			if ($acl['mode'] == $mode) {
				return $acl;
			}
		}
		return NULL;
	}

	public function allowsUnauthorizedRead($aclName) {
		$acl = $this->byName($aclName);
		return ($acl['mode'] & 04) > 0;
	}


	public static function defaultACLs() {
		$acls = new ACLs();
		$acls->define('public-read', 0664);
		$acls->define('private', 0660, true);
		return $acls;
	}

}

class MessagingService {
	private $endpoint;
	private $accesss_key;
	private $secret_key;
	private $queue;

	public function Configure($endpoint, $access, $secret, $queue) {
		$this->endpoint = $endpoint;
		$this->access = $access;
		$this->secret = $secret;
		$this->queue = $queue;
	}
	public function Publish($obj) {
		if (empty($this->endpoint)) {
			return;
		}
		# Only push writes
		if ($obj['action'] === 'mfs::PutObject' ||
			$obj['action'] === 'mfs::PutObjectACL' ||
			$obj['action'] === 'mfs::DeleteObject') {
			$this->PushMessage(json_encode($obj), 'application/json');
		}
	}
	public function PushMessage($message, $content_type = 'binary/octet-stream')
	{
	  $params = array('http' => array(
	    'method' => 'POST',
	    'content' => $message,
	    'header' => "Authorization: Basic " . base64_encode($this->access . ":" . $this->secret). "\r\n" .
	      "Content-Type: $content_type\r\n"
	  ));

	  $ctx = stream_context_create($params);
	  $fp = @fopen($this->endpoint . "?action=PushMessage&queue={$this->queue}", 'rb', false, $ctx);
	  if (!$fp) {
	    throw new Exception("Problem with $this->endpoint, $php_errormsg");
	  }

	  $response = @stream_get_contents($fp);
	  if ($response === false) {
	    throw new Exception("Problem reading data from $this->endpoint, $php_errormsg");
	  }
	  return $response;
	}
}

class LocalBucket {
	private $path;
	private $acls;
	public function LocalBucket($acls, $path) {
		$this->path = $path;
		$this->acls = $acls;

		if (!is_dir($this->path)) {
			mkdir($this->path, 0777, true);
		}
	}

	public function listObjects($prefix, $showCommonPrefixes, &$outObjects, &$outCommonsPrefixes) {
		$path = $this->toDiskPath($prefix);
		#echo ">  $prefix\n>> $path\n";
		$files = glob($path . '*', GLOB_MARK | GLOB_NOSORT | GLOB_NOESCAPE);

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
		return file_get_contents($diskPath);
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
			throw new Exception("Invalid path - must start with slash (/)");
		}

		$pathElements = explode("/", $path);
		#echo json_encode($pathElements)."\n";
		foreach ($pathElements AS $index => $s) {
			#echo "$index => $s\n";
			if ($index == 0) {
				if ($s != "") {
					throw new Exception('Invalid path element.');
				}
			}
			else if ($index == (sizeof($pathElements) - 1) && $s == "") {
				continue;
			}
			else if ($s == "" || $s == "..") {
				throw new Exception("Invalid empty path element.");
			}
		}
		return $this->path.$path;
	}
	private function toBucketKey($diskPath) {
		return substr($diskPath, strlen($this->path));
	}
}

class Server {
	private $bucket;
	private $keyManager;
	private $acls;
	private $accessManager;
	private $events;

	private $headers;
	private $params;
	private $host;
	private $method;
	private $path;
	private $username;

	public function Server($bucket, $keyManager, $acls, $accessManager, $events) {
		$this->bucket = $bucket;
		$this->keyManager = $keyManager;
		$this->acls = $acls;
		$this->accessManager = $accessManager;
		$this->events = $events;
	}

	public function handleRequest($host, $method, $path, $headers, $params) {
		$this->headers = $headers;
		$this->params = $params;
		$this->host = $host;
		$this->method = $method;
		$this->path = $path;

		try {
			if (isset($params['debug'])) {
				$this->sendDebug();
				return;
			}
			switch ($method) {
			case "HEAD":
				if ($path == "/" || $path == "")
					$this->sendError(new Exception("HEAD not supported here."), 405);
				else
					$this->handleGetObject();
				break;
			case "GET":
				if ($path == "/" || $path == "")
					$this->handleListObjects();
				else
					$this->handleGetObject();
				break;
			case "PUT":
				if (isset($params['acl'])) {
					$this->handlePutObjectACL();
				} else {
					$this->handlePutObject();
				}
				break;
			case "DELETE":
				$this->handleDeleteObject();
				break;
			default:
				$this->sendError('Method not allowed.', 405);
			}
			# If errors occur, this is normally never reached. so we only trigger success events.
			$this->events->Publish(array(
				'action' => $this->action,
				'resource' => $this->resource
			));
		} catch (Exception $e) {
			$this->sendError($e, 500);
		}
	}

	public function handlePutObjectACL() {
		$this->requiresAuthentication('PutObjectACL', $this->path);

		$newACL = file_get_contents('php://input');

		$this->bucket->updateObjectACL($this->path, $newACL);

		header("HTTP/1.1 204 No Content");
	}

	public function handleListObjects() {
		$prefix = "/";
		if (!empty($this->params['prefix'])) {
			$prefix = $this->params['prefix'];
		}
		$showCommonPrefixes = false;
		if (!empty($this->params['delimiter'])) {
			if ($this->params['delimiter'] == '/') {
				$showCommonPrefixes = true;
			} else {
				$this->sendError(new Exception('Invalid parameter: Parameter "delimiter" only supports "/"', 400), 400);
			}
		}

		$this->requiresAuthentication('ListObjects', $prefix);

		$outObjects = array();
		$outCommonsPrefixes = array();
		$this->bucket->listObjects($prefix, $showCommonPrefixes, $outObjects, $outCommonsPrefixes);

		$response = array();
		$response['prefix'] = $prefix;
		if ($showCommonPrefixes) {
			$response['delimiter'] = $this->params['delimiter'];
		}
		if (!empty($outObjects)) {
			$response['objects'] = $outObjects;
		}
		if (!empty($outCommonsPrefixes)) {
			$response['common-prefixes'] = $outCommonsPrefixes;
		}

		$this->sendJson($response);
	}

	public function handleDeleteObject() {
		$this->requiresAuthentication('DeleteObject', $this->path);

		$found = $this->bucket->deleteObject($this->path);

		if (!$found) {
			$this->sendError(new Exception('Not found', 404), 404);
		}

		header("HTTP/1.1 204 No Content");
	}

	public function handlePutObject() {
		$this->requiresAuthentication('PutObject', $this->path);

		$acl = NULL;
		if (!empty($this->headers['x-acl'])) {
			$acl = $this->headers['x-acl'];
		}

		$data = file_get_contents('php://input');
		$this->bucket->putObject($this->path, $data, $acl);

		header("HTTP/1.1 201 Created");
	}

	public function handleGetObject() {
		$info = $this->bucket->getObjectInfo($this->path);

		if (!$this->acls->allowsUnauthorizedRead($info['acl'])) {
			$this->requiresAuthentication('GetObject', $this->path);
		}

		$data = $this->bucket->getObject($this->path);

		if ($data == NULL) {
			$this->sendError(new Exception('Not found', 404), 404);
		}

		header('x-acl: ' . $info['acl']);
		if ($this->method == "GET") {
			header('Content-Length: '. $info['size']);
		} else {
			header("Content-Length: 0");
		}
		header('Content-Type: ' . $info['mime']);
		header("HTTP/1.1 200 OK");
		if ($this->method == "GET") {
			echo $data;
		}
	}

	private function sendDebug() {
		header('Content-Type: text/plain');

		echo "Host: " . $this->host . "\n";
		echo "Method: " . $this->method ."\n";
		echo "Path: " . $this->path ."\n";
		echo "\n";
		echo "Headers: " . json_encode($this->headers) . "\n";
		echo "Params: " . json_encode($this->params) . "\n";
		die();
	}

	private function sendError($exception, $code = 400) {
		switch ($code) {
		case 400:
			header("HTTP/1.1 400 Bad Request");
			break;
		case 401:
			header('HTTP/1.1 401 Unauthorized');
			break;
		case 403:
			header("HTTP/1.1 403 Forbidden");
			break;
		case 404:
			header("HTTP/1.1 404 Not Found");
			break;
		case 405:
			header("HTTP/1.1 405 Method Not Allowed");
			break;
		case 500:
			header("HTTP/1.1 500 Internal Server Errror");
			break;
		}

		header("Content-Type: application/json");

		$response = array(
			'error' => true,
			'message' => $exception->getMessage(),
			'code' => $exception->getCode()
		);
		die(json_encode($response)."\n");
	}

	private function sendJson($json) {
		header('Content-Type: application/json');
		echo json_encode($json);
	}

	private function requiresAuthentication($permission, $prefix) {
		if (!$this->checkAuthentication()) {
			header('WWW-Authenticate: Basic realm="fs.php"');

			$this->sendError(new Exception("Authentication required", 401), 401);
		} else {
			$permission = 'mfs::' . $permission;
			$granted = $this->accessManager->isGranted(
				$prefix,
				$this->username,
				$permission
			);

			if (!$granted) {
				$this->sendError(new Exception("Access denied ({$permission}) for '{$prefix}'", 403), 403);
			}

			$this->action = $permission;
			$this->resource = $prefix;
		}
	}

	private function checkAuthentication() {
		$auth = $this->headers['authorization'];

		$fields = explode(" ", $auth);

		if (sizeof($fields) != 2) {
			return false;
		}
		if ($fields[0] != "Basic") {
			return false;
		}

		$credentials = explode(":", base64_decode($fields[1]));
		if (sizeof($credentials) != 2) {
			return false;
		}

		if ($this->keyManager->validCredentials($credentials[0], $credentials[1])) {
			$this->username = $credentials[0];
			return true;
		}

		return false;
	}
}
