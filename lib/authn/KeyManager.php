<?php

class BasicAuthenticator implements RequestAuthenticator, MetricsProvider {
	private $keyManager;
	public function __construct($keyManager) {
		$this->keyManager = $keyManager;
	}
	public function authenticate($request) {
		if (!$request->hasHeader('Authorization')) {
			return null;
		}
		$authHeaderLines = $request->getHeader('Authorization');

		foreach($authHeaderLines as $auth) {
			$fields = explode(" ", $auth);

			if (sizeof($fields) != 2) {
				continue;
			}
			if ($fields[0] != "Basic") {
				continue;
			}

			$credentials = explode(":", base64_decode($fields[1]));
			if (sizeof($credentials) != 2) {
				continue;
			}

			if (!$this->keyManager->validCredentials($credentials[0], $credentials[1])) {
				continue;
			}

			return $credentials[0];
		}

		return null;
	}

	public function getMetrics() {
		return $this->keyManager instanceof MetricsProvider ? $this->keyManager->getMetrics() : [];
	}
}

class KeyManager {
	private $keys;

	public function __construct() {
		$this->keys = array();
	}

	public function addBcryptCredentials($name, $hash) {
		$key = array(
			'access' => $name,
			'secret' => $hash
		);
		$this->keys[] = $key;
	}

	public function addKey($name, $password) {
		$this->addBcryptCredentials($name, password_hash($password, PASSWORD_DEFAULT));
	}

	public function validCredentials($name, $password) {
		foreach ($this->keys AS $credentialPair) {
			if ($credentialPair['access'] == $name && password_verify($password, $credentialPair['secret'])) {
				return true;
			}
		}
		return false;
	}

	public function getMetrics() {
		return [
			array('name' => 'auth_key_count', 'type'=>'gauge', 'value' => sizeof($this->keys) )
		];
	}
}
