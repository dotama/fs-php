<?php

interface MetricsProvider {
	public function getMetrics();
}

# general authnz
require_once(__DIR__ . '/authz/autoload.php');
require_once(__DIR__ . '/AccessManager.php');

require_once(__DIR__ . '/RequestAuthenticator.php');
require_once(__DIR__ . '/JWTAndSessionAuthenticator.php');
require_once(__DIR__ . '/KeyManager.php');





# fs-php specific
require_once(__DIR__ . '/ACL.php');
require_once(__DIR__ . '/Server.php');
require_once(__DIR__ . '/MessagingService.php');
require_once(__DIR__ . '/LocalBucket.php');