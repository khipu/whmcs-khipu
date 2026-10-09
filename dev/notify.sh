#!/usr/bin/env bash
#
# Sends a signed API 3.0 notification to the local webhook, signed the way
# Khipu signs it: base64(HMAC-SHA256("<t>.<body>", secret)).
#
#   KHIPU_SECRET=<secret> KHIPU_RECEIVER_ID=<receiver id> \
#     ./notify.sh 9.0.9 <payment_id> <invoice id>
#
# The webhook asks Khipu for the payment before recording anything, so only a
# real payment_id of that account gets recorded; anything else tests the
# refusals (wrong signature, wrong receiver, unknown payment). Re-sending a
# recorded payment must answer "Already recorded".

set -euo pipefail

source "$(dirname "$0")/env.sh"
whmcs_env "${1:-}"

payment_id="${2:-}"
invoice_id="${3:-}"
secret="${KHIPU_SECRET:-}"
receiver="${KHIPU_RECEIVER_ID:-}"

if [ -z "$payment_id" ] || [ -z "$invoice_id" ] || [ -z "$secret" ] || [ -z "$receiver" ]; then
    echo "Usage: KHIPU_SECRET=<secret> KHIPU_RECEIVER_ID=<id> $0 <version> <payment_id> <invoice id>" >&2
    exit 2
fi

url="${KHIPU_NOTIFY_URL:-http://localhost:$WHMCS_PORT/modules/gateways/callback/khipu.php}"
body=$(printf '{"payment_id":"%s","receiver_id":%s,"transaction_id":"%s"}' "$payment_id" "$receiver" "$invoice_id")
t=$(($(date +%s) * 1000))
signature=$(printf '%s' "${t}.${body}" | openssl dgst -sha256 -hmac "$secret" -binary | base64)

echo "POST $url"
echo "  $body"
echo
curl -s -i -X POST "$url" \
    -H 'Content-Type: application/json' \
    -H "X-Khipu-Signature: t=${t},s=${signature}" \
    --data-binary "$body"
echo
