<?php

class StringLikeCondition {
	public function fulfills($value, $expected) {
		if (!is_string($value)) {
			return false;
		}
		if (is_array($expected)) {
			foreach($expected as $e) {
				if (fnmatch($e, $value)) {
					return true;
				}
			}
			return false;
		}
		return fnmatch($expected, $value);
	}
}