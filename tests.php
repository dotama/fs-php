<?php

require_once(__DIR__ . '/lib/iam.php');
require_once(__DIR__ . '/lib/filesystem.php');

function fail($message) {
  echo "FAIL: ";
  echo $message;
  exit(1);
}
function TestPolicies() {
	$accessManager = new AccessManager;
	$accessManager->newPolicy()
		->forUsername('zeisss')
		->forPrefix('/')
		->permission('mfs::*');

	if (!$accessManager->isGranted('/artifacts/', 'zeisss', 'mfs::ReadObject')) {
		fail("AccessManager test1 failed");
	}
	if (!$accessManager->isGranted('/', 'zeisss', 'mfs::PutObject')) {
		fail("AccessManager test2 failed");
	}

	$accessManager->newPolicy()->forUsername('zeisss')
	              ->deny()
	              ->forPrefix('/artifacts/')
	              ->permission('mfs::ReadObject');
	if ($accessManager->isGranted('/artifacts/', 'zeisss', 'mfs::ReadObject')) {
		fail("AccessManager test3 failed - expected ReadObject to be denied");
	}

  $accessManager = new AccessManager();
	$accessManager->newPolicy()
	  ->description('Grant zeisss access to everything')
	  ->forUsername('zeisss')->forPrefix('/')
	  ->permission('mfs::*');
	$accessManager->newPolicy()->deny()->forPrefix("/api.md")->permission('mfs::ReadObject')->forUsername('zeisss');
	if ($accessManager->isGranted('/api.md', 'zeisss', 'read')) {
		fail("AccessManager test4 failed - expected ReadObject to be denied");
	}

	$accessManager = new AccessManager();
	$accessManager->newPolicy()->permission('mfs::*'); // allow all by default
	$accessManager->newPolicy()->deny()->permission('mfs::(Delete|Put)*'); // Deny writes
	if($accessManager->isGranted('/api.md', 'zeisss', 'DeleteObject')) {
		fail("AccessManager test5 failed - expected DeleteObject to be denied");
	}
	if($accessManager->isGranted('/api.md', 'zeisss', 'PutObjectACL')) {
		fail("AccessManager test5 failed - expected PutObjectACL to be denied");
	}
}

function TestLocalBucket() {
	$keyManager = new KeyManager;
	$keyManager->addKey('test', 'test');

	$acls = ACLs::defaultACLs();
	$bucket = new LocalBucket($acls, "./data");

	$bucket->putObject('/test.txt', "Hello World\nSome lines\nYeah");
	$bucket->putObject('/file.txt', "Some content");
	$bucket->putObject('/folder.txt', "Hello World");
	$bucket->putObject('/folder/test.txt', "Hello World");
	$bucket->putObject('/folder/test2.txt', "Hello World");
	$bucket->putObject('/a/b/c/d.md', 'Deep recursive folder fiels.', 'public-read');


	# TESTS
	if (!$keyManager->validCredentials('test', 'test')) {
		fail("KeyManager test failed.");
	}

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
		if ($output != $result) {
			fail("Input: $input\nExpected: $output\nActual: $result\n");
		}
	}

  // Test reading object metadata
	$obj = $bucket->getObjectInfo("/test.txt");
  if ($obj == NULL) fail("GetObjectInfo() returned NULL.");
  if ($obj['key'] != '/test.txt') fail("Expected key /test.txt, got ${obj['key']}");
  if ($obj['size'] != 27) fail("Expected size 27, got ${obj['size']}");
  if ($obj['acl'] != 'private') fail("Expected acl 'private', got ${obj['acl']}");
  if ($obj['mime'] != 'text/plain') fail("Expected mime 'text/plain', got ${obj['mime']}");
  if (!isset($obj['mtime'])) fail("Expected mtime, got ${obj['mtime']}");

  // Test reading non-recursive
  $objs = array();
  $prefixes = array();
  $bucket->listObjects("/", true, $obj, $prefixes);
  if (!in_array("/folder/", $prefixes)) fail("Expected folder /folder/ to be in the prefixes");
  if (!in_array("/a/", $prefixes)) fail("Expected folder /a/ to be in the prefixes");

  // Test files get listed, when share a prefix
  $objs = array();
  $prefixes = array();
  $bucket->listObjects("/folder", true, $objs, $prefixes);
  if (!in_array("/folder/", $prefixes)) fail("Expected folder /folder/ to be in the prefixes");
  if (sizeof($objs) != 1) fail("Expected 1 object, got ".sizeof($objs));
  if ($objs[0]['key'] != "/folder.txt") fail("Expected object /folder.txt to be returned");

  // Test listing prefix contents
	$objs = array();
	$prefixes = array();
	$bucket->listObjects("/folder/test", true, $objs, $prefixes);
  if (sizeof($objs) != 2) fail("Expected 2 objects, got ".json_encode($objs));
	if (sizeof($prefixes) != 0) fail("Expected no prefixes, got ".sizeof($prefixes));
}

function TestSuite() {
  TestPolicies();
  TestLocalBucket();
}

TestSuite();
