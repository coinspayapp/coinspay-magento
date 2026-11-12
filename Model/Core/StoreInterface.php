<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Model\Core;

/**
 * What the core needs from Magento: orders and what may be done to them. The core makes no Magento
 * calls itself, so it runs in unit tests; CoinsPay\Payment\Model\OrderStore implements this.
 */
interface StoreInterface
{
    /**
     * The order, or null.
     *
     * @param int $orderId Entity id.
     * @return array|null {id, number (increment id), store_id, state, status, total (order currency),
     *                    base_total (base currency), currency, base_currency, ours (paid with this module),
     *                    test_buyer (placed by the test buyer), items: [{name, quantity}]}
     */
    public function order(int $orderId): ?array;

    /**
     * Adds a comment for the staff; the status stays.
     *
     * @param int $orderId
     * @param string $comment
     * @return string The order status afterwards.
     */
    public function comment(int $orderId, string $comment): string;

    /**
     * Sets a status of the current state of the order.
     *
     * @param int $orderId
     * @param string $status
     * @param string $comment
     * @return string|null The order status afterwards; null when the status does not belong to the state.
     */
    public function setStatus(int $orderId, string $status, string $comment): ?string;

    /**
     * Records the payment.
     *
     * A paid Magento invoice with the CoinsPay invoice as the transaction, the processing state with the
     * given status; the order e-mail goes out if it has not yet.
     *
     * @param int $orderId
     * @param string $invoiceId CoinsPay invoice id.
     * @param string $status A status of the processing state.
     * @param string $comment
     * @return string|null The order status afterwards; null when the order cannot be invoiced (cancelled).
     */
    public function pay(int $orderId, string $invoiceId, string $status, string $comment): ?string;

    /**
     * Cancels the order: Magento returns the stock.
     *
     * @param int $orderId
     * @param string $status A status of the canceled state.
     * @param string $comment
     * @return string|null The order status afterwards; null when the order cannot be cancelled.
     */
    public function cancel(int $orderId, string $status, string $comment): ?string;

    /**
     * Puts the order on hold for the store to decide.
     *
     * An order that is cancelled already gets the status in its canceled state when the status belongs to it.
     *
     * @param int $orderId
     * @param string $status A status of the holded state.
     * @param string $comment
     * @return string|null The order status afterwards; null when neither is possible.
     */
    public function hold(int $orderId, string $status, string $comment): ?string;

    /**
     * Link the buyer comes back by from the payment page.
     *
     * @param int $orderId
     * @param string $token
     * @return string
     */
    public function returnUrl(int $orderId, string $token): string;

    /**
     * Address of the store: part of the idempotency key, so a staging copy never gets the live store's invoices.
     *
     * @return string
     */
    public function siteUrl(): string;

    /**
     * Writes to the log of the module.
     *
     * @param string $message
     * @param bool $error Errors are logged always, the rest with the debug setting only.
     * @return void
     */
    public function log(string $message, bool $error): void;
}
