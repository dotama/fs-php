#!/bin/bash

source $HOME/.fscfg

# jq installed?
if [ "$(command -v jq)" = "" ]; then
  jq() {
    cat -
  }
fi

# cli
basecurl="curl -sS -u${credentials} ${url}"
action=$1
path=$2
shift 2

if [ -z "$path" ]; then
  path="/"
fi

case $action in
get)
  ${basecurl}${path}.ignore
  ;;
ls)
  ${basecurl}/?prefix=${path} | jq .
  ;;
push)
  file=$1

  acl="private"
  if [ ! -z "$2" ]; then
     acl="$2"
  fi
  ${basecurl}${path}.ignore -XPUT --data-binary @$file -H"X-ACL: $acl" -H'Content-Type: application/octet-stream'
  ;;
set)
  content=$1

  acl="private"
  if [ ! -z "$2" ]; then
     acl="$2"
  fi
  ${basecurl}${path}.ignore -XPUT -H'Content-Type: text/plain' -H"X-ACL: $acl" --data-ascii "${content}"
  ;;
delete)
  ${basecurl}${path}.ignore -XDELETE
  ;;
url)
  echo ${url}${path}
  ;;
set-acl)
  acl=$1
  ${basecurl}${path}.ignore?acl -XPUT --data-ascii ${acl}
  ;;
head)
  exec ${basecurl}${path}.ignore -s --ignore-content-length -I -f >/dev/null
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
