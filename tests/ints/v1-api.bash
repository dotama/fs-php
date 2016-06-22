#!/bin/bash

assertEquals() {
  local expected=$1
  local value=$2

  test "$expected" = "$value" || (echo "Expected '$expected', got '$value'" && exit 1)
}

assertOutputContains() {
  local text=$1

  grep -q "${text}" ./output || (echo "Expected ./output to contain '${text}'" && exit 1)
}

assertEqualsFile() {
  local expected=$1
  local file=$2

  diff $expected $file || (echo "File differs: '$expected' != '$file'" && exit 1)
}

assertJSONContains() {
  local file=$1
  local path=$2
  local match=$3

  jq -r "$path" < "$file" | fgrep "$match" >/dev/null || (echo "Expected $file to contain '$match' for path '$path'." && exit 1)
}

assertJSONNotContains() {
  local file=$1
  local path=$2
  local match=$3

  jq -r "$path" < "$file" | fgrep -v "$match" >/dev/null || (echo "Expected $file to _NOT_ contain '$match' for path '$path'." && exit 1)
}

WRONGAUTH="-u doesnotexists:NorThisPassword"
OPTS="--silent --output ./output -w %{http_code}"

setUp() {
  #WITHAUTH="-u test:test" #see fs.config.php in tests/ints/
  #ENDPOINT="http://localhost:4900/fs.php"
  #php -S localhost:49000 &
  #FSPID=$!
  test -z "$ENDPOINT" && echo 'Required: $ENDPOINT' && exit 1
  test -z "$WITHAUTH" && echo 'Required: $WITHAUTH' && exit 1
  echo "setUp done"
  true
}

tearDown() {
  #kill $FSPID
  rm -f ./output
  rm -f ./somefile
}

testHappyCase() {
  set -e

  # ListObjects fail w/o AUTH Headers
  result=$(curl "$ENDPOINT?delimiter=/&prefix=/" $OPTS)
  assertEquals "401" "$result"
  assertJSONContains "./output" '.error' 'true'
  assertJSONContains "./output" '.message' 'Authentication required'
  assertJSONNotContains "./output" '.objects' '/lib/ACL.xml'

  # Check ListObjects works (Since we serve on the local folder, verify local files)
  test -d ./lib
  test -f ./phpunit.xml
  result=$(curl $WITHAUTH "$ENDPOINT?delimiter=/&prefix=/" $OPTS)
  assertEquals "200" "$result"
  assertJSONContains "./output" '.prefix' '/'
  assertJSONContains "./output" '.delimiter' '/'
  assertJSONContains "./output" '.objects[] | .key' '/phpunit.xml'
  assertJSONContains "./output" '."common-prefixes"[]' '/lib/'
  assertJSONNotContains "./output" '.objects[] | .key' '/lib/ACL.xml'

  echo -n "."

  # prefix searches work for files
  test -f ./phpunit.xml
  result=$(curl $WITHAUTH "$ENDPOINT?&prefix=/phpunit.xml" $OPTS)
  assertEquals "200" "$result"
  assertJSONContains "./output" '.prefix' '/phpunit.xml'
  assertJSONNotContains "./output" '.delimiter' '/'
  assertJSONContains "./output" '.objects[] | .key' '/phpunit.xml'
  assertJSONNotContains "./output" '.objects[] | .key' '/lib/ACL.xml'
  assertJSONNotContains "./output" '."common-prefixes"' '/lib/'
  echo -n "."

  # prefixes work recursive if no delimiter is given
  result=$(curl $WITHAUTH "$ENDPOINT?prefix=/li" $OPTS)
  assertEquals "200" "$result"
  assertJSONContains "./output" '.prefix' '/li'
  assertJSONContains "./output" '.objects[] | .key' '/lib/ACL.php'
  assertJSONNotContains "./output" '.objects[] | .key' '/phpunit.xml'
  assertJSONNotContains "./output" '."common-prefixes"' '/lib/'
  echo -n "."

  # prefixes work non-recursive if delimiter is given
  result=$(curl $WITHAUTH "$ENDPOINT?delimiter=/&prefix=/li" $OPTS)
  assertEquals "200" "$result"
  assertJSONContains "./output" '.prefix' '/li'
  assertJSONContains "./output" '.delimiter' '/'
  assertJSONNotContains "./output" '.objects' '/phpunit.xml'
  assertJSONNotContains "./output" '.objects' '/lib/ACL.php'
  assertJSONContains "./output" '."common-prefixes"[]' '/lib/'
  echo -n "."

  # Create a file
  result=$(curl -XPUT $WITHAUTH "$ENDPOINT/somefile" -d 'content' $OPTS)
  assertEquals "201" "${result}"
  echo -n "."

  # ACL in ListObject should by private, because we haven't given anything when creating
  result=$(curl $WITHAUTH "$ENDPOINT?prefix=/somefile" $OPTS)
  assertEquals "200" "${result}"
  assertJSONContains "./output" '.objects[0].acl' 'private'

  # No auth disallows file access
  result=$(curl "$ENDPOINT/somefile" $OPTS)
  assertEquals "401" "$result"
  echo -n "."

  # No auth disallows file access
  result=$(curl -I "$ENDPOINT/somefile" $OPTS)
  assertEquals "401" "$result"
  echo -n "."

  # Given auth, we can simply GET the file
  result=$(curl $WITHAUTH "$ENDPOINT/somefile" $OPTS)
  assertEquals "200" "$result"
  assertEquals "content" "$(cat ./output)"
  echo -n "."

  # we can check it exists
  result=$(curl -I $WITHAUTH "$ENDPOINT/somefile" $OPTS)
  assertEquals "200" "$result"
  echo -n "."

  # We can change the ACL
  result=$(curl -XPUT $WITHAUTH "$ENDPOINT/somefile?acl" -d 'public-read' $OPTS)
  assertEquals "204" "${result}"
  echo -n "."

  # ACL in ListObject should now read 'public-read'
  result=$(curl $WITHAUTH "$ENDPOINT?prefix=/somefile" $OPTS)
  assertEquals "200" "${result}"
  assertJSONContains "./output" '.objects[0].acl' 'public-read'

  # After making somefile public, we can read it
  result=$(curl "$ENDPOINT/somefile" $OPTS)
  assertEquals "200" "$result"
  assertEquals "content" "$(cat ./output)"
  echo -n "."

  # After making somefile public, we can read it
  result=$(curl -I "$ENDPOINT/somefile" $OPTS)
  assertEquals "200" "$result"
  echo -n "."

  # Finally delete the file
  result=$(curl -XDELETE $WITHAUTH "$ENDPOINT/somefile" $OPTS)
  assertEquals "204" "$result"
  test ! -f ./somefile
  echo -n "."

  # HEAD on the ListObjects route should fail with 405 - because that route doesn't exist
  result=$(curl -I $WITHAUTH "$ENDPOINT/" $OPTS)
  assertEquals "405" "$result"
  echo -n "."

  # HEAD on the ListObjects route should fail with 405 - because that route doesn't exist
  result=$(curl -I "$ENDPOINT/?test=900" $OPTS)
  assertEquals "405" "$result"
  echo -n "."

  # PATCH and other methods should fail with 405 - because that route doesn't exist
  result=$(curl -XPATCH $WITHAUTH "$ENDPOINT/somefile?test=950" $OPTS)
  assertEquals "405" "$result"
  echo -n "."

  # PATCH and other methods should fail with 405 - because that route doesn't exist
  result=$(curl -XPATCH "$ENDPOINT/somefile?test=950" $OPTS)
  assertEquals "405" "$result"
  echo -n "."

  # GET on a nonexisting resource should return 404
  test ! -f ./somefile
  result=$(curl $WITHAUTH "$ENDPOINT/somefile?test=970" $OPTS)
  assertEquals "404" "$result"
  echo -n "."

  # GET on a nonexisting resource should return a 401 for unauthenticated reqs
  test ! -f ./somefile
  result=$(curl "$ENDPOINT/somefile?test=980" $OPTS)
  assertEquals "401" "$result"
  echo -n "."

  echo
}

testCreateWithACL() {
  test ! -f ./somefile
  result=$(curl -XPUT -d 'content' $WITHAUTH $ENDPOINT/somefile?test=1000 -H'X-Acl: public-read' $OPTS)
  assertEquals "201" "$result"
  test -f ./somefile
  echo -n "."

  # ACL in ListObject should now read 'public-read'
  result=$(curl $WITHAUTH "$ENDPOINT?prefix=/somefile" $OPTS)
  assertEquals "200" "${result}"
  assertJSONContains "./output" '.objects[0].key' '/somefile'
  assertJSONContains "./output" '.objects[0].acl' 'public-read'
  echo -n "."

  echo
}

testPrometheusMetrics() {
  result=$(curl $WITHAUTH $ENDPOINT?metrics $OPTS)
  assertEquals "200" "${result}"


  assertOutputContains "^authn_authenticators_count"
  assertOutputContains "^authz_policies_count"
  echo -n "."

  echo
}

setUp
testHappyCase
testCreateWithACL
testPrometheusMetrics
tearDown
echo "ok"
