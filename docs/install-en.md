# CoinsPay for Magento 2: installation

The module takes cryptocurrency payments through CoinsPay: the buyer picks a coin and a network on
the CoinsPay payment page, and the order is invoiced by itself once the payment arrives. The money
goes straight to your wallets.

It works on Magento Open Source and Adobe Commerce 2.4.4 to 2.4.9. You need a CoinsPay account with
a shop.

## Installation

With Composer:

```bash
composer require coinspay/coinspay-magento
bin/magento module:enable CoinsPay_Payment
bin/magento setup:upgrade
bin/magento setup:di:compile            # in production mode
bin/magento setup:static-content:deploy # in production mode
bin/magento cache:flush
```

Without Composer: unpack `coinspay-magento.zip` into `app/code/CoinsPay/Payment` (the folder must hold
`registration.php`) and run the same commands from `module:enable` on.

The module adds two order statuses: "Awaiting crypto payment" (the Pending Payment state) and
"Crypto payment review" (On Hold, and Canceled for a payment that came after a cancel).

## Connection

Stores → Configuration → Sales → Payment Methods → "CoinsPay: cryptocurrency payments". The key and
the webhook are set per website: switch the scope at the top of the page to a website to give it its
own CoinsPay shop.

1. In the CoinsPay dashboard open your shop.
2. Under "API keys" create a key with **"Invoices only"** access and paste it into "API key". The
   module needs nothing more, and such a key cannot withdraw money, even if someone with admin
   access reads it.
3. Leave "API address" empty: the module then uses `https://coinspay.app/api/v1`.
4. Under "Webhook" enter the address the module shows, save, and copy the `whsec_…` secret into
   "Webhook secret".
5. Save the configuration, press "Check connection", then "Send test event" in the dashboard: the
   module shows when the event arrived.

The countries that see the method and the order total limits are in the "Checkout" group. The title
of the method is set per store view: give each language its own.

## Order statuses

Each invoice event does something to the order, and the "Order statuses" group picks the status:

| Invoice event | What happens | Default status |
|---|---|---|
| Order placed, waiting for the payment | Pending Payment | Awaiting crypto payment |
| Payment on its way, partially paid | a comment only | not changed |
| Paid (on time, late, with overpayment, marked paid by hand) | a paid invoice, Processing | Processing |
| Expired unpaid, invoice cancelled | the order is cancelled, the stock returns | Canceled |
| Amount differs, the paid order was cancelled, a test payment not by the test buyer, expired or cancelled partially paid | On Hold | Crypto payment review |

Your own statuses of the same order state are in the lists too. A comment with amounts, coins and
transaction hashes goes to the order history at every event; the buyer does not see it.

When you cancel an order, the module cancels its open invoice and the buyer can no longer pay it.

## Test mode

With a test key (`cpk_test_…`) invoices are paid with a button on the payment page and no coins
move. By default the method then shows only to the test buyer: open the store with "Open the store
as the test buyer" from the settings. The link works for a day in the browser you open it in. A test
payment completes only orders of the test buyer.

## When webhooks do not arrive

The module asks CoinsPay about open invoices from the Magento cron every 5 minutes: the cron of
Magento must run. "Refresh" on the order page and `bin/magento coinspay:sync` ask at once.

The webhook address must stay the base address of the store view, as the settings show it: Magento
redirects other host names to it, and webhooks do not follow redirects. In maintenance mode Magento
refuses webhooks; polling catches up afterwards.

## Good to know

- The order is placed before the payment, as with PayPal: Magento reserves the stock at once. The
  order e-mail goes out after the payment (switch "Send the order e-mail after the payment" off to
  send it at once).
- Magento cancels pending payment orders older than "Pending Payment Order Lifetime" (Sales → Sales →
  Orders Cron Settings) by itself. Keep the time to pay of the module shorter: the settings page warns
  otherwise.
- Magento does not reopen a cancelled order: a payment that comes after the cancel is recorded in a
  comment, and the order gets "Crypto payment review" for you to decide.
- A buyer who closed the payment page can pay later: the order page in their account shows "Go to
  payment" while the invoice is open.
- The invoice is made in the order currency when CoinsPay accepts it, otherwise in the base currency.
- The log is `var/log/coinspay.log`. Errors are logged always; keys and secrets never.
