<?php

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
