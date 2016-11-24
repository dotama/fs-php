# Authorization: Conditions

Conditions are a mechanism to define more complex rules in your policies. It is inspired by Amazon's IAM conditions.

```
$accessManager-newPolicy()->withCondition([
	"Operation" => [
		"fieldName" => "expectedValueOrPattern",
		"otherField" => ["validValue1", "validValue2"]
	],
	// ... more conditions
]);

```

The Policy object provides a `withCondition()` function to define a set of conditions that must be matched for the policy to be fulfilled. The set is indexed by the operation names. To check a policy, each condition is checked.

Each condition points to a table of field names and their expected values. The expected value can be either a single value or a list of values. The field name is looked up from the request context. Valid variables are listed below. If the variable is not available in the request, the policy is not fulfilled.

Based on the operation, the comparison can vary slightly. For `StringEquals`, an exact match of the left side and right side is required. For `DateGreatherThan`, both sides must be a valid datestring in ISO8601 format. An invalid date will abort the check, even if both sides are equal.

## Available Variables

Variable         | Example | Comment
---------------- | ------- | -----------------------------
sys::CurrentTime | `"2016-02-29T14:55:59Z"` | The current time in ISO8601 notation
authn::username  | `"bob"`                   | The username of the requestee
req::ip          | `"127.0.0.1"`             | The client IP
resource         | `"mfs:/artifacts/latest.tar"` | The resource URI
permission       | `"mfs::GetObject"`            | The required permission for the current request to proceed

## Operations

### String

* `StringEquals` - Checks for exact match of the left handside and the right handside
* `StringEqualsIgnoreCase` - Same as `StringEquals` but ignores the case
* `StringNotEquals` - Invert of `StringEquals`
* `StringNotEqualsIgnoreCase` - Invert of `StringEqualsIgnoreCase` 
* `StringLike` - The right handside can contain wildcards: `*` for multiple characters, `?` for a single one anywhere in the string
* `StringNotLike` - Invert of `StringLike`

### Date

* `DateGreaterThan`
* `DateLessThan`

### Bool

* `Bool` 

## Examples

* Auto expire an account at the end of the year:

    ```
    $accessManager->newPolicy()->description('Expire bobs account at the end of the year')
	   ->forUsername('bob')
	   ->withCondition([
		  'DateLessThan' => [
			'sys::CurrentTime' => '2016-12-31T23:59:59Z'
		  ]
	   ])
      ->deny();
    ```

* Every authenticated user can write to his own names prefix

	```
	$accessManager->newPolicy()
		->description("Every authenticated user can write to his own names prefix")
		->permission('*')
		->withCondition([
			"StringLike" => ["resource" => '/*/']
		]);
	```