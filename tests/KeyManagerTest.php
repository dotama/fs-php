<?php

class KeyManagerTest extends PHPUnit_Framework_TestCase {
  public function testSimple() {
    $keyManager = new KeyManager();
  	$keyManager->addKey('test', 'test');

    $this->assertTrue($keyManager->validCredentials('test', 'test'));
    $this->assertFalse($keyManager->validCredentials('test', 'test2'));
    $this->assertFalse($keyManager->validCredentials('test', 'test '));
    $this->assertFalse($keyManager->validCredentials('test', ' test'));
    $this->assertFalse($keyManager->validCredentials(' test', 'test'));
  }
}
