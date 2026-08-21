#!/bin/bash
set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
exec php "$SCRIPT_DIR/generate.php" "$@"
