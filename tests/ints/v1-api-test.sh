#!/bin/env roundup


ENDPOINT="${ENDPOINT:-http://localhost:8000/fs.php}"
WRONGAUTH="-u doesnotexists:NorThisPassword"
WITHAUTH="-u test:test"
OPTS="--silent --output ./output -w %{http_code}"

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

assertOutputContains() {
  local text=$1

  grep -q "${text}" ./output || (echo "Expected ./output to contain '${text}'" && exit 1)
}

before() {
  cp tests/ints/fs.config.php .
}


describe "Tests for the fs-php v1 file api"
it_should_get_an_access_denied_without_authentication() {
  # ListObjects fail w/o AUTH Headers
  result=$(curl "$ENDPOINT?delimiter=/&prefix=/" $OPTS)
  test "401" = "${result}"
  assertJSONContains "./output" '.error' 'true'
  assertJSONContains "./output" '.message' 'Authentication required'
  assertJSONNotContains "./output" '.objects' '/lib/mfs/ACL.xml'

}

it_listObjects_works() {
  # Check ListObjects works (Since we serve on the local folder, verify local files)
  
  test -d ./lib
  test -f ./phpunit.xml

  result=$(curl $WITHAUTH "$ENDPOINT?delimiter=/&prefix=/" $OPTS)
  test "200" = "$result"
  assertJSONContains "./output" '.prefix' '/'
  assertJSONContains "./output" '.delimiter' '/'
  assertJSONContains "./output" '.objects[] | .key' '/phpunit.xml'
  assertJSONContains "./output" '."common-prefixes"[]' '/lib/'
  assertJSONNotContains "./output" '.objects[] | .key' '/lib/mfs/ACL.xml'
}


it_should_only_return_the_objects_matching_the_given_prefix() {
  # prefix searches work for files
  test -f ./phpunit.xml
  result=$(curl $WITHAUTH "$ENDPOINT?&prefix=/phpunit.xml" $OPTS)
  test "200" = "$result"
  assertJSONContains "./output" '.prefix' '/phpunit.xml'
  assertJSONNotContains "./output" '.delimiter' '/'
  assertJSONContains "./output" '.objects[] | .key' '/phpunit.xml'
  assertJSONNotContains "./output" '.objects[] | .key' '/lib/mfs/ACL.xml'
  assertJSONNotContains "./output" '."common-prefixes"' '/lib/'
}

it_should_return_all_objects_recursively_by_default() {
  # prefixes work recursive if no delimiter is given
  result=$(curl $WITHAUTH "$ENDPOINT?prefix=/li" $OPTS)
  test "200" = "$result"
  assertJSONContains "./output" '.prefix' '/li'
  assertJSONContains "./output" '.objects[] | .key' '/lib/mfs/ACL.php'
  assertJSONNotContains "./output" '.objects[] | .key' '/phpunit.xml'
  assertJSONNotContains "./output" '."common-prefixes"' '/lib/'
}

it_should_return_all_objects_with_the_given_prefix_but_not_recursively() {
   # prefixes work non-recursive if delimiter is given
  result=$(curl $WITHAUTH "$ENDPOINT?delimiter=/&prefix=/li" $OPTS)
  test "200" = "$result"
  assertJSONContains "./output" '.prefix' '/li'
  assertJSONContains "./output" '.delimiter' '/'
  assertJSONNotContains "./output" '.objects' '/phpunit.xml'
  assertJSONNotContains "./output" '.objects' '/lib/mfs/ACL.php'
  assertJSONContains "./output" '."common-prefixes"[]' '/lib/'
}

it_should_create_a_private_file_on_PUT() {
  # upload a file
  result=$(curl -XPUT $WITHAUTH "$ENDPOINT/somefile" -d 'content' $OPTS)
  test "201" = "${result}"

  # verify ACLs for new file are private by default
  result=$(curl $WITHAUTH "$ENDPOINT?prefix=/somefile" $OPTS)
  test "200" = "${result}"
  assertJSONContains "./output" '.objects[0].acl' 'private'
}

it_should_be_impossible_to_read_a_private_file_as_anonymous() {
  # upload a file
  result=$(curl -XPUT $WITHAUTH "$ENDPOINT/somefile" -d 'content' $OPTS)
  test "201" = "${result}"

  # Anonymous access is denied
  result=$(curl -I "$ENDPOINT/somefile" $OPTS)
  test "401" = "$result"
}

it_should_be_possible_to_read_a_private_file_as_an_authenticated_user() {
  # upload a file
  result=$(curl -XPUT $WITHAUTH "$ENDPOINT/somefile" -d 'content' $OPTS)
  test "201" = "${result}"

  # Authenticated access returns file
  result=$(curl $WITHAUTH "$ENDPOINT/somefile" $OPTS)
  test "200" = "$result"
  test "content" = "$(cat ./output)"
}


it_should_be_possible_to_change_ACLs() {
  # upload a file
  result=$(curl -XPUT $WITHAUTH "$ENDPOINT/somefile" -d 'content' $OPTS)
  test "201" = "${result}"

  # Change ACL
  result=$(curl -XPUT $WITHAUTH "$ENDPOINT/somefile?acl" -d 'public-read' $OPTS)
  test "204" = "${result}"

  # ACL in ListObject should now read 'public-read'
  result=$(curl $WITHAUTH "$ENDPOINT?prefix=/somefile" $OPTS)
  test "200" = "${result}"
  assertJSONContains "./output" '.objects[0].acl' 'public-read'

  # After making somefile public, we can read it
  result=$(curl "$ENDPOINT/somefile" $OPTS)
  test "200" = "$result"
  test "content" = "$(cat ./output)"
}

it_should_be_Possible_to_delete_files() {
  # upload a file
  result=$(curl -XPUT $WITHAUTH "$ENDPOINT/somefile" -d 'content' $OPTS)
  test "201" = "${result}"

  # delete files
  result=$(curl -XDELETE $WITHAUTH "$ENDPOINT/somefile" $OPTS)
  test "204" = "$result"
  test ! -f ./somefile
}


it_should_return_a_405_for_HEAD_on_a_prefix() {
  result=$(curl -I $WITHAUTH "$ENDPOINT/" $OPTS)
  test "405" = "$result"

  # anonymous
  result=$(curl -I "$ENDPOINT/" $OPTS)
  test "405" = "$result"
}

it_should_return_a_405_for_PATCH_requests_on_a_prefix() {
  result=$(curl -XPATCH $WITHAUTH "$ENDPOINT/somefile" $OPTS)
  test "405" = "$result"

  result=$(curl -XPATCH "$ENDPOINT/somefile" $OPTS)
  test "405" = "$result"
}

it_should_reutrn_a_404_for_non_existing_resources() {
  # GET on a nonexisting resource should return 404
  test ! -f ./somefile
  result=$(curl $WITHAUTH "$ENDPOINT/somefile?test=970" $OPTS)
  test "404" = "$result"
}

it_should_return_a_401_for_non_existing_resources_for_anonymous_access() {
  # GET on a nonexisting resource should return a 401 for unauthenticated reqs
  test ! -f ./somefile
  result=$(curl "$ENDPOINT/somefile?test=980" $OPTS)
  test "401" = "$result"
}


it_should_be_Possible_to_create_files_with_an_ACL_directly() {
  # Create file by passing in ACL header
  test ! -f ./somefile
  result=$(curl -XPUT -d 'content' $WITHAUTH $ENDPOINT/somefile -H'X-Acl: public-read' $OPTS)
  test "201" = "$result"
  test -f ./somefile

  # ACL in ListObject should now read 'public-read'
  result=$(curl $WITHAUTH "$ENDPOINT?prefix=/somefile" $OPTS)
  test "200" = "${result}"
  assertJSONContains "./output" '.objects[0].key' '/somefile'
  assertJSONContains "./output" '.objects[0].acl' 'public-read'
}

it_should_be_possible_to_get_prometheus_stats() {
  result=$(curl $WITHAUTH $ENDPOINT?metrics $OPTS)
  test "200" = "${result}"


  assertOutputContains "^authn_authenticators_count"
  assertOutputContains "^authz_policies_count"
}