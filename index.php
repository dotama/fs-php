<?php

# This file should not be called. we just throw an invalid request error here.
header("HTTP/1.1 400 Bad Request");
header("Content-Type: application/json");

$response = array(
  'error' => true,
  'message' => 'invalid endpoint',
  'detail' => 'Consult documentation for the correct endpoint url.',
  'code' => 400
);
die(json_encode($response)."\n");
