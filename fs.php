<?php

$DOC = <<<EOS
# fs.php
 
A simple one-file PHP endpoint for pushing and pulling files via HTTP.
It supports 4 basic operations:
 * Query for files with a certain prefix (like S3)
 * Upload a file by name
 * Fetch a file by name
 * Delete a file by name

The idea is similar to S3 - do not manage folders but just objects(files).

## Configuration

The file `fs.php` contains a `config` function. In there, two objects are initialized:

 * KeyManager

   Use `$keyManager->addBcryptCredentials($name, $hash)` to add a bcrypt hashed password.
   Use `$keyManager->addKey($name, $password)` to add a plain text password. Not recommended!

 * AccessManager

   Use `$accessManager->newPolicy()` to get a Policy object. It supports the following ways to modify the policy:

    * `deny()` - Marks this policy to restrict access. by default it grants access
    * `forUsername($username)` - Adds a filter to apply only to the given username. Can be used multiple times.
    * `forPrefix($prefix)` - Adds a filter to apply only to the given prefix or path. Can be used multiple times.
    * `permission($p)` - Adds a filter for the permission. `read` or `write`.
    * `description($text)` - A description for yourself. Code comments work as well.

  Example:

  ```
  $accessManager->newPolicy()
    ->description('Grant zeisss access to everything')
    ->forUsername('zeisss')
    ->forPrefix('/');

  $accessManager->newPolicy()
    ->description('Deny write access to /configs/')
    ->deny()->forPrefix("/configs/")->permission('write');
  ```

 * Bucket

   You cannot configure the bucket really.

The bucket takes the path where files should be created. The KeyManager manages the auth tokens that can be used
to authenticate with the API.

By default, `fs.php` also loads two files from the bucket itself, which can be used to customize the config:

 * `/configs/keys.php` - Put your `$keyManager` calls here
 * `/configs/policies.php` - Put your `$accessManager` calls here

## API

All requests must go to the `fs.php` script. If you want, you can play around with your webserver and 
rewrite the URLs. You may need to modify the initial `handleRequest` method to make it work though. You can add
`?debug` to the URL to get a simple request dump instead of performing the actual request.

```
$ baseurl = "http://whereever.your.fs.php.lives/path/fs.php"
```

As a workaround to some http servers, all object keys can optionally end in `.ignore` which will be dropped when
reading/writing. `fs.bash` appends this automatically to all routes.

### Authentication

Authentication is managed via http basic auth. See `Configuration` for more details.

### Listing objects

Lists all objects in the bucket. Use query parameter `prefix` to define a common prefix string. If given, it must
start with a /. 

```
$ curl \$baseurl/?prefix=/
{
	"prefix": "/",
	"delimiter": "/",
	"objects": [
		{"key": "/api.md", "acl":"public-read", "size": 100, "mtime": "2015-10-10T19:00:00Z", "mime": "plain/text"},
		{"key": "/README.md", "acl": "public-read", size": 100, "mtime": "2015-10-10T19:00:00Z", "mime": "plain/text"}
	]
}
```

By default, all objects are listed. If you just want to discover, you can pass `delimiter=/`, which splits the keys
and list the prefix in the field `common-prefixes`. In combination with the `prefix` parameter this allows to list 
files and folders easily.

### Get Object

Simply provide the key to the object behind the baseurl. The content-type will be `binary/octet-stream` for now.

```
$ curl \$baseurl/api.md
<this content>
```

A `404 Not Found` will be returned, if the given key does not exist. Otherwise a `200 OK`. If the file has the `public-read`
acl, no authorization is required.

PS: `HEAD` is also supported.

### Create an Object

Simply use `PUT` with the desired key and provide the content in the body.

```
$ curl \$baseurl/demo.md -XPUT -d 'This is the new content'
```

The server responds with a `204 No Content` if the upload was successful.

You can specify a `x-acl` header field, which can be either `private` or `public-read`. `private` is the default.
When set to `public-read`, _reading_ this file does not require authentication. This is mapped to file permissions.

### Deleting an Object

Use `DELETE` to delete an undesired object.

```
$ curl \$baseurl/demo.md -XDELETE
```

The server responds with a `204 No Content` if the delete was successful. If no such key exists, a `404 Not Found` is returned.

## Known Problems

At least on my hoster, PUTing a file with endings like `.txt` or `.gif` returns an early `Method Not Allowed` from the 
NGinx server.

If pushing binary files with `curl`, set the `Content-Type` header to something binary. Otherwise the server tries to 
parse the request and throws an error.
EOS;

class ACLs {
	private $acls;
	private $default;
	public function ACLs() {
		$this->acls = array();
	}

	public function define($name, $mode, $default = false) {
		$this->acls[$name] = array(
			'name' => $name,
			'mode' => $mode
		);

		if ($default) {
			$this->default = $name;
		}
	}

	public function defaultACL() {
		return $this->acls[$this->default];
	}

	public function byName($name) {
		return $this->acls[$name];
	}

	public function byMode($mode) {
		foreach ($this->acls AS $acl) {
			if ($acl['mode'] == $mode) {
				return $acl;
			}
		}
		return NULL;
	}

	public function allowsUnauthorizedRead($aclName) {
		$acl = $this->byName($aclName);
		return ($acl['mode'] & 04) > 0;
	}
}


class KeyManager {
	private $keys;

	public function KeyManager() {
		$this->keys = array();
	}
	public function addBcryptCredentials($name, $hash) {
		$key = array(
			'access' => $name,
			'secret' => $hash
		);
		$this->keys[] = $key;
	}
	public function addKey($name, $password) {
		$this->addBcryptCredentials($name, password_hash($password, PASSWORD_DEFAULT));
	}

	public function validCredentials($name, $password) {
		foreach ($this->keys AS $credentialPair) {
			if ($credentialPair['access'] == $name && password_verify($password, $credentialPair['secret'])) {
				return true;
			}
		}
		return false;
	}
}
class Policy {
	const EFFECT_ALLOW = 'allow';
	const EFFECT_DENY = 'deny';

	private $id;
	private $description;
	public $usernames;
	public $prefixes;

	public $effect = EFFECT_ALLOW;
	public $permissions = array();

	public function hasAccess() {
		return $this->effect == Policy::EFFECT_ALLOW;
	}

	public function deny() {
		$this->effect = Policy::EFFECT_DENY;
		return $this;
	}

	public function id($id) {
		$this->id = $id;
		return $this;
	}

	public function forPrefix($prefix) {
		$this->prefixes[] = $prefix;
		return $this;
	}

	public function forUsername($text) {
		$this->usernames[] = $text;
		return $this;
	}

	public function description($text) {
		$this->description = $text;
		return $this;
	}

	public function permission($permission) {
		$this->permissions[] = $permission;
		return $this;
	}
}
class AccessManager {
	private $policies;

	public function AccessManager() {
		$this->policies = array();
	}

	public function newPolicy() {
		$policy = new Policy();
		$this->policies[] = $policy;
		return $policy;
	}

	// addPolicy allows acces for the given $username for the given $prefix.
	public function addPolicy($username, $prefix, $allowRead, $allowWrite) {
		$policy = $this->newPolicy()
		    ->description('addPolicy')
			->forUsername($username)
			->forPrefix($prefix);

		if ($allowRead) {
			$policy->permission('read');
		}
		if ($allowWrite) {
			$policy->permission('write');
		}
		return $policy;
	}

	public function isGranted($prefix, $username, $permission) {
		$allowed = false;
		// Logic is as follows:
		// * Policy must match by username + prefix
		// * Policy must contain requested permission
		// * if any policies has effect=deny, it wins over an allow policy
		// * at least one policy must allow, other it also denies
		//
		// see also https://github.com/ory-am/ladon/blob/master/guard/guard.go 
		foreach($this->policies as $policy) {
			// Check usernames match
			if (sizeof($policy->usernames) > 0) {
				if (array_search($username, $policy->usernames, TRUE) === FALSE) {
					continue;
				}
			}

			// Check prefixes
			if (sizeof($policy->prefixes) > 0) {
				$found = false;
				foreach($policy->prefixes as $policyPrefix) {
					if (strpos($prefix, $policyPrefix) === 0) { // match!
						$found = true;
					}
				}

				if (!$found) {
					continue;
				}
			}

			// Check permissions
			if (sizeof($policy->permissions) > 0) {
				if (array_search($permission, $policy->permissions, TRUE) === FALSE) {
					continue;
				}
			}

			// Apply result
			if (!$policy->hasAccess()) {
				#echo "isGranted($username, $prefix, $permission) = false # access\n";
				return false;
			}
			$allowed = true;
		}
		#echo "isGranted($username, $prefix, $permission) = $allowed # allowed\n";
		return $allowed;
	}
}

class LocalBucket {
	private $path;
	private $acls;
	public function LocalBucket($acls, $path) {
		$this->path = $path;
		$this->acls = $acls;

		if (!is_dir($this->path)) {
			mkdir($this->path, 0777, true);
		}
	}

	public function listObjects($prefix, $showCommonPrefixes, &$outObjects, &$outCommonsPrefixes) {
		$path = $this->toDiskPath($prefix);
		#echo ">  $prefix\n>> $path\n"; 
		$files = glob($path . '*', GLOB_MARK | GLOB_NOSORT | GLOB_NOESCAPE);

		#echo json_encode($files) . "\n"
        foreach ($files as $file) {
        	#echo "# $file\n";
        	if (substr($file, -1) == '/') {
				if ($showCommonPrefixes) {
					$outCommonsPrefixes[] = $this->toBucketKey($file);
				} else {
					$this->listObjects($this->toBucketKey($file), $showCommonPrefixes, $outObjects, $outCommonsPrefixes);
				}
        	} else {
				$outObjects[] = $this->getObjectInfo($this->toBucketKey($file));
        	}
        }
	}

	public function getObjectInfo($key) {
		$diskPath = $this->toDiskPath($key);
		$stat = stat($diskPath);

		$time = new DateTime('now', new DateTimeZone('UTC'));
		$time->setTimestamp($stat['mtime']);

		$mode = fileperms($diskPath) & 0777;
		$acl = $this->acls->byMode($mode);

		return array(
			'key' => $key,
			'size' => $stat['size'],
			'acl' => $acl['name'],
			'mime' => mime_content_type($diskPath),
			'mtime' => $time->format(DATE_ATOM)
        );
	}

	public function putObject($key, $data, $aclName = NULL) {
		$diskPath = $this->toDiskPath($key);

		if ($aclName == NULL) {
			$acl = $this->acls->defaultACL();
		} else {
			$acl = $this->acls->byName($aclName);
			if ($acl == NULL) {
				throw new Exception("Invalid ACL: $aclName", 400);
			}
		}

		if (is_dir($diskPath)) {
			throw new Exception("Cannot create key with same name as common-prefix: $key", 400);
		}

		$dir = dirname($diskPath);
		@mkdir($dir, 0777, true);

		if (($handle = @fopen($diskPath, 'wb')) === false) {
			throw new Exception('Could not write object', 500);
		}
		if (false === fwrite($handle, $data)) {
			fclose($handle);
			throw new Exception('Error writing object', 500);
		}
		fflush($handle);
		fclose($handle);

		chmod($diskPath, $acl['mode']);
	}

	public function updateObjectACL($key, $aclName) {
		$acl = $this->acls->byName($aclName);
		if ($acl == NULL) {
			throw new Exception("Invalid ACL: $aclName", 400);
		}
		$diskPath = $this->toDiskPath($key);

		chmod($diskPath, $acl['mode']);
	}

	public function getObject($path) {
		$diskPath = $this->toDiskPath($path);
		if (!file_exists($diskPath)) {
			return NULL;
		}
		if (is_dir($diskPath)) {
			return NULL;
		}
		return file_get_contents($diskPath);	
	}

	public function deleteObject($path) {
		$diskPath = $this->toDiskPath($path);

		if (!file_exists($diskPath)) {
			return false;
		}

		unlink($diskPath);
		return true;
	}

	public function toDiskPath($path) {
		if ($path == "/") {
			return $this->path . "/";
		}

		if (substr($path, -7) == ".ignore") {
			$path = substr($path, 0, -7);
		}

		if ($path[0] != "/") {
			throw new Exception("Invalid path - must start with slash (/)");
		}

		$pathElements = explode("/", $path);
		#echo json_encode($pathElements)."\n";
		foreach ($pathElements AS $index => $s) {
			#echo "$index => $s\n";
			if ($index == 0) {
				if ($s != "") {
					throw new Exception('Invalid path element.');
				}
			}
			else if ($index == (sizeof($pathElements) - 1) && $s == "") {
				continue;
			} 
			else if ($s == "" || $s == "..") {	
				throw new Exception("Invalid empty path element.");
			}
		}
		return $this->path.$path;
	}
	private function toBucketKey($diskPath) {
		return substr($diskPath, strlen($this->path));
	}
}

class Server {
	private $bucket;
	private $keyManager;
	private $acls;
	private $accessManager;

	private $headers;
	private $params;
	private $host;
	private $method;
	private $path;
	private $username;

	public function Server($bucket, $keyManager, $acls, $accessManager) {
		$this->bucket = $bucket;
		$this->keyManager = $keyManager;
		$this->acls = $acls;
		$this->accessManager = $accessManager;
	}

	public function handleRequest($host, $method, $path, $headers, $params) {
		$this->headers = $headers;
		$this->params = $params;
		$this->host = $host;
		$this->method = $method;
		$this->path = $path;

		try {
			if (isset($params['debug'])) {
				$this->sendDebug();
				return;
			}
			switch ($method) {
			case "HEAD":
				if ($path == "/" || $path == "")
					$this->sendError(new Exception("HEAD not supported here."), 405);
				else 
					$this->handleGetObject();
			case "GET":
				if ($path == "/" || $path == "")
					$this->handleListObjects();
				else
					$this->handleGetObject();
			case "PUT":
				if (isset($params['acl'])) {
					$this->handlePutObjectACL();
				} else {
					$this->handlePutObject();	
				}
				
			case "DELETE":
				$this->handleDeleteObject();
			default:
				header("HTTP/1.0 405 Method Not Allowed");
				$this->sendDebug();
			}
		} catch (Exception $e) {
			$this->sendError($e, false);
		}
	}

	public function handlePutObjectACL() {
		$this->requiresAuthentication(true, $this->path);

		$newACL = file_get_contents('php://input');

		$this->bucket->updateObjectACL($this->path, $newACL);

		header("HTTP/1.1 204 No Content");
		die();
	}

	public function handleListObjects() {
		$prefix = "/";
		if (!empty($this->params['prefix'])) {
			$prefix = $this->params['prefix'];
		}
		$showCommonPrefixes = false;
		if (!empty($this->params['delimiter'])) {
			if ($this->params['delimiter'] == '/') {
				$showCommonPrefixes = true;
			} else {
				$this->sendError(new Exception('Invalid parameter: Parameter "delimiter" only supports "/"', 400), 400);
			}
		}

		$this->requiresAuthentication(false, $prefix);

		$outObjects = array();
		$outCommonsPrefixes = array();
		$this->bucket->listObjects($prefix, $showCommonPrefixes, $outObjects, $outCommonsPrefixes);

		$response = array();
		$response['prefix'] = $prefix;
		if ($showCommonPrefixes) {
			$response['delimiter'] = $this->params['delimiter'];
		}
		if (!empty($outObjects)) {
			$response['objects'] = $outObjects;
		}
		if (!empty($outCommonsPrefixes)) {
			$response['common-prefixes'] = $outCommonsPrefixes;
		}

		header('Content-Type: application/json');
		die(json_encode($response));
	}

	public function handleDeleteObject() {
		$this->requiresAuthentication(true, $this->path);

		$found = $this->bucket->deleteObject($this->path);

		if (!$found) {
			$this->sendError(new Exception('Not found', 404), 404);
		}

		header("HTTP/1.1 204 No Content");
		die();
	}

	public function handlePutObject() {
		$this->requiresAuthentication(true, $this->path);

		$acl = NULL;
		if (!empty($this->headers['x-acl'])) {
			$acl = $this->headers['x-acl'];
		}

		$data = file_get_contents('php://input');
		$this->bucket->putObject($this->path, $data, $acl);

		header("HTTP/1.1 201 Created");
		die();
	}

	public function handleGetObject() {
		$info = $this->bucket->getObjectInfo($this->path);
	
		if (!$this->acls->allowsUnauthorizedRead($info['acl'])) {
			$this->requiresAuthentication(false, $this->path);
		}

		$data = $this->bucket->getObject($this->path);

		if ($data == NULL) {
			$this->sendError(new Exception('Not found', 404), 404);
		}

		header('x-acl: ' . $info['acl']);
		if ($this->method == "GET") {
			header('Content-Length: '. $info['size']);
		} else {
			header("Content-Length: 0");
		}
		header('Content-Type: ' . $info['mime']);
		header("HTTP/1.1 200 OK");
		if ($this->method == "GET") {
			die($data);
		} else {
			die();
		}
	}

	private function sendDebug() {
		header('Content-Type: text/plain');
		
		echo "Host: " . $this->host . "\n";
		echo "Method: " . $this->method ."\n";
		echo "Path: " . $this->path ."\n";
		echo "\n";
		echo "Headers: " . json_encode($this->headers) . "\n";
		echo "Params: " . json_encode($this->params) . "\n";
		die();
	}

	private function sendError($exception, $code = 400) {
		switch ($code) {
		case 400:
			header("HTTP/1.1 400 Bad Request");
			break;
		case 401:
			header('HTTP/1.1 401 Unauthorized');
			break;
		case 403:
			header("HTTP/1.1 403 Forbidden");
			break;
		case 404:
			header("HTTP/1.1 404 Not Found");
			break;
		case 405:
			header("HTTP/1.1 405 Method Not Allowed");
			break;
		case 500:
			header("HTTP/1.1 500 Internal Server Errror");
			break;
		}

		header("Content-Type: application/json");

		$response = array(
			'error' => true,
			'message' => $exception->getMessage(),
			'code' => $exception->getCode()
		);
		die(json_encode($response)."\n");
	}

	private function requiresAuthentication($write, $prefix) {
		if (!$this->checkAuthentication()) {
			header('WWW-Authenticate: Basic realm="fs.php"');

			$this->sendError(new Exception("Authentication required", 401), 401);
		} else {
			$granted = $this->accessManager->isGranted(
				$prefix,
				$this->username, 
				$write ? 'write' : 'read'
			);

			if (!$granted) {
				$this->sendError(new Exception("Access denied (" . ($write ? "write" : "read") . ") for '{$this->path}'", 403), 403);
			}
		}
	}

	private function checkAuthentication() {
		$auth = $this->headers['authorization'];

		$fields = explode(" ", $auth);

		if (sizeof($fields) != 2) {
			return false;
		}
		if ($fields[0] != "Basic") {
			return false;
		}

		$credentials = explode(":", base64_decode($fields[1]));
		if (sizeof($credentials) != 2) {
			return false;
		}

		if ($this->keyManager->validCredentials($credentials[0], $credentials[1])) {
			$this->username = $credentials[0];
			return true;
		}

		return false;
	}
}

function acls() {
	$acls = new ACLs();
	$acls->define('public-read', 0664);
	$acls->define('private', 0660, true);
	return $acls;
}


function config() {
	global $DOC;

	$acls = acls();

	$bucket = new LocalBucket($acls, "/home/zeisss/var/fs");
	@$bucket->putObject('/api.md', $DOC, 'public-read');
	@$bucket->putObject('/README.md', "Manage files here via fs.php\nSee api.md too.", 'public-read');
	#$bucket->putObject('/folder.md', "Hello World");
	#$bucket->putObject('/folder/test.md', "Hello World");
	#$bucket->putObject('/folder/test2.md', "Hello World");

	$accessManager = new AccessManager;
	$keyManager = new KeyManager;
	# Replace this with your own secret credentials
	#   $keyManager->addBcryptCredentials('test', '$y2k$hashviabcrypt');
	#   $accessManager->newPolicy()->forUsername('test')->permission('write');
	# Or load the keys from the bucket itself
	@include($bucket->toDiskPath('/configs/keys.php'));
	@include($bucket->toDiskPath('/configs/policies.php'));

	return array($keyManager, $bucket, $acls, $accessManager);
}

function handleRequest() {
	global $_SERVER;

	$host = $_SERVER['HTTP_HOST'];
	$method = $_SERVER['REQUEST_METHOD'];
	$path = $_SERVER['PATH_INFO'];
	$params = $_GET;
	$headers = getallheaders();
	foreach($headers AS $key => $value) {
		$headers[strtolower($key)] = $value;
	}

	list($keyManager, $bucket, $acls, $accessManager) = config();
	$server = new Server($bucket, $keyManager, $acls, $accessManager);
	$server->handleRequest($host, $method, $path, $headers, $params);
}
function testPolicies() {
	$accessManager = new AccessManager;
	$accessManager->addPolicy('zeisss', '/', true, true);

	if (!$accessManager->isGranted('/artifacts/', 'zeisss', 'read')) {
		echo "AccessManager test1 failed\n";
	}
	if (!$accessManager->isGranted('/', 'zeisss', 'write')) {
		echo "AccessManager test2 failed\n";
	}

	$accessManager->newPolicy()->forUsername('zeisss')->forPrefix('/artifacts/')->deny()->permission('read');
	if ($accessManager->isGranted('/artifacts/', 'zeisss', 'read')) {
		echo "AccessManager test3 failed\n";
	}

    $accessManager = new AccessManager();
	$accessManager->newPolicy()
	  ->description('Grant zeisss access to everything')
	  ->forUsername('zeisss')->forPrefix('/')
	  ->permission('read')->permission('write');
	$accessManager->newPolicy()->deny()->forPrefix("/api.md")->permission('read')->forUsername('zeisss');
	if ($accessManager->isGranted('/api.md', 'zeisss', 'read')) {
		echo "AccessManager test4 failed\n";
	}
}

function tests() {
	testPolicies();

	$keyManager = new KeyManager;
	$keyManager->addKey('test', 'test');

	$acls = acls();
	$bucket = new LocalBucket($acls, "./data");
	
	$bucket->putObject('/test.txt', "Hello World\nSome lines\nYeah");
	$bucket->putObject('/file.txt', "Some content");
	$bucket->putObject('/folder.txt', "Hello World");
	$bucket->putObject('/folder/test.txt', "Hello World");
	$bucket->putObject('/folder/test2.txt', "Hello World");
	$bucket->putObject('/a/b/c/d.md', 'Deep recursive folder fiels.', 'public-read');


	# TESTS
	if (!$keyManager->validCredentials('test', 'test')) {
		echo "KeyManager test failed.\n";
	}

	$diskPaths = array(
		'/' => './data/',
		'/test.txt' => './data/test.txt',
		'/folder' => './data/folder',
		"/folder/" => './data/folder/'
	);

	#echo json_encode($diskPaths);
	foreach ($diskPaths AS $input => $output) {
		try {
			$result = $bucket->toDiskPath($input);
		} catch (Exception $e) {
			$result = $e;
		}
		if ($output != $result) {
			die("Test failed:\nInput: $input\nExpected: $output\nActual: $result\n");
		}
	}

	$obj = $bucket->getObjectInfo("/test.txt");
	echo json_encode($obj) . "\n\n";

	$objs = array();
	$prefixes = array();
	$bucket->listObjects("/folder/test", true, $objs, $prefixes);
	echo json_encode($objs) . "\n";
	echo json_encode($prefixes) . "\n";

}


handleRequest();
# tests();