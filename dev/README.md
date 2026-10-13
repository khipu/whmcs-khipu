# Local WHMCS for the Khipu module

Runs WHMCS 9.0 and 8.13 LTS in Docker with the module mounted from this repository, to
try checkout, notifications and refunds without touching a real install.

You need **Docker**, the WHMCS release zips, and a **WHMCS license key** (you type it into
the WHMCS installer; it is never stored in this repository).

| WHMCS | PHP | URL |
|---|---|---|
| 9.0.x | 8.3 | <http://localhost:8090> |
| 8.13.x | 8.2 | <http://localhost:8091> |

The PHP versions follow each release's ionCube build: 8.13 is encoded for PHP ≤ 8.2.

## 1. Set up and start

```bash
cd dev
./setup.sh ~/Downloads/whmcs_v909_full.zip      # unpacks into dev/whmcs/9.0.9 (git-ignored)
./up.sh 9.0.9                                    # builds PHP + ionCube, starts WHMCS and MySQL
```

The first build takes a few minutes. Then open
<http://localhost:8090/install/install.php> and install:

- license key: yours
- database host `db`, name `whmcs`, user `whmcs`, password `whmcs`
- any admin user and password

WHMCS refuses to run while `install/` exists. When the installer finishes:

```bash
rm -rf whmcs/9.0.9/install
```

Same for 8.13 with `./setup.sh ~/Downloads/whmcs_v8137_full.zip` and `./up.sh 8.13.7`.
Both can run at the same time.

## 2. Activate the module

In the admin area, **Configuration → Apps & Integrations** → search **Khipu** (or Browse → Payments → *Additional apps*) → activate it, then fill **API Key**,
**ID Cobrador** and **Llave secreta** of a Khipu test account, and save.

> Use a test account. A payment made here is a real payment.

The module files are bind-mounted one by one (the callbacks find `init.php` through
`__DIR__/../../..`, so they must sit inside the WHMCS tree). An editor that saves by
replacing the file can leave the container on the old copy; if a change does not show,
`./up.sh 9.0.9` again or `docker compose -p whmcs-909 restart whmcs`.

## 3. Try a payment

**A tunnel is required.** Khipu refuses to create a payment whose `notify_url` points at
localhost.

```bash
./tunnel.sh 9.0.9            # public https URL, set as WHMCS's System URL
./tunnel.sh 9.0.9 status
./tunnel.sh 9.0.9 stop       # back to http://localhost:8090/
```

Use the printed URL in the browser: WHMCS redirects to its System URL. Create a client
and an invoice with Khipu as the payment method, open it as the client, pay, and come
back. Khipu notifies the public webhook and the invoice turns **Paid**.

> While the tunnel is up, WHMCS (admin included) is reachable by anyone with the URL.
> Stop it when done.

Check what was recorded:

```bash
./last-payments.sh 9.0.9     # tblaccounts and tblgatewaylog for khipu
```

### Re-send a notification

```bash
KHIPU_SECRET=<secret> KHIPU_RECEIVER_ID=<receiver id> ./notify.sh 9.0.9 <payment_id> <invoice id>
```

Signed exactly as Khipu signs. The webhook asks Khipu for the payment before recording
anything, so only a real `payment_id` of that account is recorded; a second send must
answer `Already recorded`. Use it for the refusals too: another secret (400 *Invalid
signature*), another receiver id (400 *Wrong receiver*), an invented `payment_id`
(400 after Khipu's 404).

## 4. Try a refund

Needs a real payment from an account with the refund wallet enabled. As admin, open the
paid invoice → **Refund** tab → pick the Khipu transaction → Refund Type **Refund through Gateway**.
`./last-payments.sh` shows the refund row (`refundid` pointing at the payment) and the
gateway log entry with `resultado`. The wallet balance shows in Billing > Transactions List.

## 5. Integration tests

`tests-integration/` runs inside the dev WHMCS against the real Khipu API, with the
account configured in step 2. **Use a demo (DemoBank) account**: some tests refund small
amounts.

```bash
./integration-test.sh 9.0.9                          # the suite (starts and stops the tunnel)
./integration-test.sh 9.0.9 -- --filter WebhookTest  # extra PHPUnit arguments
```

What runs without anyone paying: the pay button against real payments (amounts, CLP
rounding, signed `custom`, currency conversion, tampered and expired forms), the webhook
against real payments (unpaid, already recorded, the 2.x notification through the v2
API, the lock, refusals), and refunds Khipu turns down (fully refunded, unknown payment,
bad API Key), the refund lock and the wallet balance. Unpaid payments it creates expire
on their own.

Refunds that succeed need a paid payment. Seed one, once:

```bash
./integration-test.sh 9.0.9 seed
```

It prints two payment links to pay in the demo bank and waits up to 20 minutes:

1. a normal payment of $1.001 for a 1,000.50 invoice: the webhook must record 1,000.50;
2. a payment whose `custom` was forged without the Llave secreta: the webhook must refuse
   it and leave its invoice unpaid.

After that, every run refunds about $4 of the first one, so it lasts a few hundred runs.

If WHMCS lacks a USD or a CLP currency, the suite adds it (1 USD = 950 CLP).

The runner refuses to start if WHMCS is not verifying its license: WHMCS's own API then
rejects calls the pay button needs, and those failures would look like module bugs.

## 6. Stop

```bash
./up.sh 9.0.9 down     # keeps the database
./up.sh 9.0.9 reset    # deletes the database (dev/whmcs/9.0.9 stays: delete it to start over)
```

## Troubleshooting

| Symptom | Cause |
|---|---|
| Blank page or "Down for maintenance" | `install/` still exists: delete it |
| License invalid | The license is bound to a domain, IP and directory. The image pins Apache's `SERVER_NAME` to `localhost`, so a license valid for `localhost` also works through the tunnel; any other binding has to be fixed in the WHMCS client area |
| "No pudimos iniciar el pago con Khipu" | No tunnel (Khipu rejects a localhost `notify_url`), or wrong API Key. The gateway log has Khipu's reason |
| Changes to a module file do not show | See §2: restart the container |
| ionCube error on start | The loader must match the PHP minor; `up.sh` picks it from the WHMCS version |
| `integration-test.sh` says the license is not verified | Open the admin area: WHMCS shows its license error page and why. Fix it there or in the WHMCS client area, then run again |

This directory is development only: `package.sh` does not ship it.
