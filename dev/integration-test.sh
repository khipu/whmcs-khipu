#!/usr/bin/env bash
#
# Runs the integration suite (tests-integration/) inside the dev WHMCS,
# against the real Khipu API with the account configured there. Use a demo
# (DemoBank) account: some tests refund small amounts of a seeded payment.
#
#   ./integration-test.sh 9.0.9            run the suite
#   ./integration-test.sh 9.0.9 seed       once: two payments to pay by hand
#   ./integration-test.sh 9.0.9 -- --filter WebhookTest    extra PHPUnit args
#
# Khipu only creates payments for a public https notify_url, so the tunnel is
# started for the run (and stopped after, if this script started it).

set -euo pipefail

source "$(dirname "$0")/env.sh"
whmcs_env "${1:-}"
shift
mode="test"
if [ "${1:-}" = "seed" ]; then
    mode="seed"
    shift
fi
[ "${1:-}" = "--" ] && shift

if [ "$(db_query "SELECT COUNT(*) FROM tblpaymentgateways WHERE gateway='khipu' AND setting='api_key' AND value <> ''")" != "1" ]; then
    echo "Activate Khipu in the dev WHMCS and fill its credentials first (dev/README.md, step 2)." >&2
    exit 1
fi

# Without a verified license WHMCS's own API refuses calls the pay button
# needs, and those failures would read as module bugs.
if curl -s -o /dev/null -w '%{redirect_url}' "http://localhost:$WHMCS_PORT/admin/login.php" | grep -q licenseerror; then
    echo "WHMCS $WHMCS_VERSION is not verifying its license (see http://localhost:$WHMCS_PORT/admin/). Fix that first." >&2
    exit 1
fi

system_url() {
    db_query "SELECT value FROM tblconfiguration WHERE setting='SystemURL'"
}

webhook_status() {
    curl -s -o /dev/null -m 10 -w '%{http_code}' "${1}modules/gateways/callback/khipu.php"
}

# An https System URL is not proof of a live tunnel: one left behind by a
# stopped stack points nowhere. Only a webhook that answers counts.
url="$(system_url)"
if [[ "$url" != https://* ]] || [ "$(webhook_status "$url")" != "405" ]; then
    # Before starting it: a start that fails halfway must not leave WHMCS public.
    trap '"$DEV_DIR/tunnel.sh" "$WHMCS_VERSION" stop >/dev/null' EXIT
    "$DEV_DIR/tunnel.sh" "$WHMCS_VERSION" >/dev/null
fi

url="$(system_url)"
ready=0
for _ in $(seq 1 30); do
    if [ "$(webhook_status "$url")" = "405" ]; then
        ready=1
        break
    fi
    sleep 3
done
if [ "$ready" != "1" ]; then
    echo "The webhook at ${url} does not answer: Khipu could not reach it either. Check ./tunnel.sh $WHMCS_VERSION status." >&2
    exit 1
fi
echo "WHMCS $WHMCS_VERSION at $url"

if [ "$mode" = "seed" ]; then
    dc exec -T -w /var/www/html whmcs php /opt/khipu-module/tests-integration/seed.php
else
    # Absolute config path: the bootstrap chdirs into the WHMCS root, which init.php needs.
    dc exec -T -w /opt/khipu-module whmcs php vendor/bin/phpunit -c /opt/khipu-module/phpunit.integration.xml "$@"
fi
