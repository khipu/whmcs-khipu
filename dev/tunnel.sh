#!/usr/bin/env bash
#
# Exposes the local WHMCS on a public https URL and sets it as WHMCS's
# System URL. Needed for a real payment: Khipu refuses to create one whose
# notify_url points at localhost, and notifications only arrive this way.
#
#   ./tunnel.sh 9.0.9           start it
#   ./tunnel.sh 9.0.9 status    show the current URL
#   ./tunnel.sh 9.0.9 stop      stop it and go back to localhost
#
# While it runs, WHMCS (admin area included) is reachable by anyone with the
# URL. Use a strong admin password and stop it when you are done.

set -euo pipefail

source "$(dirname "$0")/env.sh"
whmcs_env "${1:-}"

current_url() {
    dc --profile tunnel logs tunnel 2>/dev/null | grep -oE 'https://[a-z0-9-]+\.trycloudflare\.com' | tail -1 || true
}

set_system_url() {
    db_query "UPDATE tblconfiguration SET value='$1' WHERE setting='SystemURL';"
}

case "${2:-start}" in
    status)
        url=$(current_url)
        echo "Tunnel     : ${url:-(none)}"
        echo "System URL : $(db_query "SELECT value FROM tblconfiguration WHERE setting='SystemURL'")"
        exit 0
        ;;
    stop)
        dc --profile tunnel stop tunnel >/dev/null 2>&1 || true
        dc --profile tunnel rm -f tunnel >/dev/null 2>&1 || true
        set_system_url "http://localhost:$WHMCS_PORT/"
        echo "Tunnel down. System URL back to http://localhost:$WHMCS_PORT/"
        exit 0
        ;;
esac

dc --profile tunnel up -d tunnel >/dev/null

url=""
for _ in $(seq 1 60); do
    url=$(current_url)
    [ -n "$url" ] && break
    sleep 2
done

if [ -z "$url" ]; then
    echo "No tunnel URL yet. Check: docker compose -p $PROJECT logs tunnel" >&2
    exit 1
fi

set_system_url "$url/"

echo "WHMCS      : $url/"
echo "Admin      : $url/admin/"
echo "Webhook    : $url/modules/gateways/callback/khipu.php"
echo
echo "Use that URL in the browser: WHMCS redirects to its System URL."
echo "When done: ./tunnel.sh $WHMCS_VERSION stop"
