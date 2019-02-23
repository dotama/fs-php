<?php

// Default implementation
trait Histogram {
	public function histogram($key, $labels, $buckets, $value) {
		$this->counter_inc($key . '_sum', $labels, $value);
		$this->counter_inc($key . '_count', $labels, 1);

		foreach ($buckets as $b) {
			if ($b >= $value) {
				$l = $labels;
				$l['le'] = $b;

				$this->counter_inc($key . '_bucket', $l, 1);
			}
		}

		# always increase the +Inf bucket
		$labels['le'] = '+Inf';
		$this->counter_inc($key . '_bucket', $labels, 1);
	}
}

interface StatsRegistry {
	public function histogram($key, $labels, $buckets, $value);

	// Increment the counter identified by $key and $labels by $val.
	public function counter_inc($key, $labels = [], $val = 1);

	// Returns all list of all known metrics.
	// [{'name' => string(), 'labels' => map(string() => string(), 'value' => int()}]
	public function getMetrics();
}