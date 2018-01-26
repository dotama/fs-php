<?php

require_once (__DIR__ . '/../StatsRegistry.php');

class MysqlStatsRegistry implements StatsRegistry {
	private $pdo;
	public function __construct($pdo) {
		$this->pdo = $pdo;
	}

	private function mapLabelsToTag($labels) {
		$output = "";
		foreach($labels as $key => $value) {
			$output .= ",${key}=\"${value}\"";
		}
		return substr($output, 1);
	}

	public function getMetrics() {
		$sql = 'SELECT name, labels, value FROM `mfs_stats`';
		$stmt = $this->pdo->prepare($sql);
		$stmt->execute(array());
		$rows = $stmt->fetchAll();

		$result = [];
		foreach($rows AS $row) {
			$result[] = array(
				'name' => $row['name'],
				'tags' => $this->mapLabelsToTag(json_decode($row['labels'])),
				'value' => $row['value']
			);
		}
		return $result;
	}

	public function counter_inc($name, $labels = [], $inc = 1) {
		$l = json_encode($labels);
		$sql = 'INSERT DELAYED INTO `mfs_stats` (`name`, `labels`, `value`) VALUES (?,?,?) ' .
			'ON DUPLICATE KEY UPDATE `value` = `value` + ?';
		$this->pdo->prepare($sql)->execute(array($name, $l, $inc, $inc));
	}

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

				$this->counter_inc($key . '_bucket', $labels, $value);
			}
		}
		$labels['le'] = '+Inf';
		$this->counter_inc($key . '_bucket', $labels, $value);
	}
}
