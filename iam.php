<?php

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
}

class Policy {
	const EFFECT_ALLOW = 'allow';
	const EFFECT_DENY = 'deny';

	private $id;
	private $description;
	public $usernames;
	public $prefixes;

	public $effect = Policy::EFFECT_ALLOW;
	public $permissions = array();

	public function hasAccess() {
		return $this->effect == Policy::EFFECT_ALLOW;
	}

	public function deny() {
		$this->effect = Policy::EFFECT_DENY;
		return $this;
	}

	public function id($id) {
		$this->id = $id;
		return $this;
	}

	public function forPrefix($prefix) {
		$this->prefixes[] = $prefix;
		return $this;
	}

	public function forUsername($text) {
		$this->usernames[] = $text;
		return $this;
	}

	public function description($text) {
		$this->description = $text;
		return $this;
	}

	public function permission($permission) {
		$this->permissions[] = $permission;
		return $this;
	}
}

class AccessManager {
	private $policies;

	public function AccessManager() {
		$this->policies = array();
	}

	public function newPolicy() {
		$policy = new Policy();
		$this->policies[] = $policy;
		return $policy;
	}

	// addPolicy allows acces for the given $username for the given $prefix.
	public function addPolicy($username, $prefix, $allowRead, $allowWrite) {
		$policy = $this->newPolicy()
		    ->description('addPolicy')
			->forUsername($username)
			->forPrefix($prefix);

		if ($allowRead) {
			$policy->permission('read');
		}
		if ($allowWrite) {
			$policy->permission('write');
		}
		return $policy;
	}

	public function isGranted($prefix, $username, $permission) {
		$allowed = false;
		// Logic is as follows:
		// * If a policy has usernames, one must match
		// * If a policy has a prefix, one must match
		// * One policy must contain the requested permission
		// * if any policies has effect=deny, it wins over an allow policy
		// * at least one policy must allow, other it also denies
		//
		// see also https://github.com/ory-am/ladon/blob/master/guard/guard.go
		foreach($this->policies as $policy) {
			// Check usernames match
			if (sizeof($policy->usernames) > 0) {
				if (!AccessManager::matches($username, $policy->usernames)) {
					continue;
				}
			}

			// Check prefixes
			if (sizeof($policy->prefixes) > 0) {
				$found = false;
				foreach($policy->prefixes as $policyPrefix) {
					if (strpos($prefix, $policyPrefix) === 0) { // match!
						$found = true;
					}
				}

				if (!$found) {
					continue;
				}
			}

			// Check permissions (one MUST match)
			if (!AccessManager::matches($permission, $policy->permissions)) {
				continue;
			}

			// Apply result
			if (!$policy->hasAccess()) {
				#echo "isGranted($username, $prefix, $permission) = false # access\n";
				return false;
			}
			$allowed = true;
		}
		#echo "isGranted($username, $prefix, $permission) = $allowed # allowed\n";
		return $allowed;
	}

	/**
	 * Checks the $needle against a list of $patterns. Returns TRUE if any pattern matches.
	 */
	private static function matches($needle, $patterns) {
		foreach($patterns as $pattern) {
			$pattern = '/^' . str_replace('*', '.*', $pattern)  . '$/';
			$result = preg_match($pattern, $needle);
			# print $pattern . " to {$needle}\n";
			# print "> $result\n";
			if (1 === $result) {
				return true;
			}
		}
		return false;
	}
}
