#!/bin/bash

source $HOME/.fscfg

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

  acl="-H'X-ACL: private'"
  if [ ! -z "$2" ]; then
     acl="X-ACL: $2"
  fi
  ${basecurl}${path}.ignore -XPUT --data-binary @$file -H"$acl" -H'Content-Type: application/octet-stream' 
  ;;
set)
  content=$1

  acl="-H'X-ACL: private'"
  if [ ! -z "$2" ]; then
     acl="X-ACL: $2"
  fi
  ${basecurl}${path}.ignore -XPUT -H'Content-Type: text/plain' -H"$acl" --data-ascii "${content}"
  ;;
delete)
  ${basecurl}${path}.ignore -XDELETE
  ;;
url)
  echo ${url}${path}
  ;;
*)
  self=$(basename $0)
  echo "$self ls [prefix]"
  echo "$self push KEY FILEPATH [ACL]"
  echo "$self set KEY CONTENT [ACL]"
  echo "$self get KEY"
  echo "$self delete KEY"
  echo "$self url KEY"
  exit 1
esac
