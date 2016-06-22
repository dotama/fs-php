# API

All requests must go to the `fs.php` script. If you want, you can play around with your webserver and
rewrite the URLs. You may need to modify the initial `handleRequest` method to make it work though. You can add
`?debug` to the URL to get a simple request dump instead of performing the actual request.

```
$ baseurl = "http://whereever.your.fs.php.lives/path/fs.php"
```

As a workaround to some http servers, all object keys can optionally end in `.ignore` which will be dropped when
reading/writing. `fs.bash` appends this automatically to all routes.

## Authentication

Authentication can happen over Basic Auth or using JWT as a bearer token and cookies. See `Configuration` for more details.

## Operations
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

A header `x-acl` contains the ACL of the object.

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

### Updating an Objects ACL

You can change the ACL of an object after its creating.

```
$ curl \$baseurl/demo.md?acl -XPUT -d'public-read'
```

The servers responds with a `204 No Content`.
