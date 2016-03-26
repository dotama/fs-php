<?php

class LocalBucketTest {
  private function givenLocalBucket() {
    $acls = ACLs::defaultACLs();
    $bucket = new LocalBucket($acls, "./data");
    return $bucket;
  }

  public function testToDiskPath() {
    $bucket = $this->givenLocalBucket();
    // Test disk path mapping
    $diskPaths = array(
      '/' => './data/',
      '/test.txt' => './data/test.txt',
      '/folder' => './data/folder',
      "/folder/" => './data/folder/'
    );
    foreach ($diskPaths AS $input => $output) {
      try {
        $result = $bucket->toDiskPath($input);
      } catch (Exception $e) {
        $result = $e;
      }
      $this->assertEquals($result, $output);
    }
  }

  public function testLocalBucket() {
    	$bucket = $this->givenLocalBucket();

    	$bucket->putObject('/test.txt', "Hello World\nSome lines\nYeah");
    	$bucket->putObject('/file.txt', "Some content");
    	$bucket->putObject('/folder.txt', "Hello World");
    	$bucket->putObject('/folder/test.txt', "Hello World");
    	$bucket->putObject('/folder/test2.txt', "Hello World");
    	$bucket->putObject('/a/b/c/d.md', 'Deep recursive folder fiels.', 'public-read');

      // GetObjectInfo returns NULLL for wrong files
      $obj = $bucket->getObjectInfo('/doesnotexist');
      $this->assertNull($obj);

      // Test reading object metadata
    	$obj = $bucket->getObjectInfo("/test.txt");
      $this->assertNotNull($obj);
      $this->assertEquals("/test.txt", $obj['key']);
      $this->assertEquals(27, $obj['size']);
      $this->assertEquals('private', $obj['acl']);
      $this->assertEquals('text/plain', $obj['mime']);
      $this->assertNotEmpty(0, $obj['mtime']);

      // Test reading non-recursive
      $objs = array();
      $prefixes = array();
      $bucket->listObjects("/", true, $obj, $prefixes);
      $this->assertContains("/folder/", $prefixes);
      $this->assertContains("/a/", $prefixes);


      // Test files get listed, when share a prefix
      $objs = array();
      $prefixes = array();
      $bucket->listObjects("/folder", true, $objs, $prefixes);
      $this->assertContains("/folder", $prefixes);
      $this->assertEquals(1, sizeof($objs));
      $this->assertEquals('/folder.txt', $objs[0]['key']);

      // Test listing prefix contents
    	$objs = array();
    	$prefixes = array();
    	$bucket->listObjects("/folder/test", true, $objs, $prefixes);
      $this->assertEquals(2, sizeof($objs));
      $this->assertEmpty($prefixes);
  }
}
