<?php

class StringEqualsCondition {
	public function fulfills($value, $expected) {
		if (!is_string($value)) {
			return false;
		}
		if (is_array($expected)) {
			return in_array($value, $expected);	
		} else {
			return $value === $expected;
		}		
	}
}