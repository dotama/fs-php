<?php

class Server {
	private $bucket;
	private $authenticators;
	private $acls;
	private $accessManager;
	private $events;
	private $errorHandler;

	private $clientIP;
	private $headers;
	private $params;
	private $host;
	private $method;
	private $path;
	private $username;

	public function __construct($bucket, $authenticators, $acls, $accessManager, $events) {
		$this->bucket = $bucket;
		$this->authenticators = new RequestAuthenticatorSet($authenticators);
		$this->acls = $acls;
		$this->accessManager = $accessManager;
		$this->events = $events;
	}

	// return [$name, $resource, callable]; or Exception
	private function getHandler() {
		if (isset($this->params['debug'])) {
			return ['SendDebug', $this->path, 'handleDebug'];
		}
		switch ($this->method) {
		case "HEAD":
			if ($this->path == "/" || $this->path == "") {
				throw new Exception("HEAD not supported here", 405);
			} else {
				return ['GetObject', $this->path, 'handleGetObject'];
			}
		case "GET":
			if (isset($this->params['metrics'])) {
				return ['FetchPrometheusMetrics', '*', 'handleFetchPrometheusMetrics'];
			} else if ($this->path == "/" || $this->path == "") {
				return ['ListObjects', empty($this->params['prefix']) ? '/' : $this->params['prefix'], 'handleListObjects'];
			} else {
				return ['GetObject', $this->path, 'handleGetObject'];

			}
		case "PUT":
			if (isset($this->params['acl'])) {
				return ['PutObjectACL', $this->path, 'handlePutObjectACL'];
			} else {
				return ['PutObject', $this->path, 'handlePutObject'];
			}
		case "DELETE":
			return ['DeleteObject', $this->path, 'handleDeleteObject'];
		default:
			throw new Exception('Method not allowed', 405);
		}
	}

	public function handleRequest($host, $method, $path, $headers, $params, $clientIP) {
		$this->headers = $headers;
		$this->params = $params;
		$this->host = $host;
		$this->method = $method;
		$this->path = $path;
		$this->clientIP = $clientIP;

		try {
			$objectInfo = [];
			if (!empty($this->path)) {
				$objectInfo = $this->bucket->getObjectInfo($this->path);
			}
			list($name, $resource, $handler) = $this->getHandler();

			$authenticated = $this->doAuthentication();
			$authorized = $this->doAuthorization($name, $resource, $objectInfo);

			if (!$authorized && !$authenticated) {
				throw new Exception("Authentication required", 401);
			} else if (!$authorized) {
				// 403 forbidden
				throw new Exception("Forbidden", 403);
			} else if (!$authenticated) {
				// 401 access denied
				throw new Exception("Authentication required", 401);
			} else if (!empty($this->path) && empty($objectInfo)) {
				// 404 not found
				throw new Exception("Object not found", 404);
			}

			$this->resource = $this->params['prefix'];

			call_user_func([$this, $handler]);

			# If errors occur, this is normally never reached. so we only publish success events.
			$this->events->Publish(array(
				'username' => $this->username,
				'action' => 'mfs::' . $name,
				'resource' => $resource
			));
		} catch (Exception $e) {
			$code = 500;
			if ($e->getCode() != 0) {
				$code = $e->getCode();
			}
			$this->sendError($e, $code);
		}
	}

	public function handleFetchPrometheusMetrics() {
		$metrics = array_merge(
			$this->bucket->getMetrics(),
			$this->accessManager->getMetrics(),
			$this->acls->getMetrics(),
			$this->authenticators->getMetrics()
		);

		# Render
		$body = "";
		$tags = "service=\"mfs\"";
		foreach($metrics as $metric) {
			if (isset($metric['help'])) {
				$body .= "# HELP ${metric['name']} ${metric['help']}\n";
			}
			if (isset($metric['type'])) {
				$body .= "# TYPE ${metric['name']} ${metric['type']}\n";
			}
			# should becomes
			# "thisisthekey{these=are,the=tags} thisisthevalue\n"
			$mtags = "";
			if (isset($metric['tags'])) {
					$mtags .= $metric['tags'];
			}
			if (!empty($mtags) && !empty($tags)) {
				$mtags .= ",";
			}
			$mtags .= $tags;
			$body .= $metric['name'] . '{' . $mtags . '} ' . $metric['value'] . "\n";
		}

		header('Content-Type: plain/text; version=0.0.4');
		print ($body);
	}

	public function handlePutObjectACL() {
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
		$found = $this->bucket->deleteObject($this->path);

		if (!$found) {
			$this->sendError(new Exception('Not found', 404), 404);
		}

		header("HTTP/1.1 204 No Content");
	}

	public function handlePutObject() {
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

		if ($info == NULL) {
			throw new Exception('Not found', 404);
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
			$data = $this->bucket->getObject($this->path);
			echo $data;
		}
	}

	private function handleDebug() {
		header('Content-Type: text/plain');

		echo "Host: " . $this->host . "\n";
		echo "Method: " . $this->method ."\n";
		echo "Path: " . $this->path ."\n";
		echo "\n";
		echo "Headers: " . json_encode($this->headers, JSON_UNESCAPED_SLASHES) . "\n";
		echo "Params: " . json_encode($this->params, JSON_UNESCAPED_SLASHES) . "\n";
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
		die(json_encode($response, JSON_UNESCAPED_SLASHES)."\n");
	}

	private function sendJson($json) {
		header('Content-Type: application/json');
		echo json_encode($json, JSON_UNESCAPED_SLASHES);
	}

	private function doAuthentication() {
		$userid = $this->authenticators->authenticate($this->path, $this->params, $this->headers);

		if ($userid == null) {
			header('WWW-Authenticate: Basic realm="fs.php"');
			return false;
		}
		$this->username = $userid;
		return true;
	} 

	// doAuthorization() checks is the current request is allowed to continue
	//
	// @param string permission
	// @param array $resource
	// @throws AccessDeniedException
	private function doAuthorization($permission, $prefix, $resource) {
		$permission = 'mfs::' . $permission;

		$resourceInfo = [];
		if (!empty($resource)) {
			foreach($resource as $k => $v) {
				$resourceInfo['mfs::' . $k] = $v;
			}
		}

		$granted = $this->accessManager->isAuthorized(
			// Subject
			$this->username,
			$this->clientIP,

			// Operation
			$permission,

			// Target
			$prefix,
			$resource
		);
		return $granted;
	}
}
