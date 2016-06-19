<?php
ini_set('track_errors', 1);

require_once(__DIR__ . '/lib/autoload.php');
require_once(__DIR__ . '/vendor/autoload.php');

function config() {
	$accessManager = new AccessManager();
	$keyManager = new KeyManager();
	$events = new MessagingService();
	$authenticators = [];

	# Load config file
	@include_once(__DIR__ . '/fs.config.php');
	if (empty($bucketPath)) {
		header("HTTP/1.1 500 Internal Server Errror");
		die('$bucketPath must be configured in fs.config.php - empty');
	}

	# $keyManager is allowed to be reset by the config.
	if ($keyManager != null) {
		$authenticators[] = new BasicAuthenticator($keyManager);
	}
	$acls = ACLs::defaultACLs();

	global $DOC;
	$bucket = new LocalBucket($acls, $bucketPath);

	# Load further configuration files from bucket itself
	#  $accessManager->newPolicy()...
	#  $keyManager->addToken()
	#  $keyManager->addBcryptCredentials
	if (isset($bucketConfigFiles)) {
		foreach($bucketConfigFiles as $path) {
			require($bucket->toDiskPath($path));
		}
	}
	return [$authenticators, $bucket, $acls, $accessManager, $events];
}

function handleRequest() {
	global $_SERVER;

	$host = $_SERVER['HTTP_HOST'];
	$method = $_SERVER['REQUEST_METHOD'];
	$path = isset($_SERVER['PATH_INFO']) ? $_SERVER['PATH_INFO'] : "";
	$params = $_GET;
	if (!function_exists('getallheaders')) {
		$headers = array();
		foreach ($_SERVER as $name => $value) {
			/* RFC2616 (HTTP/1.1) defines header fields as case-insensitive entities. */
			if (strtolower(substr($name, 0, 5)) == 'http_') {
				$headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
			}
		}
	} else {
	   $headers = getallheaders();
	}

	foreach($headers AS $key => $value) {
		$headers[strtolower($key)] = $value;
	}

	list($keyManager, $bucket, $acls, $accessManager, $events) = config();
	$server = new Server($bucket, $keyManager, $acls, $accessManager, $events);
	$server->handleRequest($host, $method, $path, $headers, $params);
}

handleRequest();
