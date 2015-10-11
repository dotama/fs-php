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

  ${basecurl}${path} -XPUT -d@$file -H'Content-Type: application/octet-stream'
  ;;
set)
  content=$1
  ${basecurl}${path} -XPUT -d "$content" -H'Content-Type: text/plain'
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
  echo "$self get KEY"
  echo "$self delete KEY"
  echo "$self push KEY FILEPATH"
  echo "$self set KEY CONTENT"
  echo "$self url KEY"
  exit 1
esac
