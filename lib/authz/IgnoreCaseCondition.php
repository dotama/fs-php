<?php

class IgnoreCaseCondition {
	public function __construct($condition) {
		$this->condition = $condition;
	}

	public function fulfills($value, $expected) {
		$value = mb_strtolower($value);
		if (is_string($expected)) {
			$expected = mb_strtolower($expected);
		} else if (is_array($expected)) {
			$expected = array_map(function($v) {
				return mb_strtolower($v);
			}, $expected);
		}

		return $this->condition->fulfills($value, $expected);
	}
}