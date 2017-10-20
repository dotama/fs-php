<?php

ini_set('track_errors', 1);
date_default_timezone_set('UTC');

require_once(__DIR__ . '/vendor/autoload.php');
require_once(__DIR__ . '/lib/mfs/autoload.php');

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

	# construct the database connection, if configured
	if (isset ($pdo_dsn)) {
		$pdo = new PDO($pdo_dsn, $pdo_username, $pdo_passwd, $pdo_options);
		$pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
		$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$stats = new MysqlStatsRegistry($pdo);
	} else {
		$stats = new InmemoryStatsRegistry();
	}

	# $keyManager is allowed to be reset by the config.
	if ($keyManager != null) {
		$authenticators[] = new BasicAuthenticator($keyManager);
	}
	$acls = ACLs::defaultACLs();

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
	return [$authenticators, $bucket, $acls, $accessManager, $events, $stats];
}

function handleRequest() {
	$request = Zend\Diactoros\ServerRequestFactory::fromGlobals();

	global $_SERVER;

	$path = isset($_SERVER['PATH_INFO']) ? $_SERVER['PATH_INFO'] : "";
	$request = $request->withAttribute(REQUEST_ATTR_PATH, $path);

	list($keyManager, $bucket, $acls, $accessManager, $events, $stats) = config();
	$server = new Server($bucket, $keyManager, $acls, $accessManager, $events, $stats);
	$server->handleRequest($request, $path);
}

handleRequest();
