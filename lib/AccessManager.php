<?php


class Policy {
	const EFFECT_ALLOW = 'allow';
	const EFFECT_DENY = 'deny';

	private $id;
	private $description;
	public $usernames;
	public $resources;

	public $conditions = [];

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

	public function forResource($resource) {
		$this->resources[] = $resource;
		return $this;
	}
	// @deprecated
	public function forPrefix($prefix) {
		return $this->forResource('mfs:' . $prefix . '*');
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

	public function mustMatch($conditions) {
		foreach ($conditions as $cType => $cFields) {
			if (isset($this->conditions[$cType])) {
				foreach($cFields as $name => $expectedValue) {
					$this->conditions[$cType][$name] = $expectedValue;
				}
			} else {
				$this->conditions[$cType] = $cFields;
			}
		}
	}
}

class AccessManager {
	const CTX_USERNAME = 'authn::username';
	const CTX_CURRENTTIME = 'sys::CurrentTime';
	const CTX_REQUEST_IP = 'req::ip';

	private $policies;
	private $conditions;

	public function __construct() {
		$this->policies = array();
		$this->conditions = new ConditionEvaluator();
	}

	public function newPolicy() {
		$policy = new Policy();
		$this->policies[] = $policy;
		return $policy;
	}

	// isGranted returns true if the given $username has allowance to perform
	// $permission onto $prefix.
	public function isGranted($prefix, $username, $permission) {
		$resource = 'mfs:' . $prefix;
		$allowed = false;

		$context = [
			'mfs::resource' => $resource,
			'mfs::permission' => $permission,

			AccessManager::CTX_USERNAME => $username,
			AccessManager::CTX_CURRENTTIME => date("c"),
		];

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

			// Check resources
			if (sizeof($policy->resources) > 0) {
				if (!AccessManager::matches($resource, $policy->resources)) {
					continue;
				}
			}

			// Check permissions (one MUST match)
			if (!AccessManager::matches($permission, $policy->permissions)) {
				continue;
			}

			if (sizeof($policy->conditions) > 0) {
				list($result, $reason) = $this->conditions->evaluate($context, $policy->conditions);
				# TODO: What to do with reason?
				if (!$result) {
					continue;
				}
			}

			// Apply result
			if (!$policy->hasAccess()) {
				# a deny rule aborts evaulation immediately
				return false;
			}
			$allowed = true;
		}
		return $allowed;
	}

	/**
	 * Checks the $needle against a list of $patterns. Returns TRUE if any pattern matches.
	 */
	private static function matches($needle, $patterns) {
		foreach($patterns as $pattern) {
			$pattern = ',^' . str_replace('*', '.*', $pattern)  . '$,';
			$result = preg_match($pattern, $needle);
			if (1 === $result) {
				return true;
			}
		}
		return false;
	}

	public function getMetrics() {
		return [
			array('name' => 'authz_policies_count', 'type'=>'gauge', 'value' => sizeof($this->policies) )
		];
	}
}
