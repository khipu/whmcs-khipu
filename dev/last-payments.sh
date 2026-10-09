#!/usr/bin/env bash
#
# Last Khipu transactions and gateway log entries of the local WHMCS.
#
#   ./last-payments.sh 9.0.9

set -euo pipefail

source "$(dirname "$0")/env.sh"
whmcs_env "${1:-}"

echo "== Transactions (tblaccounts)"
echo "id | invoice | date | in | out | transid | refund of"
db_query "SELECT id, invoiceid, date, amountin, amountout, transid, refundid
          FROM tblaccounts WHERE gateway='khipu' ORDER BY id DESC LIMIT 10;" | tr '\t' '|'

echo
echo "== Gateway log (tblgatewaylog)"
db_query "SELECT date, result, REPLACE(LEFT(data, 300), '\n', ' ')
          FROM tblgatewaylog WHERE gateway LIKE '%hipu%' ORDER BY id DESC LIMIT 10;" | tr '\t' '|'
