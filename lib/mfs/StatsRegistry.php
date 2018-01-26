<?php

interface StatsRegistry {
	public function histogram($key, $labels, $bucket, $value);

	// Increment the counter identified by $key and $labels by $val.
	public function counter_inc($key, $labels = [], $val = 1);

	// Returns all list of all known metrics.
	// [{'name' => string(), 'labels' => map(string() => string(), 'value' => int()}]
	public function getMetrics();
}