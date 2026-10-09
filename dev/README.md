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

## 5. Stop

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

This directory is development only: `package.sh` does not ship it.
