<?php

class ACLs {
	private $acls;
	private $default;
	public function __construct() {
		$this->acls = array();
	}

	public function define($name, $mode, $default = false) {
		$this->acls[$name] = array(
			'name' => $name,
			'mode' => $mode
		);

		if ($default) {
			$this->default = $name;
		}
	}

	public function defaultACL() {
		return $this->acls[$this->default];
	}

	public function byName($name) {
		return isset($this->acls[$name]) ? $this->acls[$name] : null;
	}

	public function byMode($mode) {
		foreach ($this->acls AS $acl) {
			if ($acl['mode'] == $mode) {
				return $acl;
			}
		}
		return null; # throw new Exception('Unknown ACL', 500);
	}

	public function allowsUnauthorizedRead($aclName) {
		$acl = $this->byName($aclName);
		return ($acl['mode'] & 04) > 0;
	}

	public static function defaultACLs() {
		$acls = new ACLs();
		$acls->define('public-read', 0664);
		$acls->define('private', 0660, true);
		return $acls;
	}

	public function getMetrics() {
		return [
			array('name' => 'acl_count', 'type'=>'gauge', 'value' => sizeof($this->acls) ),
		];
	}
}
