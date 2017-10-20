<?php

require_once (__DIR__ . '/../StatsRegistry.php');

class InmemoryStatsRegistry implements StatsRegistry {
	public function __construct() {
	}

	public function getMetrics() {
		return [];
	}

	public function counter_inc($key, $labels = [], $val = 1) {
	}
}
