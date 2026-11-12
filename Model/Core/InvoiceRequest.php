<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Model\Core;

/**
 * Body of POST /invoices from plain order data.
 */
class InvoiceRequest
{
    public const MAX_DESCRIPTION = 1000;
    public const MAX_REFERENCE = 190;
    public const MAX_RETURN_URL = 500;

    /**
     * Request body of a new invoice.
     *
     * @param array $order {currency, amount, number, items: [{name, quantity}], return_url}; number is the
     *                     order number the buyer sees (increment id).
     * @param array $options {lifetime, stablecoins_only, buyer_pays_fee, buyer_pays_network, auto_return}.
     * @return array
     */
    public static function body(array $order, array $options): array
    {
        $lines = [];
        foreach ($order['items'] as $item) {
            $name = trim(html_entity_decode(strip_tags((string)$item['name']), ENT_QUOTES, 'UTF-8'));
            if ($name !== '') {
                $lines[] = $name . " \u{00D7} " . Util::normalize((string)$item['quantity']);
            }
        }

        // No title: the payment page then names the invoice "Order <reference>" in the buyer's language.
        $body = [
            'currency' => strtoupper((string)$order['currency']),
            'amount' => (string)$order['amount'],
            'reference' => Util::truncate((string)$order['number'], self::MAX_REFERENCE),
            'lifetime_minutes' => Settings::clampLifetime((int)$options['lifetime']),
        ];
        if ($lines) {
            $body['description'] = Util::truncate(implode(', ', $lines), self::MAX_DESCRIPTION);
        }

        // A link longer than the API takes would fail the whole invoice; without it the buyer still pays.
        $returnUrl = (string)$order['return_url'];
        if ($returnUrl !== '' && strlen($returnUrl) <= self::MAX_RETURN_URL) {
            $body['return_url'] = $returnUrl;
            if (!empty($options['auto_return'])) {
                $body['auto_return'] = true;
            }
        }

        foreach (['stablecoins_only', 'buyer_pays_fee', 'buyer_pays_network'] as $flag) {
            if (!empty($options[$flag])) {
                $body[$flag] = true;
            }
        }
        return $body;
    }

    /**
     * Idempotency key: the same order, attempt and body give the same key, so a repeated click on the
     * payment button gets the invoice already created. The body is part of the key, so a changed total
     * never meets the "same key, other body" error; the site part keeps a staging copy on the same
     * CoinsPay shop from getting invoices of the live store.
     *
     * @param string $site
     * @param int $orderId
     * @param int $attempt Invoice number within the order, from 1.
     * @param array $body
     * @return string
     */
    public static function idempotencyKey(string $site, int $orderId, int $attempt, array $body): string
    {
        return sprintf(
            'mg-%s-%d-%d-%s',
            substr(hash('sha256', $site), 0, 8),
            $orderId,
            $attempt,
            substr(hash('sha256', Util::json($body)), 0, 12)
        );
    }
}
