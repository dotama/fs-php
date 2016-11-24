<?php

class ConditionEvaluator {
	private $types;

	public function __construct($types = null) {
		if ($types == NULL) {
			$types = array(
				// String
				'StringLike' => new StringLikeCondition(),
				'StringNotLike' => new InvertCondition(new StringLikeCondition()),
				'StringEquals' => new StringEqualsCondition(),
				'StringEqualsIgnoreCase' => new IgnoreCaseCondition(new StringEqualsCondition()),
				'StringNotEquals' => new InvertCondition(new StringEqualsCondition()),
				'StringNotEqualsIgnoreCase' => new InvertCondition(new IgnoreCaseCondition(new StringEqualsCondition())),

				// Date
				'DateGreaterThan' => new DateGreaterThanCondition(),
				'DateNotGreaterThan' => new InvertCondition(new DateGreaterThanCondition()),
				'DateLessThan' => new DateLessThanCondition(),
				'DateNotLessThan' => new InvertCondition(new DateLessThanCondition()),

				// Bool
				'Bool' => new BoolCondition(),
				
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
				throw new Exception("Failed to find end of var token '}' after position $begin for '$input'.");
			}

			$key = substr($input, $begin+2, $end - $begin - 2);
			if (!isset($vars[$key])) {
				throw new Exception("Unresolved variable $key in input '$input'");
			}
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
			if (!isset($this->types[$name])) {
				return [false, "Unknown condition '$name"];
			}
			$condition = $this->types[$name];
			

			foreach($objects as $field => $rhs) {
				if (!isset($context[$field])) {
					return [false, "Field '$field' is not given in context"];
				}

				try {
					if (is_scalar($rhs)) {
						$rhs = $this->resolve($rhs, $context);
					} else if (is_array($rhs)) {
						$rhs = array_map(function($v) use ($context) {
							return $this->resolve($v, $context);
						}, $rhs);
					} else {
						return [false, "Unexpected type for right handside '$rhs'"];
					}
				} catch (Exception $e) {
					return [false, $e->getMessage()];
				}

				$f = $condition->fulfills($context[$field], $rhs);
				if (!$f) {
					return [false, "'$name' evaluated to false for field '$field' and right handside " . json_encode($rhs)];
				}
			}
		}
		return [true, null];
	}
}

