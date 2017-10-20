<?php

require_once (__DIR__ . '/../StatsRegistry.php');

class MysqlStatsRegistry implements StatsRegistry {
	private $pdo;
	public function __construct($pdo) {
		$this->pdo = $pdo;
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
				'labels' => json_decode($row['labels']),
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
}
