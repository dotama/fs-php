# Configuration

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


fs-php has a policy concept to support fine grained control over the actions each
user can perform. Policy objects can grant or deny rights based on user,
resource and action.

Upon a request at least one policy must be found that grants access. If none
matches, the request is denied. If one deny-policy matches, the request is
aborted immediately.

Call `$accessManager->newPolicy()` to obtain a new policy.

* `deny()` modifies the effect of the policy to deny the request immediately. By
  default a policy grants access.
* `forUsername(string)` specifies the username that must be provided by the
  authenticator.
* `forResource(string)` specifies the URI of the resource. Resource in fs-php are
  specified as `mfs:PATH`, where `PATH` is the prefix that is operated upon.
* `forPrefix(string)` is an alias for `forResource()` that prefixes the argument
  with `mfs:`.
* `forUsername(string)` specifies the username that should be matched upon.
* `permission(string)` specifies the action that this policy matches on.
* `description(string)` allows to provide a description for the administrator.
* `id(string)` specifies an identifier for the policy object.

The string parameter for matching support wildcards and grouping.

 * `forUsername('(adam|bob|eve)')` match all three usernames.
 * `forUsername('z*')` match all users starting with a `z`.


### Permissions

Each operations equals on permissions that is expected to be granted by a policy to perform the operation:

 * `mfs::ListObjects`
 * `mfs::GetObject`
 * `mfs::PutObject`
 * `mfs::PutObjectACL`
 * `mfs::DeleteObject`

## Known Problems

Previouslys on my hoster, PUTing a file with endings like `.txt` or `.gif` returned an early `Method Not Allowed` from the
NGinx server.

If pushing binary files with `curl`, set the `Content-Type` header to something binary. Otherwise the server tries to
parse the request and throws an error.
