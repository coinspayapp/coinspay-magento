# CoinsPay for Magento 2

Accept cryptocurrency payments in your Magento store. Buyers pay on the CoinsPay payment page, and the money goes on to your own wallets.

## Requirements

| | Supported | Tested on |
|---|---|---|
| Magento Open Source, Adobe Commerce | 2.4.4 to 2.4.9 | Magento Open Source 2.4.4-p13, 2.4.6-p15, 2.4.7-p10, 2.4.8-p5, 2.4.9 |
| PHP | 7.4 (Magento 2.4.4 only), 8.1 to 8.5 | 8.1, 8.2, 8.3, 8.4, 8.5 |

You also need a [CoinsPay](https://coinspay.app) account with a shop.

## Description

The module adds a "Cryptocurrency" payment method to the checkout. When the buyer places the order, Magento creates it in the "Pending Payment" state with the "Awaiting crypto payment" status, and the buyer goes to the CoinsPay payment page, picks a coin and a network and pays. Once the payment is confirmed, the module invoices the order (Processing) and the buyer comes back to the order success page.

Prices stay in your store currency. CoinsPay converts the total at the current rate and holds the coin amount for 30 minutes, so the rate does not change while the buyer opens their wallet.

### Coins and networks

More than 30 coins and tokens on TRON, Ethereum, BNB Smart Chain, Solana, Bitcoin and Litecoin, among them USDT, USDC, BTC, ETH, BNB, TRX, LTC and SOL. The full list is on [coinspay.app/coins](https://coinspay.app/coins).

### What the module takes care of

- A payment invoices the order: a paid Magento invoice with the CoinsPay invoice as its transaction, and the order e-mail goes out then (or when the order is placed, if you prefer).
- Each invoice event maps to an order status of your choice within its order state, your own statuses included. A comment with amounts, coins and transaction hashes goes to the order history at every event; the buyer does not see it.
- An invoice that expires unpaid cancels the order, and the stock returns.
- When you cancel an order, the module cancels its open invoice, so the buyer can no longer pay it. This works for Magento's own cancel of old pending payment orders too.
- If a payment does not match the order total, or arrives for an order you cancelled, the order is put on hold with the "Crypto payment review" status and a comment for you to review.
- A buyer who left the payment page can pay later from their account: the order page shows "Go to payment" while the invoice is open. A buyer who comes back without paying can put the items back in the cart.
- Webhooks are signed and checked. If they do not arrive, the Magento cron asks CoinsPay about open invoices every 5 minutes; "Refresh" on the order page and `bin/magento coinspay:sync` do it at once.
- Several websites: the key and the webhook secret are set per website, so each may have its own CoinsPay shop.
- The saved API key and webhook secret are stored encrypted and never shown back.
- English and Russian.

### Checkout

The standard Magento checkout (Luma and themes based on it). The Hyvä theme should work with its Luma checkout fallback, since the module uses the standard checkout renderer, but it has not been tested yet. Hyvä Checkout and headless storefronts (GraphQL, PWA Studio) are not supported yet.

### Why CoinsPay

Payments go straight to your wallets. Each network takes up to five payout addresses with percentage shares, for example 70% to you and 30% to a partner, and nothing is kept on the platform.

The fee is as low as 0.3% of each payout: the rate goes down as the monthly turnover of all your shops grows. There is no monthly or setup fee. For the first 10 days after your first payment, payouts up to $500 carry no fee. You can let the buyer pay the CoinsPay fee and the network fee on top of the order total. Details are on the [pricing page](https://coinspay.app/pricing).

## Installation

With Composer (recommended):

```bash
composer require coinspay/coinspay-magento
bin/magento module:enable CoinsPay_Payment
bin/magento setup:upgrade
bin/magento setup:di:compile            # in production mode
bin/magento setup:static-content:deploy # in production mode
bin/magento cache:flush
```

Without Composer: download `coinspay-magento.zip` from the [releases](https://github.com/coinspayapp/coinspay-magento/releases/latest), unpack it into `app/code/CoinsPay/Payment` and run the same `bin/magento` commands.

The archive includes the installation guide in [English](docs/install-en.md) and [Russian](docs/install-ru.md).

## Configuration

Stores → Configuration → Sales → Payment Methods → "CoinsPay: cryptocurrency payments". Switch the scope to a website to give it its own CoinsPay shop.

1. In the CoinsPay dashboard open your shop:
   - under "API keys" create a key with "Invoices only" access and paste it into "API key". The module needs nothing more, and such a key cannot withdraw money;
   - leave "API address" empty: the module then uses `https://coinspay.app/api/v1`;
   - under "Webhook" enter the address the module shows, save, and copy the `whsec_…` secret into "Webhook secret".
2. Press "Check connection", then "Send test event" in the dashboard. The module shows when the event arrived.
3. Under "Order statuses" check the status of each invoice event.
4. Save.

The countries that see the method and the order total limits are in the "Checkout" group of the same page.

## Test mode

With a test key (`cpk_test_…`) invoices are paid with a button on the payment page and no coins move. By default the method is then shown to the test buyer only: open the store with "Open the store as the test buyer" from the settings (the link works for a day in that browser). A test payment completes only orders of the test buyer.

## When webhooks do not arrive

The module polls CoinsPay for open invoices from the Magento cron every 5 minutes, so the cron of Magento must run (it is required by Magento anyway). The settings page warns when it does not. In maintenance mode Magento refuses webhooks, and polling catches up afterwards.

## Good to know

- The order is placed before the payment, as with PayPal: Magento reserves the stock at once. An unpaid order is cancelled when its invoice expires, and the stock returns.
- Magento cancels pending payment orders older than "Pending Payment Order Lifetime" (Sales → Sales → Orders Cron Settings, 480 minutes by default) on its own. Keep the time to pay of the module shorter; the settings page warns otherwise.
- Magento does not reopen a cancelled order. A payment that comes after the order was cancelled is recorded in a comment, and the order gets the "Crypto payment review" status for you to decide.
- The webhook address must be the base address of the store view, as the settings show it: Magento redirects other host names to it, and webhooks do not follow redirects.
- The invoice is made in the order currency when CoinsPay accepts it, otherwise in the base currency.
- The title of the method is set per store view: give a Russian store view its own title.
- The log is `var/log/coinspay.log`. Errors are logged always; keys and secrets never.

## Development

```
Model/Core/        the core shared with the other CoinsPay modules, without Magento calls: decisions,
                   synchronization, settings and statuses, the invoice request, signatures, the API client
Model/             Magento side: orders (OrderStore), tables (Repo), settings (Config), webhook, poller
Gateway/           the payment method on the Magento payment gateway: placing the order
Controller/        redirect to the payment page, return, webhook, test buyer link; admin AJAX
Observer/          availability at checkout, cancel of the invoice with the order
Block/, view/      settings rows, order pages, the checkout renderer
Setup/Patch/Data/  the module's order statuses and the secret of its links
Test/Unit/         unit tests of the core
bin/build.php      builds dist/coinspay-magento.zip
bin/tools/         PHPUnit and the code sniffers of the checks
```

Checks and the build run in Docker; the tools are installed in `bin/tools`:

```bash
P=$(pwd)
docker run --rm -v "$P:/app" -w /app/bin/tools composer:2 install
docker run --rm -v "$P:/app" -w /app php:7.4-cli bin/tools/vendor/bin/phpunit
docker run --rm -v "$P:/app" -w /app php:8.5-cli bin/tools/vendor/bin/phpunit
docker run --rm -v "$P:/app" -w /app php:8.5-cli bin/tools/vendor/bin/phpcs -q
docker run --rm -v "$P:/app" -w /app --entrypoint php composer:2 bin/build.php
```

`phpcs` checks the Magento coding standard of the Adobe Commerce Marketplace and PHP 7.4 compatibility. `bin/build.php` needs PHP with the zip extension.

## Changelog

### 0.1.0

- First release.

## License

Open Software License 3.0 (OSL-3.0). The full text is in [LICENSE.txt](LICENSE.txt).
