#!/bin/sh
set -eu

find wp-plugin tests mcp-server -name '*.php' -type f -print | sort | while IFS= read -r file; do
  php -l "$file" >/dev/null
done
for test in tests/php-*.php; do
  php "$test"
done
