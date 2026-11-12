<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Model\Core;

/**
 * The module's tables: invoices of orders (coinspay_invoice) and a small key-value store
 * (coinspay_kv), plus the lock of an order. Rows are arrays with the column names.
 */
interface RepoInterface
{
    /**
     * Remembers a new invoice of an order.
     *
     * @param int $orderId
     * @param int $storeId
     * @param array $invoice
     * @param int $attempt
     * @param bool $testBuyer
     * @param int $pollUntil
     * @return void
     */
    public function add(int $orderId, int $storeId, array $invoice, int $attempt, bool $testBuyer, int $pollUntil): void;

    /**
     * An invoice row by the CoinsPay invoice id.
     *
     * @param string $invoiceId
     * @return array|null
     */
    public function find(string $invoiceId): ?array;

    /**
     * Invoices of an order, newest first.
     *
     * @param int $orderId
     * @return array
     */
    public function forOrder(int $orderId): array;

    /**
     * The current (latest) invoice of an order, or null.
     *
     * @param int $orderId
     * @return array|null
     */
    public function current(int $orderId): ?array;

    /**
     * Id of the invoice that paid the order, or ''.
     *
     * @param int $orderId
     * @return string
     */
    public function paidInvoice(int $orderId): string;

    /**
     * The last order status the module set; '' before the first one.
     *
     * @param int $orderId
     * @return string
     */
    public function pluginStatus(int $orderId): string;

    /**
     * The event of the last status the module set.
     *
     * @param int $orderId
     * @return string
     */
    public function pluginEvent(int $orderId): string;

    /**
     * Remembers the status the module set, on every invoice of the order.
     *
     * @param int $orderId
     * @param string $status
     * @param string $event
     * @return void
     */
    public function setPluginStatus(int $orderId, string $status, string $event): void;

    /**
     * Keeps the last seen state of an invoice.
     *
     * @param array $invoice
     * @return void
     */
    public function saveState(array $invoice): void;

    /**
     * Remembers what of the invoice the order has seen.
     *
     * @param string $invoiceId
     * @param string $fingerprint
     * @return void
     */
    public function setFingerprint(string $invoiceId, string $fingerprint): void;

    /**
     * The invoice paid the order.
     *
     * @param string $invoiceId
     * @return void
     */
    public function markPaid(string $invoiceId): void;

    /**
     * When to ask CoinsPay about the invoice again; null — never.
     *
     * @param string $invoiceId
     * @param int|null $at
     * @return void
     */
    public function setNextPoll(string $invoiceId, ?int $at): void;

    /**
     * Invoices due for polling, longest unchecked first.
     *
     * @param int $limit
     * @return array
     */
    public function due(int $limit): array;

    /**
     * Stops polling invoices past their time.
     *
     * @return void
     */
    public function expirePolling(): void;

    /**
     * A value of the key-value store; null when missing or older than $maxAge seconds.
     *
     * @param string $key
     * @param int $maxAge
     * @return string|null
     */
    public function kvGet(string $key, int $maxAge = 0): ?string;

    /**
     * Saves a value of the key-value store.
     *
     * @param string $key
     * @param string $value
     * @return void
     */
    public function kvSet(string $key, string $value): void;

    /**
     * Deletes a value of the key-value store.
     *
     * @param string $key
     * @return void
     */
    public function kvDelete(string $key): void;

    /**
     * Takes the lock of an order: a webhook burst, the poller and the buyer's return apply one at a time.
     *
     * @param int $orderId
     * @return bool False when another request holds it too long.
     */
    public function lock(int $orderId): bool;

    /**
     * Releases the lock of an order.
     *
     * @param int $orderId
     * @return void
     */
    public function unlock(int $orderId): void;
}
