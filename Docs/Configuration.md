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
    It is the store for the `BasicAuthenticator` that gets installed by default.
    This behavior can be disabled by setting `$keyManager` to `null`.

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

* `$authenticators`

  An array of `RequestAuthenticator` objects. `$keyManager` will be added to it,
  after the config file has been included.

  `RequestAuthenticator` is described in the `lib/KeyManager.php` file.

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

## Provided RequestAuthenticator

fs-php comes with the following implementations of `RequestAuthenticator`:

 * BasicAuthenticator
 * JWTAndSessionAuthenticator

The `BasicAuthenticator` takes a username/password via htbasic and compares it to
a bcrypted list of passwords. This can be configured through the `$keyManager`
variable as explained above.

### JWT

The `JWTAndSessionAuthenticator` is an authenticator that starts a new PHP session
upon seeing a valid JWT token. The `sub` claim is taken as the username of the client.

To active, register it as an authenticator. The second and third parameter are optional.

> $authenticators[] = new JWTAndSessionAuthenticator(
>   "secret-key"
>   # , "bearer"        # authentication header scope to look for
>   # , array('HS256')  # algorithms to accept for signature
> );

Clients must support cookies as described in [RFC
6265](https://tools.ietf.org/html/rfc6265).

This allows an external service to act as an authorization service. The client or
enduser authenticates with that service, which issues a JWT token that can be
used with fs-php to start a session. The token can be short-lived and run on any
server. They only need to share a secret key.

## Known Problems

Previouslys on my hoster, PUTing a file with endings like `.txt` or `.gif`
returned an early `Method Not Allowed` from the Nginx server.

If pushing binary files with `curl`, set the `Content-Type` header to something
binary. Otherwise the server tries to parse the request and throws an error.
