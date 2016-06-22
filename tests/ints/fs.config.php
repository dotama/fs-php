<?php

$bucketPath = ".";

# Overwrite KeyManager to allow all credentials
class StaticKeyManager {
  	public function validCredentials($name, $password) {
      return true;
    }
}
$keyManager = new StaticKeyManager();

$accessManager->newPolicy()->forPrefix('/')->forUsername('test')->permission('*');
$accessManager->newPolicy()->forPrefix('*')->forUsername('test')->permission('mfs::FetchPrometheusMetrics');
