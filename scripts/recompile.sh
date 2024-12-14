#!/bin/bash

# Find all files with the .phpx extension recursively in the current directory
find . -type f -name "*.phpx" | while read -r file; do
	echo "Updating: $file"
	# Call php compile.php with the path to each .phpx file
	php scripts/compile.php "$file"
done
