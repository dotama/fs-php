<?php

class DateLessThanCondition {
	public function fulfills($value, $expected) {
		$t1 = DateTime::createFromFormat(DateTime::ISO8601, $value);
		if ($t1 === false) {
			return false;
		}

		$t2 = DateTime::createFromFormat(DateTime::ISO8601, $expected);
		if ($t2 === false) {
			return false;
		}

		$diff = $t1->diff($t2);
		return $diff->invert == 0 && ($diff->s > 0 || $diff->i > 0 || $diff->h > 0 || $diff->d > 0 || $diff->m > 0 || $diff->y > 0);
	}
}