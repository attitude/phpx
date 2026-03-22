#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
PEST="$PROJECT_ROOT/vendor/bin/pest"

cd "$PROJECT_ROOT"

echo "Running initial test suite..."
"$PEST" --parallel || true

echo ""
echo "Watching tests/ and src/ for changes..."

fswatch -e '.*' -i '\.php$' \
    "$PROJECT_ROOT/tests" \
    "$PROJECT_ROOT/src" \
  | while IFS= read -r changed_file; do
      relative_file="${changed_file#"$PROJECT_ROOT/"}"

      echo ""
      if [[ "$relative_file" == tests/* ]]; then
          echo "Test changed: $relative_file"
          "$PEST" "$relative_file" || true
      elif [[ "$relative_file" == src/* ]]; then
          echo "Source changed: $relative_file"
          "$PEST" --parallel || true
      fi
  done
