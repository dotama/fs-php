<?php

class ConditionEvaluator {
	private $types;

	public function __construct($types = null) {
		if ($types == NULL) {
			$types = array(
				'StringLike' => new StringLikeCondition(),
				'DateGreaterThan' => new DateGreaterThanCondition(),
				'DateLessThan' => new DateLessThanCondition(),
				'Bool' => new BoolCondition(),
				'StringEquals' => new StringEqualsCondition(),
			);
		}
		$this->types = $types;
	}

	// resolve replaces all occurences of '${varname}' with the value of the key 'varname'
	// in $vars.
	function resolve($input, $vars) {
		while (true) {
			$begin = mb_strpos($input, '${');
			if ($begin === FALSE) {
				return $input;
			}

			$end = mb_strpos($input, '}', $begin);
			if ($end === FALSE) {
				trigger_error("Failed to find end of var token '}' after position $begin for '$input'.");
				return NULL;
			}

			$key = substr($input, $begin+2, $end - $begin - 2);
			$input = substr_replace(
				$input,
				$vars[$key],
				$begin, $end - $begin + 1
			);
		}
	}

	// evaluate returns true, if all conditions are satisfied by the given $context and $username.
	//
	// Returns [$success, $reason]. $reason contains a hint, if $success is false, otherwilse null.
	public function evaluate($context, $conditions) {
		foreach ($conditions as $name => $objects) {
			$condition = $this->types[$name];

			foreach($objects as $field => $rhs) {
				if (!isset($context[$field])) {
					return [false, "Field '$name' is not given in context"];
				}

				if (is_scalar($rhs)) {
					$rhs = $this->resolve($rhs, $context);
				} else if (is_array($rhs)) {
					$rhs = array_map(function($v) use ($context) {
						return $this->resolve($v, $context);
					}, $rhs);
				} else {
					return [false, "Unexpected type for right handside '$rhs'"];
				}

				$f = $condition->fulfills($context[$field], $rhs);
				if (!$f) {
					return [false, "'$name' evaluated to false for field '$field' and right handside '" . json_encode($rhs) . "'"];
				}
			}
		}
		return [true, null];
	}
}

