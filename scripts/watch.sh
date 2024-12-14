#!/bin/bash

# Use fswatch to monitor *.phpx files in the current directory
fswatch -e ".*" -i "\\.phpx$" . | while read -r event; do
  echo "Updating: $event"
  php scripts/compile.php "$event"
done
