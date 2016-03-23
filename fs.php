<?php

ini_set('track_errors', 1);

require_once(__DIR__ . '/lib/iam.php');
require_once(__DIR__ . '/lib/filesystem.php');

function config() {
	$accessManager = new AccessManager();
	$keyManager = new KeyManager();
	$events = new MessagingService();

	# Load config file
	@require_once(__DIR__ . '/fs.config.php');
	if (empty($bucketPath)) {
		die('$bucketPath must be configured in fs.config.php - empty');
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
	return [$keyManager, $bucket, $acls, $accessManager, $events];
}

function handleRequest() {
	global $_SERVER;

	$host = $_SERVER['HTTP_HOST'];
	$method = $_SERVER['REQUEST_METHOD'];
	$path = $_SERVER['PATH_INFO'];
	$params = $_GET;
	$headers = getallheaders();
	foreach($headers AS $key => $value) {
		$headers[strtolower($key)] = $value;
	}

	list($keyManager, $bucket, $acls, $accessManager, $events) = config();
	$server = new Server($bucket, $keyManager, $acls, $accessManager, $events);
	$server->handleRequest($host, $method, $path, $headers, $params);
}

handleRequest();
