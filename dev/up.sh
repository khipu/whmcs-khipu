#!/usr/bin/env bash
#
# Starts (or rebuilds) the local WHMCS of a given version.
#
#   ./up.sh 9.0.9
#   ./up.sh 8.13.7
#   ./up.sh 9.0.9 down      stop it, keeping the database
#   ./up.sh 9.0.9 reset     stop it and delete its database

set -euo pipefail

source "$(dirname "$0")/env.sh"
whmcs_env "${1:-}"

case "${2:-up}" in
    down)  dc down; exit 0 ;;
    reset) dc down -v; exit 0 ;;
esac

dc up -d --build

echo
echo "WHMCS $WHMCS_VERSION on PHP $PHP_VERSION: http://localhost:$WHMCS_PORT/"
if [ -d "$DEV_DIR/whmcs/$WHMCS_VERSION/install" ]; then
    echo "Not installed yet: http://localhost:$WHMCS_PORT/install/install.php"
    echo "  Database host: db   name: whmcs   user: whmcs   password: whmcs"
    echo "  Afterwards delete dev/whmcs/$WHMCS_VERSION/install (WHMCS refuses to run while it exists)."
fi
