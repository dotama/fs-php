# Objects API

The *Objects API* is accessable under the following URL:

```
http://apps.moinz.de/fs/fs.php
```

Generally all API responses are JSON responses, if not stated otherwise. If the URL parameter `pretty` is set, the responses will be pretty printed.

NOTE: All examples below are slightly pretty-printed for easier readability.

## Authentication

Authentication can happen over Basic Auth or using JWT as a bearer token and cookies.
A token can be obtained by contacting support.

NOTE: For all examples below that an authentication header is passed.

## Operations

### Listing objects

Lists all objects in the bucket. Use query parameter `prefix` to define a common prefix string. If given, it must
start with a /.

```
GET /fs/fs.php?prefix=/

{
	"prefix": "/",
	"objects": [
		{"key": "/docs/api.md", "acl":"public-read", "size": 100, "mtime": "2015-10-10T19:00:00Z", "mime": "plain/text"},
		{"key": "/README.md", "acl": "public-read", size": 100, "mtime": "2015-10-10T19:00:00Z", "mime": "plain/text"}
	]
}
```

By default, all objects are listed. If you just want to discover, you can pass `delimiter=/`, which splits the keys and list the prefix in the field `common-prefixes`.
In combination with the `prefix` parameter this allows to list
files and folders easily.

```
GET /fs/fs.php?prefix=/&delimiter=/

{
	"prefix": "/",
	"delimiter": "/",
	"common-prefixes": [
		"/docs/"
	],
	"objects": [
		{"key": "/README.md", "acl": "public-read", size": 100, "mtime": "2015-10-10T19:00:00Z", "mime": "plain/text"}
	]
}
```



```
GET /fs/fs.php?prefix=/docs&delimiter=/

{
	"prefix": "/docs",
	"delimiter": "/",
	"objects": [
		{"key": "/docs/api.md", "acl":"public-read", "size": 100, "mtime": "2015-10-10T19:00:00Z", "mime": "plain/text"}
	]
}
```

### Get Object

To read the contents of an objects, provide the key to the object behind the endpoint url. The content-type will be determined by the server.

```
GET /fs/fs.php/docs/api.md
```

A `404 Not Found` will be returned, if the given key does not exist. Otherwise a `200 OK`. If the file has the `public-read` acl, no authorization is required.

A header `x-acl` contains the ACL of the object.

PS: `HEAD` is also supported.

### Create an Object

Simply use `PUT` with the desired key and provide the content in the body.

```
PUT /fs/fs.php/demo.md

This is the new content
```

The server responds with a `201 Created` if the upload was successful.

You can specify a `x-acl` header field, which can be either `private` or `public-read`. `private` is the default.
When set to `public-read`, _reading_ this file does not require authentication.

### Deleting an Object

Use `DELETE` to delete an undesired object.

```
DELETE /fs/fs.php/demo.md
```

The server responds with a `204 No Content` if the delete was successful. If no such key exists, a `404 Not Found` is returned.

### Updating an Objects ACL

You can change the ACL of an object after it was created by issuing a PUT to the `?acl` resource.

```
PUT /fs/fs.php/demo.md?acl

public-read
```

The servers responds with a `204 No Content`. A `400 Bad Request` indicates a wrong ACL.

## Workarounds

All object keys can optionally end in `.ignore` which will be dropped when
reading/writing. `fs.bash` appends this automatically to all routes.
