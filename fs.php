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
rewrite the URLs. You may need to 

```
$ baseurl = "http://whereever.your.fs.php.lives/path/fs.php"
```

### Authentication

Authentication is managed via http basic auth. See `Configuration` for more details.

### Listing objects

Lists all objects in the bucket. Use query parameter `prefix` to define a common prefix string. If given, it must
start with a /. 

```
$ curl $baseurl/?prefix=/
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
$ curl $baseurl/api.md
<this content>
```

A `404 Not Found` will be returned, if the given key does not exist. Otherwise a `200 OK`.

### Create an Object

Simply use `PUT` with the desired key and provide the 

```
$ curl $baseurl/demo.md -XPUT -d 'This is the new content'
```

The server responds with a `204 No Content` if the upload was successful.

### Deleting an Object

Use `DELETE` to delete an undesired object.

```
$ curl $baseurl/demo.md -XDELETE
```

The server responds with a `204 No Content` if the delete was successful. If no such key exists, a `404 Not Found` is returned.
EOS;

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
	public function LocalBucket($path) {
		$this->path = $path;
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

		$time = new DateTime('now', new DateTimeZone('UTC'));
		$time->setTimestamp($stat['mtime']);

		return array(
        	'key' => $key,
        	'size' => $stat['size'],
        	'mtime' => $time->format(DATE_ATOM)
        );
	}

	public function putObject($path, $data) {
		$diskPath = $this->toDiskPath($path);

		if (is_dir($diskPath)) {
			throw new Exception("Path exists");
		}

		$dir = dirname($diskPath);
		@mkdir($dir);

		file_put_contents($diskPath, $data);
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
	public function Server($bucket, $keyManager) {
		$this->bucket = $bucket;
		$this->keyManager = $keyManager;
	}

	public function handleRequest($host, $method, $path, $headers, $params) {
		if (!$this->checkAuthorization($headers)) {
			header('WWW-Authenticate: Basic realm="fs.php"'); 
    		header('HTTP/1.0 401 Unauthorized'); 
    		die("Unauthorized\n");
		}

	
		try {
			if (isset($params['debug'])) {
				$this->sendDebug($host, $method, $path, $headers, $params);
				return;
			}
			switch ($method) {
			case "GET":
				if ($path == "/")
					$this->handleListObjects($params);
				else
					$this->handleGetObject($path);
			case "PUT":
				$this->handlePutObject($path, file_get_contents('php://input'));
			case "DELETE":
				$this->handleDeleteObject($path);
			default:
				header("HTTP/1.0 405 Method Not Allowed");
				$this->sendDebug($host, $method, $path, $headers, $params);
			}
		} catch (Exception $e) {
			$this->sendError($e);
		}
	}

	public function handleListObjects($params) {
		$prefix = "/";
		if (isset($params['prefix'])) {
			$prefix = $params['prefix'];
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

	public function handleDeleteObject($path) {
		$found = $this->bucket->deleteObject($path);

		if (!$found) {
			header("HTTP/1.1 404 Not Found");
			die("Not found");
		}

		header("HTTP/1.1 204 No Content");
		die();
	}

	public function handlePutObject($path, $stream) {
		$this->bucket->putObject($path, $stream);

		header("HTTP/1.1 201 Created");
		die();
	}

	public function handleGetObject($path) {
		$data = $this->bucket->getObject($path);

		if ($data == NULL) {
			header("HTTP/1.1 404 Not Found");
			die("Not found");
		}

		header('Content-Type: binary/octet-stream');
		header("HTTP/1.1 200 OK");
		die($data);
	}


	private function sendDebug($host, $method, $path, $headers, $params) {
		header('Content-Type: text/plain');
		
		echo $host . "\n";
		echo $method ."\n";
		echo $path ."\n";
		echo json_encode($headers) . "\n";
		echo json_encode($params) . "\n";
	}
	private function sendError($exception) {
		header("HTTP/1.1 400 Bad Request");

		$response = array(
			'error' => true,
			'message' => $exception->getMessage(),
			'code' => $exception->getCode()
		);
		die(json_encode($response));
	}

	private function checkAuthorization($headers) {
		$auth = $headers['authorization'];

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


function config() {
	global $DOC;
	$keyManager = new KeyManager;
	# Replace this with your own secret credentials
	$keyManager->addKey('test', 'test');

	$bucket = new LocalBucket("/home/zeisss/var/data/myfiles");
	$bucket->putObject('/api.md', $DOC);
	$bucket->putObject('/README.md', "Manage files here via fs.php\nSee api.md too.");
	#$bucket->putObject('/folder.md', "Hello World");
	#$bucket->putObject('/folder/test.md', "Hello World");
	#$bucket->putObject('/folder/test2.md', "Hello World");

	return array($keyManager, $bucket);
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

	list($keyManager, $bucket) = config();
	$server = new Server($bucket, $keyManager);
	$server->handleRequest($host, $method, $path, $headers, $params);
}

function tests() {
	$keyManager = new KeyManager;
	$keyManager->addKey('test', 'test');


	$bucket = new LocalBucket("./data");
	
	$bucket->putObject('/test.txt', "Hello World\nSome lines\nYeah");
	$bucket->putObject('/folder.txt', "Hello World");
	$bucket->putObject('/folder/test.txt', "Hello World");
	$bucket->putObject('/folder/test2.txt', "Hello World");


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