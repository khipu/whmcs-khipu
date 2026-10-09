# Technical manual: Khipu module for WHMCS

How the module plugs into WHMCS, what it calls on the Khipu API, what it stores, and how
to develop, test and package it. To install and operate it, see
[manual-comercio.md](manual-comercio.md) (Spanish, merchant-facing).

- **Module:** `khipu` (third-party gateway)
- **Version:** 3.0.0 (single source: `khipu/Version.php`)
- **Khipu API:** v3 (`https://payment-api.khipu.com/v3`), plus one v2 call kept for the upgrade window
- **Supported:** WHMCS 8.13 LTS (ionCube build for PHP 7.2, 8.1, 8.2) and 9.0 (PHP 8.2, 8.3). The library code is
  PHP 7.2+ compatible and tested on 7.2, 8.3 and 8.4.

---

## 1. Layout

```
khipu.php                       WHMCS module: MetaData, config, link, refund, account_balance
callback/khipu_redirect.php     Pay-button target: creates the Khipu payment, redirects the payer
callback/khipu.php              Webhook: Khipu payment notification (public URL)

khipu/                          Library, no WHMCS dependencies (namespace KhipuWhmcs)
  autoload.php                  Explicit require list (no Composer at runtime)
  Version.php                   Plugin/API version, User-Agent
  Http.php                      cURL transport: timeouts, TLS verification, https only
  Client.php                    v3 endpoints; turns responses into ok/http/data/errors/outcome
  LegacyClient.php              v2 payment lookup by notification token (2.x payments only)
  PayLink.php                   HMAC of the pay form (invoice id + expiry)
  PaymentCustom.php             The payment's `custom` field: invoice amount and currency
  Notification.php              Webhook signature, payload shape, record/retry/reject decision
  RefundRules.php               Refund amount mapping, Khipu error grammar, outcome rules
  RefundProcessor.php           The refund flow, answering in WHMCS's _refund shape
  Gateway.php                   Small helpers shared by the WHMCS entry points

tests/                          PHPUnit 8.5 on the library (no WHMCS, no network)
dev/                            Local WHMCS 8.13 / 9.0 in Docker
package.sh                      Builds dist/whmcs-khipu.zip
```

On a WHMCS install everything lives under `modules/gateways/`: `khipu.php`, the `khipu/`
library directory, and the two callbacks in `modules/gateways/callback/`.

The split is deliberate. Everything that decides something (signatures, amounts, what a
Khipu response means) is in `khipu/` and unit tested with an injected HTTP transport. The
three WHMCS entry points only read WHMCS state, call the library, and write WHMCS state.

### WHMCS functions implemented

| Function | WHMCS calls it | What it does |
|---|---|---|
| `khipu_MetaData` | Module load | Display name, API version 1.1, no local card input |
| `khipu_config` | Gateway settings page | `api_key`, `receiver_id`, `secret` (2.x key names kept; `secret` is now a `password` field, and the plaintext value 2.x stored still reads back intact, verified on 9.0.9) |
| `khipu_link` | Every invoice view, order completion | Renders the signed pay form. No network call |
| `khipu_refund` | Admin refund with *Refund through Gateway* | `RefundProcessor` |
| `khipu_account_balance` | Billing > Transactions List, *Gateway Balances* (WHMCS 8.2+) | Refund wallet balance, or nothing |

---

## 2. Payment flow

```
Invoice page              khipu_redirect.php                 Khipu                callback/khipu.php
     │ POST invoice_id,exp,sig │                                │                        │
     │────────────────────────►│ PayLink::decode                │                        │
     │                         │ GetInvoice: Unpaid, gateway=khipu                       │
     │                         │ getGatewayVariables(khipu, id) │                        │
     │                         │ POST /v3/payments ────────────►│                        │
     │◄──── 303 payment_url ───│◄───────── payment_url ─────────│                        │
     │ pays at the bank ───────────────────────────────────────►│                        │
     │                                                          │ POST signed JSON ─────►│
     │                                                          │◄── GET /v3/payments/{id}
     │                                                          │                        │ addInvoicePayment
     │◄──── return_url: viewinvoice.php?id=…&paymentsuccess=true ────────────────────────│
```

### 2.1 Pay button: `khipu_link()`

Renders a form posting `invoice_id`, `exp` and `sig` to `callback/khipu_redirect.php`.
`sig = HMAC-SHA256(invoice_id|exp)` with a key derived from the merchant secret
(`HMAC(secret, "whmcs-khipu-pay-link-v1")`), so it can never be mistaken for a Khipu
signature. The form is valid for `PayLink::TTL` (2 h); an expired but authentic one sends
the payer back to the invoice, which renders a fresh form.

Version 2.x posted amount, currency, `return_url` and `notify_url` as plain hidden fields
and created the payment with whatever arrived: anyone could create a Khipu payment for any
invoice with any amount or callback URL. The signed form closes that, and also stops
invoice-id enumeration from leaking invoice amounts through the Khipu payment page.

If any credential is missing, the button is replaced by a "not available" message.

A 2.x form still open in a browser after the upgrade posts its old fields; its
`transaction_id` (the invoice id) sends the payer back to the invoice, which renders the
new form.

### 2.2 Payment creation: `callback/khipu_redirect.php`

1. POST only; `PayLink::decode()`.
2. `localAPI('GetInvoice')`: the invoice must exist, be `Unpaid` and still use `khipu`.
   Otherwise the payer goes back to the invoice.
3. `getGatewayVariables('khipu', $invoiceId)`: the same parameters WHMCS passes to
   `khipu_link()`, so the amount is the **current balance**, already converted when
   *Convert To For Processing* is set. Nothing sent to Khipu comes from the form.
4. `POST /v3/payments`:

   | Field | Value |
   |---|---|
   | `subject` | WHMCS invoice description, truncated to 255 |
   | `currency` | `$params['currency']` (after conversion) |
   | `amount` | `$params['amount']` rounded with `Client::amountPrecision()`: 0 decimals for CLP, 2 otherwise |
   | `transaction_id` | Invoice id |
   | `custom` | `PaymentCustom::build()`: invoice id, the amount in the invoice's own currency (`basecurrencyamount` / `basecurrency` when converting), the amount and currency Khipu is asked to charge, and `sig`, an HMAC over all of them with a key derived from the merchant secret |
   | `return_url` | `viewinvoice.php?id=…&paymentsuccess=true` |
   | `cancel_url` | `viewinvoice.php?id=…&paymentfailed=true` |
   | `notify_url` | `{System URL}modules/gateways/callback/khipu.php` |
   | `notify_api_version` | `3.0` |
   | `payer_email` | Client email |

5. On success, a 303 to `payment_url` (only if it is `https://`). On failure, Khipu's
   reason goes to the gateway log (it is about the merchant's setup) and the payer sees a
   generic page with a link back to the invoice.

Timeouts: 5 s connect, 15 s total (`Client::CHECKOUT_TIMEOUTS`).

### 2.3 Notification: `callback/khipu.php`

Khipu retries anything other than a 200 for up to two days, so status codes are chosen
on purpose: **200** done or nothing to do, **4xx** refused, **503** try again later.

API 3.0 (JSON body, `X-Khipu-Signature: t=<ms>,s=<base64>`):

1. Module inactive → 503 (a module switched off for a moment must not lose payments).
2. `Notification::verifySignature()`: `base64(HMAC-SHA256("<t>.<raw body>", secret))`
   compared with `hash_equals`. Missing, partial or junk headers and an empty secret all
   fail closed with 400, never with a PHP error. Logged, because a wrong *Llave secreta*
   fails every real notification here.
3. `Notification::parse()`: `payment_id`, `receiver_id`, `transaction_id` must be
   non-empty scalars. `receiver_id` must equal the configured one.
4. MySQL `GET_LOCK` on the payment id (10 s; 503 if busy). Two simultaneous
   notifications would otherwise both see the payment unrecorded and add it twice.
5. Already in `tblaccounts` (gateway `khipu`, same `transid`) → 200.
6. `GET /v3/payments/{payment_id}`. Transport error or 5xx → 503; 4xx → 400.
   **The amount recorded comes from this call, not from the notification**, so a
   replayed or forged notification can never record money Khipu did not receive.
7. `Notification::evaluate()`:

   | Payment as Khipu reports it | Action |
   |---|---|
   | Other `receiver_id`, or `payment_id`/`transaction_id` not matching the notification | 400 |
   | `status` not `done` | 503: the notification can beat the payment's own state change |
   | `status_detail` = `marked-paid-by-receiver` | 200, nothing recorded (no transfer happened) |
   | `status_detail` = `reversed`, `fully-refunded`, `partially-refunded`, or present and empty (also read as "has refunds") | Recorded, with an `ATENCIÓN` warning in the log: the payment was voided or refunded from the Khipu portal while its notification kept failing. Marked for refund is not paid out (an unfunded refund expires), and a 200 without recording would stop Khipu's retries for good; recorded, the merchant only adds the refund once it settles |
   | `transaction_id` not numeric; `custom` missing, not signed with the current secret, or for another invoice | 400 |
   | Charged amount or currency differs from the signed `custom` | 400 |
   | Otherwise | Record `custom.invoice_amount` on invoice `transaction_id` |

   Why `custom` is signed: anyone holding the API Key (another integration of the same
   Khipu account, or a leaked key) can create payments with any `custom` and our
   `notify_url`, and Khipu signs their notifications like any other. Without the secret
   they cannot produce a `custom` this module accepts, and a genuine one copied from a
   real payment only fits a payment that charges exactly the same. Rotating the secret
   invalidates the `custom` of payments still in flight: those are refused (400) and the
   log says to record them by hand. Refunds read `custom` without the signature
   (`PaymentCustom::read()`): the payment was verified when it was recorded, so a later
   rotation does not block them.

8. `checkCbInvoiceID()`, `checkCbTransID()`, then
   `addInvoicePayment($invoiceId, $paymentId, $invoiceAmount, 0, 'khipu')`.
   The WHMCS transaction id **is the Khipu `payment_id`**: refunds depend on it.
   The amount must be in the invoice currency: `addInvoicePayment()` does **not** convert
   back from the processing currency (verified on 9.0.9: passing 9500 for a 10 USD
   invoice paid it and credited the client 9490 USD).
9. `addInvoicePayment()` reports nothing, so the row is looked up again; missing → 503.

API 1.3 (form post with `api_version=1.3`, `notification_token`): sent by payments created
by version 2.x before the upgrade. `LegacyClient` looks the payment up with
`GET https://khipu.com/api/2.0/payments?notification_token=…`, signed as the v2 client
did (`Authorization: <receiver_id>:<HMAC-SHA256("GET&<url>&notification_token=<token>", secret)>`).
The rest is the same, except that there is no `custom`: the amount Khipu charged is
recorded as is (2.x behaviour), under the `payment_id` (2.x used the token), so these
payments become refundable. A token already recorded by 2.x answers 200.

Gateway log entries carry ids, amounts and state only. The payment also includes the
payer's name, RUT and bank account number; they are never logged (data minimisation,
Ley 21.719).

---

## 3. Refunds (refund wallet, API v3)

WHMCS owns the refund UI and bookkeeping: the admin refunds a transaction from the
invoice, WHMCS calls `khipu_refund($params)` with `transid`, `amount` and `currency`, and
records the refund only if the module returns `status = success`. On anything else the
admin sees a generic failure and the module's `rawdata` goes to the gateway log, so every
`rawdata['resultado']` states whether money moved and whether retrying is safe.
**Refund requests are not idempotent: two requests are two refunds.**

### 3.1 Endpoints

| Endpoint | Use | Required fields in the 200 |
|---|---|---|
| `GET /v3/payments/{id}` | Before refunding (currency, `custom`, refund snapshot); after an unknown outcome | `payment_id`, `receiver_id`, `status`, `amount`, `currency` |
| `POST /v3/refunds` | `type: partial` with `amount` | `id`, `payment_id`, `refunded_amount`, `total_refunded`, `remaining` |
| `GET /v3/refund-wallet/balance` | `khipu_account_balance()` | `balance`, `currency` |

A 200 without its required fields is not a success: a proxy or WAF answering
`200 {"message": …}` must not be recorded as a refund.

Timeouts: 10 s / 20 s for refunds and the webhook, 3 s / 6 s for the balance (it renders
inside Billing > Transactions List).

### 3.2 `RefundProcessor::refund()`

1. Empty `transid` → `declined`, nothing sent.
2. `GET /v3/payments/{transid}`. 401/403 → `declined`, the API Key is wrong. 404 →
   `declined`, Khipu does not know the payment (typically a 2.x transaction whose id is
   a notification token). Other 4xx → `declined` with Khipu's reason. Anything else →
   `error`, safe to retry: nothing was sent.
3. With *Convert To For Processing*, WHMCS converts the refund into the processing
   currency at **today's** rate before calling the module (verified on 9.0.9: a 5 USD
   refund arrived as `4750.00 CLP`). `khipu_refund()` undoes that with the same WHMCS
   rates (`RefundRules::unconvert()`, snapping to "everything left" within half a
   processing-currency cent), so the payment's own ratio applies below. If the rates
   cannot be read it returns `error` and sends nothing: today's-rate amount would not
   match what WHMCS records.
4. `RefundRules::toPaymentAmount()` maps the WHMCS amount onto the payment:
   - if `custom` says the invoice was in the requested currency, scale by
     `payment.amount / custom.invoice_amount` (covers conversion and CLP rounding).
     Rounding is cumulative: `khipu_refund()` sums the earlier refunds of the transaction
     (`tblaccounts.refundid`), and this refund is the rounded running total after it minus
     the rounded running total before it. Refunding the whole invoice, at once or in
     parts, always adds up to exactly what Khipu charged; rounding each part on its own
     can leave the last one above what Khipu has left;
   - else, if the requested currency is the payment's, send it as is;
   - else `declined`. Zero after rounding or more than the payment → `declined`.
5. `POST /v3/refunds` with **`type: partial` and an explicit amount**, always. `full`
   refunds whatever Khipu has left, which WHMCS cannot know if something was refunded
   outside it, and WHMCS would then record a different amount than the one returned.
6. Outcome (`RefundRules::classifyOutcome()`):

   | Outcome | When | Result |
   |---|---|---|
   | `ok` | 200 with all fields | `success`, `transid` = Khipu refund id. Khipu's `message` goes to `rawdata`: it means *queued*, which is the normal path (every refund waits for Khipu's daily batch, even with balance to spare), not a shortage |
   | `rejected` | 4xx | `declined`. `field = receiver_id` (or the wallet-disabled message) → wallet not enabled. "no es reembolsable" → first `GET /v3/refund-wallet/balance`, because an account without the wallet can get that same text and the balance endpoint tells them apart; otherwise explained from `status_detail` |
   | `unknown` | 5xx, transport error, unreadable 200 | Read the payment again and compare (below) |

7. Unknown outcome, `RefundRules::resolveUnknown()` on snapshots taken before and after:

   | Comparison | State | Result |
   |---|---|---|
   | Exactly one new refund in `refunds`, same amount | `confirmed` | `success` with that refund id |
   | New refunds with other amounts, or `status_detail` changed | `likely` | `error`: do not retry; check the Khipu portal, record manually if refunded |
   | Same refunds and state, with `refunds` present or `status_detail = normal` | `absent` | `error`: not visible yet. **Not** "safe to retry": a request that timed out may still commit on Khipu's side, so the admin is told to check the Khipu portal in a few minutes first |
   | Second lookup failed, or not decidable | `unknown` | `error`: do not retry; Khipu portal or soporte@khipu.com with the `payment_id` |

   `refunds` and `total_refunded` only appear on a payment once it has refunds, so their
   absence alone does not tell "none" from "API without the field". An empty
   `status_detail` is also read as "has refunds" (`reversed` means a *voided* payment,
   not a refunded one); a change from `normal` to `''` counts as `likely`.

### 3.3 Refund wallet

`khipu_account_balance()` returns a `BalanceCollection` with one `Balance` (WHMCS 8.2+
API), shown in Billing > Transactions List (*Gateway Balances*) to Full Administrators or
roles with the *View Gateway Balances* permission. When the wallet is disabled or Khipu does not answer in time it returns an
empty collection: that page must render regardless.

A disabled wallet is detected by `field === 'receiver_id'`; the text match
(`RefundRules::WALLET_DISABLED_NEEDLE`) is a fallback cut before the noun, so a change
of wording does not break it. The module's own texts say "reversa"; the merchant manual
tells merchants that Khipu's messages may say "devolución" for the same thing.

---

## 4. Stored data

The module has no tables of its own.

| Where | What |
|---|---|
| `tblpaymentgateways` (gateway `khipu`) | `api_key`, `receiver_id`, `secret`, plus WHMCS's own settings |
| `tblaccounts` | Payments: `transid` = Khipu `payment_id`. Refunds: `transid` = Khipu refund id, `refundid` = the payment row |
| `tblgatewaylog` | One entry per notification, payment-creation failure and refund attempt |
| Khipu payment `custom` | Invoice id, invoice amount and currency, charged amount and currency, HMAC signature |

---

## 5. Security notes

- The pay form is HMAC-signed and carries no amounts (§2.1); the redirect endpoint
  re-reads everything from WHMCS.
- The payment's `custom` is HMAC-signed and bound to the charged amount (§2.3), so an
  API Key alone cannot make the webhook credit an invoice.
- The webhook fails closed on signature, receiver and shape, and records only what
  `GET /v3/payments/{id}` confirms (§2.3). Concurrent notifications are serialised.
  The signature timestamp is not checked for freshness: Khipu retries for two days, and
  a replayed notification can only re-confirm a payment that is already recorded.
- Every outgoing call: TLS peer and host verification on, https only
  (`CURLPROTO_HTTPS`), connect and total timeouts.
- Only `https://` payment URLs are followed.
- Everything rendered into HTML is escaped (`Gateway::html()`); Khipu's error texts never
  reach the payer.
- Logs exclude payer personal data (§2.3).
- Library files have no side effects; `khipu/index.php` stops directory listing.

---

## 6. Development

### Tests

```bash
docker run --rm -v "$PWD":/app -w /app -u "$(id -u):$(id -g)" composer:2 install
docker run --rm -v "$PWD":/app -w /app -u "$(id -u):$(id -g)" php:7.2-cli php vendor/bin/phpunit
docker run --rm -v "$PWD":/app -w /app -u "$(id -u):$(id -g)" php:8.3-cli php vendor/bin/phpunit
```

PHPUnit 8.5 on purpose: the last one that installs on PHP 7.2 and still runs on 8.x.
`composer.json` pins `config.platform.php` to 7.2.5. Composer is a development tool only:
the module ships no `vendor/`.

New logic should stay testable the same way: anything that needs the network takes an
injectable transport (`Client`, `LegacyClient`); anything that decides goes in a pure
class. Code inside the three WHMCS entry points cannot be tested without WHMCS.

Syntax check on both ends of the supported range:

```bash
for v in 7.2 8.4; do
  docker run --rm -v "$PWD":/app -w /app php:$v-cli \
    sh -c 'for f in khipu.php callback/*.php khipu/*.php; do php -l "$f" >/dev/null || exit 1; done && echo ok'
done
```

### Local WHMCS

`dev/` runs WHMCS 8.13 and 9.0 in Docker, with the module mounted from the repository.
See [dev/README.md](../dev/README.md).

### Packaging

```bash
./package.sh     # dist/whmcs-khipu.zip
```

The zip mirrors the WHMCS tree (`modules/gateways/…`) and contains only runtime files.
Version 2.x shipped a Composer `vendor/` into the shared `modules/gateways/` directory,
where it could clash with other gateways; 3.x keeps everything in `modules/gateways/khipu/`.

### Release checklist

1. `khipu/Version.php` → `PLUGIN`, and the version in `README.md` and both manuals.
2. Tests green on PHP 7.2 and 8.3; lint on 7.2 and 8.4.
3. Try the zip on WHMCS 8.13 and 9.0 (`dev/`), **over a 2.x install** too: the upgrade
   path is what breaks in production.
4. `./package.sh`, GitHub release with `dist/whmcs-khipu.zip` attached.
