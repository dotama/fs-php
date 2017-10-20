<?php

class Server {
	private $bucket;
	private $authenticators;
	private $acls;
	private $accessManager;
	private $events;
	private $stats;

	private $request;
	private $params;
	private $username;

	public function __construct($bucket, $authenticators, $acls, $accessManager, $events, $stats) {
		$this->bucket = $bucket;
		$this->authenticators = new RequestAuthenticatorSet($authenticators);
		$this->acls = $acls;
		$this->accessManager = $accessManager;
		$this->events = $events;
		$this->stats = $stats;
	}

	// return [$name, $resource, callable]; or Exception
	private function getHandler($request) {
		$path = $request->getRequestTarget();
		$queryParams = $request->getQueryParams();

		if (isset($queryParams['debug'])) {
			return ['SendDebug', $path, 'handleDebug'];
		}

		switch ($request->getMethod()) {
		case "HEAD":
			if ($path == "/" || $path == "") {
				throw new Exception("HEAD not supported here", 405);
			} else {
				$info = $this->bucket->getObjectInfo($path);
				if ($this->acls->allowsUnauthorizedRead($info['acl'])) {
					return ['GetPublicObject', $path, 'handleGetObject'];
				}
				return ['GetObject', $path, 'handleGetObject'];
			}
		case "GET":
			if (isset($queryParams['metrics'])) {
				return ['FetchPrometheusMetrics', '*', 'handleFetchPrometheusMetrics'];
			} else if ($path == "/" || $path == "") {
				return ['ListObjects', empty($queryParams['prefix']) ? '/' : $queryParams['prefix'], 'handleListObjects'];
			} else {
				$info = $this->bucket->getObjectInfo($path);
				if ($this->acls->allowsUnauthorizedRead($info['acl'])) {
					return ['GetPublicObject', $path, 'handleGetObject'];
				}
				return ['GetObject', $path, 'handleGetObject'];

			}
		case "PUT":
			if (isset($queryParams['acl'])) {
				return ['PutObjectACL', $path, 'handlePutObjectACL'];
			} else {
				return ['PutObject', $path, 'handlePutObject'];
			}
		case "DELETE":
			return ['DeleteObject', $path, 'handleDeleteObject'];
		default:
			throw new Exception('Method not allowed', 405);
		}
	}

	public function handleRequest($request) {
		$this->request = $request;
		$this->params = $request->getQueryParams();

		$name = "invalid-request";
		$response = null;
		try {
			try {
				list($name, $resource, $handler) = $this->getHandler($request);
			} finally {
				$this->stats->counter_inc('api_http_requests_total', ['handler' => $name]);
			}

			switch($name) {
			// Public functions need no auth check.
			case "GetPublicObject":
			case "SendDebug":
				break;
			default:
				$this->requiresAuthentication($name, $resource);
			}
			$response = call_user_func([$this, $handler]);

			# If errors occur, this is normally never reached. so we only publish success events.
			$this->events->Publish(array(
				'username' => $this->username,
				'action' => 'mfs::' . $name,
				'resource' => $resource
			));
		} catch (Exception $e) {
			// Handlers MUST throw an exception for internal server errors
			// Handler CAN throw an exception for servers that should be treated as failed by Sysops
			$code = 500;
			if ($e->getCode() != 0) {
				$code = $e->getCode();
			}
			$this->stats->counter_inc('api_http_requests_failed', ['handler' => $name, 'code' => $code]);

			$errorBody = array(
				'error' => true,
				'message' => $e->getMessage(),
				'code' => $code,
			);
			$response = new Zend\Diactoros\Response\JsonResponse($errorBody, $code);
		}

		return $response;
	}

	public function handleFetchPrometheusMetrics() {
		$metrics = array_merge(
			$this->bucket->getMetrics(),
			$this->accessManager->getMetrics(),
			$this->acls->getMetrics(),
			$this->authenticators->getMetrics(),
			$this->stats->getMetrics()
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
			$mtags = "${tags}";

			if (isset($metric['tags']) && !empty($metric['tags'])) {
				if (!empty($mtags)) {
					$mtags .= ",";
				}

				$mtags .= $metric['tags'];
			}
			$body .= $metric['name'] . '{' . $mtags . '} ' . $metric['value'] . "\n";
		}

		$headers = [
			'Content-Type' => 'plain/text; version=0.0.4',
		];

		return new Zend\Diactoros\Response\TextResponse($body, 200, $headers);
	}

	public function handlePutObjectACL() {
		$newACL = $this->request->getBody()->getContents();

		$this->bucket->updateObjectACL($this->request->getRequestTarget(), $newACL);

		return new Zend\Diactoros\Response\EmptyResponse(204);
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
				throw new Exception('Invalid parameter: Parameter "delimiter" only supports "/"', 400);
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

		return new Zend\Diactoros\Response\JsonResponse($response);
	}

	public function handleDeleteObject() {
		$found = $this->bucket->deleteObject($this->request->getRequestTarget());

		if (!$found) {
			throw new Exception('Not found', 404);
		}

		return new Zend\Diactoros\Response\EmptyResponse(204);
	}

	public function handlePutObject() {
		$acl = NULL;
		if ($this->request->hasHeader('x-acl')) {
			$acl = $this->request->getHeaderLine('x-acl');
		}

		$data = file_get_contents('php://input');
		$this->bucket->putObject($this->request->getRequestTarget(), $data, $acl);

		return new Zend\Diactoros\Response\EmptyResponse(201);
	}

	public function handleGetObject() {
		$info = $this->bucket->getObjectInfo($this->request->getRequestTarget());

		if ($info == NULL) {
			throw new Exception('Not found', 404);
		}

		$headers = array(
			'x-acl' => $info['acl'],
			'content-length' => '0',
			'content-type' => $info['mtime']
		);

		if ($this->request->getMethod() == "HEAD") {
			return new Zend\Diactoros\Response\EmptyResponse(200, $headers);
		} else {
			$headers['content-length'] = $info['size'];
			$stream = $this->bucket->getObject($this->request->getRequestTarget());
			if ($stream == null) {
				throw new Exception('Not found', 404);
			}
			return new Zend\Diactoros\Response($stream, 200, $headers);
		}
	}

	private function handleDebug() {
		$text = "";
		$text .= "Host: " . $this->request->getUri()->getHost() . "\n";
		$text .= "Method: " . $this->request->getMethod() ."\n";
		$text .= "Path: " . $this->request->getRequestTarget() ."\n";
		$text .= "\n";
		$text .= "Headers: " . var_export($this->request->getHeaders(), true) ."\n";
		$text .= "Params: " . json_encode($this->params, JSON_UNESCAPED_SLASHES) . "\n";
		$text .= "\n";
		$text .= var_export($this->request, true) . "\n";
		$text .= "\n";
		$text .= var_export($this->getHandler($this->request), true) . "\n";

		return new Zend\Diactoros\Response\TextResponse($text);
	}

	private function requiresAuthentication($permission, $prefix) {
		if (!$this->checkAuthentication()) {
			header('WWW-Authenticate: Basic realm="fs.php"');

			throw new Exception("Authentication required", 401);
		} else {
			$permission = 'mfs::' . $permission;
			$granted = $this->accessManager->isGranted(
				$prefix,
				$this->username,
				$permission
			);

			if (!$granted) {
				throw new Exception("Access denied - {$permission} for '{$prefix}'", 403);
			}

			$this->resource = $prefix;
		}
	}

	private function checkAuthentication() {
		$userid = $this->authenticators->authenticate($this->request);
		if ($userid !== null) {
			$this->username = $userid;
			return true;
		}
		return false;
	}
}