<?php


class MessagingService {
	private $endpoint;
	private $accesss_key;
	private $secret_key;
	private $queue;

	public function Configure($endpoint, $access, $secret, $queue) {
		$this->endpoint = $endpoint;
		$this->access = $access;
		$this->secret = $secret;
		$this->queue = $queue;
	}
	public function Publish($obj) {
		if (empty($this->endpoint)) {
			return;
		}
		# Only push writes
		if ($obj['action'] === 'mfs::PutObject' ||
			$obj['action'] === 'mfs::PutObjectACL' ||
			$obj['action'] === 'mfs::DeleteObject') {
			$this->PushMessage(json_encode($obj), 'application/json');
		}
	}
	public function PushMessage($message, $content_type = 'binary/octet-stream')
	{
	  $params = array('http' => array(
	    'method' => 'POST',
	    'content' => $message,
	    'header' => "Authorization: Basic " . base64_encode($this->access . ":" . $this->secret). "\r\n" .
	      "Content-Type: $content_type\r\n"
	  ));

	  $ctx = stream_context_create($params);
	  $fp = @fopen($this->endpoint . "?action=PushMessage&queue={$this->queue}", 'rb', false, $ctx);
	  if (!$fp) {
	    throw new Exception("Problem with $this->endpoint, $php_errormsg");
	  }

	  $response = @stream_get_contents($fp);
	  if ($response === false) {
	    throw new Exception("Problem reading data from $this->endpoint, $php_errormsg");
	  }
	  return $response;
	}
}
