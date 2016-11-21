<?php

class InvertCondition {
	public function __construct($condition) {
		$this->condition = $condition;
	}

	public function fulfills($value, $expected) {
		return !$this->condition->fulfills($value, $expected);
	}
}