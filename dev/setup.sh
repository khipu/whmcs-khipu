#!/usr/bin/env bash
#
# Unpacks a WHMCS release into dev/whmcs/<version>, ready for ./up.sh.
#
#   ./setup.sh ~/Downloads/whmcs_v909_full.zip
#   ./setup.sh ~/Downloads/whmcs_v8137_full.zip
#
# The license key is never stored here: you type it into the WHMCS installer.

set -euo pipefail

zip="${1:-}"
if [ ! -f "$zip" ]; then
    echo "Usage: $0 <whmcs_vXXX_full.zip>" >&2
    exit 2
fi
zip="$(cd "$(dirname "$zip")" && pwd)/$(basename "$zip")"

cd "$(dirname "$0")"

version=$(unzip -p "$zip" README.txt | sed -n 's/^Release Version: \([0-9.]*\).*/\1/p' | head -1)
if [ -z "$version" ]; then
    echo "Could not read the release version from $zip" >&2
    exit 1
fi

target="whmcs/$version"
source ./env.sh
if [ -e "$target" ]; then
    echo "$target already exists. Delete it first to start over (it holds configuration.php)." >&2
    exit 1
fi

tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
unzip -q "$zip" -d "$tmp"
mkdir -p whmcs
mv "$tmp/whmcs" "$target"

# What the WHMCS install guide asks for; the installer fills it in.
cp "$target/configuration.sample.php" "$target/configuration.php"

link_module "$target"

echo "WHMCS $version unpacked in dev/$target"
echo "Next: ./up.sh $version"
