#!/bin/sh

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
  ${basecurl}${path}
  ;;
ls)
  ${basecurl}/?prefix=${path} | jq .
  ;;
push)
  file=$1

  acl=
  if [ ! -z "$2" ]; then
     acl="X-ACL: $2"
  fi
  ${basecurl}${path} -XPUT -d@$file -H'Content-Type: application/octet-stream' -H"$acl"
  ;;
set)
  content=$1

  acl=
  if [ ! -z "$2" ]; then
     acl="X-ACL: $2"
  fi
  ${basecurl}${path} -XPUT -H'Content-Type: text/plain' -H"$acl" -d "${content}"
  ;;
delete)
  ${basecurl}${path} -XDELETE
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
