<?php

class AccessManagerTest extends PHPUnit_Framework_TestCase {
  public function givenAccessManagerWithReadOnlyPolicy() {
    $accessManager = new AccessManager();
    $accessManager->newPolicy()
      ->forUsername('zeisss')
      ->forPrefix('/')
      ->permission('mfs::Read*');
    return $accessManager;
  }

  public function givenAccessManagerWithPrefixedPolicy() {
    $accessManager = new AccessManager();
    $accessManager->newPolicy()
      ->forUsername('zeisss')
      ->forPrefix('/data')
      ->permission('mfs::*');
    return $accessManager;
  }

  public function testDenyPolicyHavePrecedense() {
    $accessManager = $this->givenAccessManagerWithPrefixedPolicy();
    $this->assertTrue($accessManager->isGranted('/data/forbidden', 'zeisss', 'mfs::ReadObject'));

    $accessManager->newPolicy()->forUsername('zeisss')
                  ->deny()
                  ->forPrefix('/data/forbidden')
                  ->permission('mfs::ReadObject');
    $this->assertFalse($accessManager->isGranted('/data/forbidden', 'zeisss', 'mfs::ReadObject'));
  }


  public function testPrefixWorks() {
    $am = $this->givenAccessManagerWithPrefixedPolicy();
    $this->assertTrue($am->isGranted('/data/blub', 'zeisss', 'mfs::PutObject'));
    $this->assertTrue($am->isGranted('/data/bla', 'zeisss', 'mfs::ReadObject'));

    $this->assertFalse($am->isGranted('/other/blub', 'zeisss', 'mfs::PutObject'));
    $this->assertFalse($am->isGranted('/other/blub', 'zeisss', 'mfs::ReadObject'));
  }

  public function testDefaultToDeny() {
    $am = new AccessManager();
    $this->assertFalse($am->isGranted('/artifacts/', 'zeisss', 'mfs::ReadObject'));
  }

  public function testPolicyGrantsAccess() {
    $accessManager = new AccessManager();
    $accessManager->newPolicy()
      ->forUsername('zeisss')
      ->forPrefix('/')
      ->permission('mfs::*');

    $this->assertTrue($accessManager->isGranted('/artifacts/', 'zeisss', 'mfs::ReadObject'));
  }

  public function testReadOnlyPolicyDeniesWrite() {
    $am = $this->givenAccessManagerWithReadOnlyPolicy();
    $this->assertFalse($am->isGranted('/object', 'zeisss', 'mfs::PutObject'));
    $this->assertFalse($am->isGranted('/object', 'zeisss', 'mfs::PutObjectACL'));
    $this->assertFalse($am->isGranted('/object', 'zeisss', 'mfs::DeleteObject'));
  }
}
