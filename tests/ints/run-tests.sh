#!/bin/bash

# NOTE This is a helper script in case `roundup` is not installed.

set -e

describe() {
  echo "# $1"
}

before() {
  true
}

after() {
  true
}

source "$(dirname $0)/v1-api-test.sh"

before

test_files=$(find "$(dirname $0)" -name "*-test.sh")
grep -o '^it_[a-zA-Z0-9_-]*' "${test_files}" | while read fun;
do
  echo -ne "  ${fun}\t"
  (before && eval "${fun}" && after) && echo "ok" || echo "failed"
done