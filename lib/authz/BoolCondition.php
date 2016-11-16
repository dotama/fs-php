<?php

class BoolCondition {
	public function fulfills($value, $expected) {
		return ($expected === true && $value === true) ||
			($expected === false && $value === false);
	}
}