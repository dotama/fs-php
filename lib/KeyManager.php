<?php

class BasicAuthenticator implements RequestAuthenticator, MetricsProvider {
	private $keyManager;
	public function BasicAuthenticator($keyManager) {
		$this->keyManager = $keyManager;
	}
	public function authenticate($_url, $_query, $headers) {
		if (empty($headers['authorization'])) {
			return null;
		}
		$auth = $headers['authorization'];
		$fields = explode(" ", $auth);

		if (sizeof($fields) != 2) {
			return null;
		}
		if ($fields[0] != "Basic") {
			return null;
		}

		$credentials = explode(":", base64_decode($fields[1]));
		if (sizeof($credentials) != 2) {
			return null;
		}

		if ($this->keyManager->validCredentials($credentials[0], $credentials[1])) {
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

	public function KeyManager() {
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
