<?php

require_once (__DIR__ . '/../StatsRegistry.php');

class InmemoryStatsRegistry implements StatsRegistry {
	use Histogram;

	public function __construct() {
	}

	public function getMetrics() {
		return [];
	}

	public function counter_inc($key, $labels = [], $val = 1) {
		$l = json_encode($labels);
		error_log("Stats: Increment $key with $l by $val.");
	}
}
