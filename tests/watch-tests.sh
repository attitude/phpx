#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
PEST="$PROJECT_ROOT/vendor/bin/pest"

cd "$PROJECT_ROOT"

if [[ ! -f "$PEST" ]]; then
    echo "Error: Pest binary not found at $PEST" >&2
    echo "Run: composer install" >&2
    exit 1
fi

if [[ ! -x "$PEST" ]]; then
    echo "Error: Pest binary is not executable: $PEST" >&2
    exit 1
fi

if ! command -v fswatch &>/dev/null; then
    echo "Error: fswatch is not installed or not on PATH" >&2
    echo "Install it with: brew install fswatch" >&2
    exit 1
fi

# Runs pest, swallowing exit code 1 (test failures are expected) but
# propagating any other non-zero code as a real error so the script fails fast.
run_pest() {
    local exit_code=0
    "$PEST" "$@" || exit_code=$?
    if [[ $exit_code -ne 0 && $exit_code -ne 1 ]]; then
        echo "Error: Pest exited with code $exit_code — check for PHP errors or configuration issues" >&2
        return $exit_code
    fi
    return 0
}

echo "Running initial test suite..."
run_pest --parallel

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
          run_pest "$relative_file"
      elif [[ "$relative_file" == src/* ]]; then
          echo "Source changed: $relative_file"
          run_pest --parallel
      fi
  done
