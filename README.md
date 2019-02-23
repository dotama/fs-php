# fs-php

A simple one-file PHP endpoint for pushing and pulling files via HTTP.
It supports 4 basic operations:

 * Query for files with a certain prefix (like S3)
 * Upload a file by name
 * Fetch a file by name
 * Delete a file by name

The idea is similar to S3 - do not manage folders but just objects(files).


Features:

 * Very simple JSON-API
 * configurable authentication via HTBasic or JWT tokens
 * configurable authorization model similar Amazons IAM
   * Allow/Deny policies
   * Resource/Permission/User based matching
   * Complex Conditions based on per-request variables
* Prometheus text-metrics support
* No database needed - everything written to disk

## This Repo

* Automatic PRs via https://dependabot.com/
* CI via https://wercker.com [![wercker status](https://app.wercker.com/status/91e3f6a841880b8e3e9327c02b684aa4/s/master "wercker status")](https://app.wercker.com/project/byKey/91e3f6a841880b8e3e9327c02b684aa4)