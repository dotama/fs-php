<?php

$DOC = <<<EOS
# fs.php
 
A simple PHP endpoint for pushing and pulling files via HTTP.
It supports 4 basic operations:
 * Query for files with a certain prefix (like S3)
 * Upload a file by name
 * Fetch a file by name
 * Delete a file by name

The idea is similar to S3 - do not manage folders but just objects(files).

## Configuration

The file `fs.php` contains a `config` function. In there, two objects are initialized:

 * KeyManager
 * Bucket

The bucket takes the path where files should be created. The KeyManager manages the auth tokens that can be used
to authenticate with the API.

## API

All requests must go to the `fs.php` script. If you want, you can play around with your webserver and 
rewrite the URLs. You may need to modify the initial `handleRequest` method to make it work though. You can add
`?debug` to the URL to get a simple request dump instead of performing the actual request.

```
$ baseurl = "http://whereever.your.fs.php.lives/path/fs.php"
```

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
		{"key": "/api.md", "size": 100, "mtime": "2015-10-10T19:00:00Z"},
		{"key": "/README.md", "size": 100, "mtime": "2015-10-10T19:00:00Z"}
	]
}
```

### Get Object

Simply provide the key to the object behind the baseurl. The content-type will be `binary/octet-stream` for now.

```
$ curl \$baseurl/api.md
<this content>
```

A `404 Not Found` will be returned, if the given key does not exist. Otherwise a `200 OK`. If the file has the `public-read`
acl, no authorization is required.

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
	public function addKey($name, $password) {
		$this->keys[] = array(
			'access' => $name,
			'secret' => $password
		);
	}
	public function validCredentials($name, $password) {
		foreach ($this->keys AS $credentialPair) {
			if ($credentialPair['access'] == $name && $credentialPair['secret'] == $password) {
				return true;
			}
		}
		return false;
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

	public function listObjects($prefix, &$result) {
		
		$path = $this->toDiskPath($prefix);
		#echo ">  $prefix\n>> $path\n"; 
		$files = glob($path . '*', GLOB_MARK | GLOB_NOSORT | GLOB_NOESCAPE);

		#echo json_encode($files) . "\n";

        foreach ($files as $file) {
        	#echo "# $file\n";
        	if (substr($file, -1) == '/') {
        		$this->listObjects($this->toBucketKey($file), $result);
        	} else {
        		$result[] = $this->getObjectInfo($this->toBucketKey($file));
        	}
        }
	}

	public function getObjectInfo($key) {
		$diskPath = $this->toDiskPath($key);
		$stat = stat($diskPath);

		#echo json_encode($stat) . "\n";

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

	public function putObject($path, $data, $aclName = NULL) {
		$diskPath = $this->toDiskPath($path);

		if ($aclName == NULL) {
			$acl = $this->acls->defaultACL();
		} else {
			$acl = $this->acls->byName($aclName);
			if ($acl == NULL) {
				throw new Exception("Invalid ACL: $aclName");
			}
		}

		if (is_dir($diskPath)) {
			throw new Exception("Path exists");
		}

		$dir = dirname($diskPath);
		@mkdir($dir, 0777, true);

		if (($handle = @fopen($diskPath, 'wb')) === false) {
			throw new Exception('Could not write object');
		}
		if (false === fwrite($handle, $data)) {
			fclose($handle);
			throw new Exception('Error writing object');
		}
		fflush($handle);
		fclose($handle);

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
	public function Server($bucket, $keyManager, $acls) {
		$this->bucket = $bucket;
		$this->keyManager = $keyManager;
		$this->acls = $acls;
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
			case "GET":
				if ($path == "/" || $path == "")
					$this->handleListObjects();
				else
					$this->handleGetObject();
			case "PUT":
				$this->handlePutObject();
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

	public function handleListObjects() {
		$this->requiresAuthorization();

		$prefix = "/";
		if (isset($this->params['prefix'])) {
			$prefix = $this->params['prefix'];
		}
		$result = array();
		$this->bucket->listObjects($prefix, $result);


		header('Content-Type: application/json');
		die(json_encode(array(
			'prefix' => $prefix,
			'delimiter' => '/',
			'objects' => $result
		)));
	}

	public function handleDeleteObject() {
		$this->requiresAuthorization();

		$found = $this->bucket->deleteObject($this->path);

		if (!$found) {
			header("HTTP/1.1 404 Not Found");
			die("Not found");
		}

		header("HTTP/1.1 204 No Content");
		die();
	}

	public function handlePutObject() {
		$this->requiresAuthorization();

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
			$this->requiresAuthorization();
		}

		$data = $this->bucket->getObject($this->path);

		if ($data == NULL) {
			header("HTTP/1.1 404 Not Found");
			die("Not found");
		}

		header('x-acl: ' . $info['acl']);
		header('Content-Length: '. $info['size']);
		header('Content-Type: ' . $info['mime']);
		header("HTTP/1.1 200 OK");
		die($data);
	}


	private function sendDebug() {
		header('Content-Type: text/plain');
		
		echo $this->host . "\n";
		echo $this->method ."\n";
		echo $this->path ."\n";
		echo json_encode($this->headers) . "\n";
		echo json_encode($this->params) . "\n";
	}

	private function sendError($exception, $userError) {
		if ($userError) {
			header("HTTP/1.1 400 Bad Request");
		} else {
			header("HTTP/1.1 500 Internal Server Errror");
		}

		$response = array(
			'error' => true,
			'message' => $exception->getMessage(),
			'code' => $exception->getCode()
		);
		die(json_encode($response));
	}

	private function requiresAuthorization() {
		if (!$this->checkAuthorization()) {
			header('WWW-Authenticate: Basic realm="fs.php"');
			header('HTTP/1.0 401 Unauthorized');
			die("Unauthorized\n");
		}
	}

	private function checkAuthorization() {
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

		return $this->keyManager->validCredentials($credentials[0], $credentials[1]);
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

	$bucket = new LocalBucket($acls, "/home/zeisss/var/data/myfiles");
	@$bucket->putObject('/api.md', $DOC, 'public-read');
	@$bucket->putObject('/README.md', "Manage files here via fs.php\nSee api.md too.", 'public-read');
	#$bucket->putObject('/folder.md', "Hello World");
	#$bucket->putObject('/folder/test.md', "Hello World");
	#$bucket->putObject('/folder/test2.md', "Hello World");

	$keyManager = new KeyManager;
	# Replace this with your own secret credentials
	#   $keyManager->addKey('test', 'test');
	# Or load the keys from the bucket itself
	@include($bucket->toDiskPath('/keys.php'));

	return array($keyManager, $bucket, $acls);
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

	list($keyManager, $bucket, $acls) = config();
	$server = new Server($bucket, $keyManager, $acls);
	$server->handleRequest($host, $method, $path, $headers, $params);
}

function tests() {
	$keyManager = new KeyManager;
	$keyManager->addKey('test', 'test');

	$acls = acls();
	$bucket = new LocalBucket($acls, "./data");
	
	$bucket->putObject('/test.txt', "Hello World\nSome lines\nYeah");
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
	echo json_encode($obj);

}


handleRequest();
#tests();