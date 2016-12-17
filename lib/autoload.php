<?php

interface MetricsProvider {
	public function getMetrics();
}

# general authnz
require_once(__DIR__ . '/authn/RequestAuthenticator.php');
require_once(__DIR__ . '/authn/JWTAndSessionAuthenticator.php');
require_once(__DIR__ . '/authn/KeyManager.php');
require_once(__DIR__ . '/authz/AccessManager.php');

# fs-php specific
require_once(__DIR__ . '/ACL.php');
require_once(__DIR__ . '/Server.php');
require_once(__DIR__ . '/MessagingService.php');
require_once(__DIR__ . '/LocalBucket.php');
