# fs.php

A simple one-file PHP endpoint for pushing and pulling files via HTTP.
It supports 4 basic operations:

 * Query for files with a certain prefix (like S3)
 * Upload a file by name
 * Fetch a file by name
 * Delete a file by name

The idea is similar to S3 - do not manage folders but just objects(files).

## Configuration

The file `fs.php` contains a `config` function. It loads the `fs.config.php` file in the same folder,
which allows the basic configuration. The following objects and variables can be configured:

 * `$bucketPath` - MUST be configured to point to the place where the files should be located.

 * `$bucketConfigFiles` - Optional. Can be set to contain further files inside the bucket that should be loaded.

    Example:

    ```
    $bucketConfigFiles = ['/configs/policies.php'];
    ```

 * `$keyManager`

    The KeyManager manages the auth tokens that can be used to authenticate with the API.

    Use `$keyManager->addBcryptCredentials($name, $hash)` to add a bcrypt hashed password.
    Use `$keyManager->addKey($name, $password)` to add a plain text password. Not recommended!

 * `$accessManager`

    Use `$accessManager->newPolicy()` to get a Policy object. It supports the following ways to modify the policy:

    * `deny()` - Marks this policy to restrict access. by default it grants access
    * `forUsername($username)` - Adds a filter to apply only to the given username. Can be used multiple times.
    * `forPrefix($prefix)` - Adds a filter to apply only to the given prefix or path. Can be used multiple times.
    * `permission($p)` - Adds a filter for the permission. See permissions below.
    * `description($text)` - A description for yourself. Code comments work as well.

    Example:

    ```
    $accessManager->newPolicy()
      ->description('Grant zeisss access to everything')
      ->forUsername('zeisss')
      ->forPrefix('/');

    $accessManager->newPolicy()
      ->description('Deny write access to /configs/')
      ->deny()->forPrefix("/configs/")
      ->permission('mfs::(Delete|Put)*');
    ```

## Policies

The `$accessManager` allows a more fine grained configuration of users to objects.

### Permissions

Each operations equals on permissions that is expected to be granted by a policy to perform the operation:

 * `mfs::ListObjects`
 * `mfs::GetObject`
 * `mfs::PutObject`
 * `mfs::PutObjectACL`
 * `mfs::DeleteObject`

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

Previouslys on my hoster, PUTing a file with endings like `.txt` or `.gif` returned an early `Method Not Allowed` from the 
NGinx server.

If pushing binary files with `curl`, set the `Content-Type` header to something binary. Otherwise the server tries to 
parse the request and throws an error.