<?php

interface RequestAuthenticator {
	// Authenticate the given request represented by URL, query params and headers.
	// In case the user can be identified, an ID must be returned.
	//
	// @param $url string
	// @param $query array
	// @param $headers array
	//
	// @return string|null
	function authenticate($url, $query, $headers);
}


class RequestAuthenticatorSet {
	private $set;
	public function RequestAuthenticatorSet($set = []) {
		$this->set = $set;
	}

	// authenticate checks if any configured authenticator can authenticate the given request.
	public function authenticate($path, $params, $headers) {
		foreach ($this->set as $authenticator) {
			$userid = $authenticator->authenticate($path, $params, $headers);
			if ($userid != null) {
				if (!is_string($userid)) {
					header("HTTP/1.1 500 Internal Server Errror");
					die('{"message": "Invalid authenticator result."}');
				}
				return $userid;
			}
		}
		return false;
	}

	public function getMetrics() {
		$metrics = [
			array('name' => 'authn_authenticators_count', 'type'=>'gauge', 'value' => sizeof($this->set) ),
		];

		foreach($this->set as $authenticator) {
			if ($authenticator instanceof RequestAuthenticator) {
				$metrics = array_merge($metrics, $authenticator->getMetrics());
			}
		}
		return $metrics;
	}
}
