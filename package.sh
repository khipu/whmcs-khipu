#!/usr/bin/env bash
#
# Builds dist/whmcs-khipu.zip, the file merchants install.
#
# The zip mirrors the WHMCS tree (modules/gateways/...), so it is extracted at
# the WHMCS root. Only runtime files go in: no tests, docs, dev tools or
# vendor/ (that is PHPUnit, and 2.x's shared modules/gateways/vendor/ is
# exactly what this layout stops doing).

set -euo pipefail

cd "$(dirname "$0")"

stage=$(mktemp -d)
trap 'rm -rf "$stage"' EXIT

gateways="$stage/modules/gateways"
mkdir -p "$gateways/callback" "$gateways/khipu" dist

cp khipu.php "$gateways/"
cp callback/khipu.php callback/khipu_redirect.php "$gateways/callback/"
cp khipu/*.php "$gateways/khipu/"

rm -f dist/whmcs-khipu.zip
(cd "$stage" && zip -qr - modules) > dist/whmcs-khipu.zip

echo "Built: dist/whmcs-khipu.zip"
unzip -l dist/whmcs-khipu.zip
