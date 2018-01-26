<?php

// Default implementation
trait Histogram {
	public function histogram($key, $labels, $buckets, $value) {
		$l = json_encode($labels);

		$deltas = array(
			$key . '_sum' => $value,
			$key . '_count' => 1,
		);

		# http_request_duration_seconds_bucket{le="0.1"}
		# http_request_duration_seconds_sum
		# http_request_duration_seconds_count

		$this->counter_inc($key . '_sum', $labels, $value);
		$this->counter_inc($key . '_count', $labels, 1);

		foreach ($buckets as $b) {
			if ($value <= $b) {
				$l = $labels;
				$l['le'] = $b;

				$this->counter_inc($key . '_bucket', $l, $value);
			}
		}
		$labels['le'] = '+Inf';
		$this->counter_inc($key . '_bucket', $labels, $value);
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