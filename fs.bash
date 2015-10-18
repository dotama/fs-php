#!/bin/bash

function fscurl() {
  local url=$1
  shift

  curl -sS -u${credentials} "${basecurl}${url}" $*
}

source $HOME/.fscfg

# cli
basecurl="${url}"
action=$1
path=$2
shift 2

if [ -z "$path" ]; then
  path="/"
fi

case $action in
get)
  fscurl "${path}.ignore"
  ;;
ls)
  fscurl "/?prefix=${path}&delimiter=/" | jq .
  ;;
push)
  file=$1

  acl="private"
  if [ ! -z "$2" ]; then
     acl="$2"
  fi
  fscurl "${path}.ignore" -XPUT --data-binary @$file -H"X-ACL: $acl" -H'Content-Type: application/octet-stream'
  ;;
set)
  content=$1

  acl="private"
  if [ ! -z "$2" ]; then
     acl="$2"
  fi
  fscurl "${path}.ignore" -XPUT -H'Content-Type: text/plain' -H"X-ACL: $acl" --data-ascii "${content}"
  ;;
delete)
  fscurl "${path}.ignore" -XDELETE
  ;;
url)
  echo ${basecurl}${path}
  ;;
set-acl)
  acl=$1
  fscurl "${path}.ignore?acl" -XPUT --data-ascii ${acl}
  ;;
head)
  set -e
  fscurl "${path}.ignore" -If --ignore-content-length >/dev/null 2>&1
  ;;
*)
  self=$(basename $0)
  echo "$self ls [prefix]"
  echo "$self push KEY FILEPATH [ACL]"
  echo "$self set KEY CONTENT [ACL]"
  echo "$self get KEY"
  echo "$self delete KEY"
  echo "$self url KEY"
  echo "$self set-acl KEY ACL"
  exit 1
esac
