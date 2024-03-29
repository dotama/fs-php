<?php

interface RequestAuthenticator {
	// Authenticate the given request represented by URL, query params and headers.
	// In case the user can be identified, an ID must be returned.
	//
	// @param $request PSR-7 RequestInterface
	//
	// @return string|null
	function authenticate($request);
}


class RequestAuthenticatorSet {
	private $set;
	public function __construct($set = []) {
		$this->set = $set;
	}

	// authenticate checks if any configured authenticator can authenticate the given request.
	public function authenticate($request) {
		foreach ($this->set as $authenticator) {
			$userid = $authenticator->authenticate($request);

			if ($userid != null) {
				if (!is_string($userid) || empty($userid)) {
					header("HTTP/1.1 503 Internal Server Errror");
					die('{"error": true, "message": "Invalid authenticator result."}');
				}
				return $userid;
			}
		}
		return null;
	}

	public function getMetrics() {
		$metrics = [
			array('name' => 'authn_authenticators_count', 'type'=>'gauge', 'value' => sizeof($this->set) ),
		];

		foreach($this->set as $authenticator) {
			if ($authenticator instanceof MetricsProvider) {
				$metrics = array_merge($metrics, $authenticator->getMetrics());
			}
		}
		return $metrics;
	}
}
