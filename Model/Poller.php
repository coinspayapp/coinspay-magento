<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Model;

use CoinsPay\Payment\Model\Core\ApiClient;
use CoinsPay\Payment\Model\Core\ApiError;
use CoinsPay\Payment\Model\Core\Sync;

/**
 * Asks CoinsPay about open invoices when webhooks do not arrive (not set up, a firewall, the store
 * in maintenance): from the Magento cron, "bin/magento coinspay:sync" and "Refresh" on the order page.
 */
class Poller
{
    public const BATCH = 25;

    /** @var Repo */
    private $repo;

    /** @var Kits */
    private $kits;

    /** @var OrderStore */
    private $store;

    /**
     * @param Repo $repo
     * @param Kits $kits
     * @param OrderStore $store
     */
    public function __construct(Repo $repo, Kits $kits, OrderStore $store)
    {
        $this->repo = $repo;
        $this->kits = $kits;
        $this->store = $store;
    }

    /**
     * One batch of due invoices.
     *
     * @return int Invoices checked.
     */
    public function run(): int
    {
        $this->repo->kvSet('last_poll', (string)time());
        $this->repo->expirePolling();
        $failures = 0;
        $checked = 0;
        foreach ($this->repo->due(self::BATCH) as $row) {
            $invoiceId = (string)$row['invoice_id'];
            // Moved forward first: a fatal error on one invoice must not stop the next runs.
            $this->repo->setNextPoll($invoiceId, time() + Sync::INTERVAL_OPEN);
            try {
                $this->refresh($row);
                $checked++;
            } catch (ApiError $e) {
                if ($e->isNotFound()) {
                    $this->repo->setNextPoll($invoiceId, null);
                    continue;
                }
                $this->store->log(sprintf('poll of invoice %s failed: %s', $invoiceId, $e->summary()), true);
                // CoinsPay is not answering: the next run tries again.
                if (++$failures >= 3) {
                    break;
                }
            } catch (\Exception $e) {
                $this->store->log(sprintf('poll of invoice %s failed: %s', $invoiceId, $e->getMessage()), true);
            }
        }
        return $checked;
    }

    /**
     * Asks CoinsPay about the current invoice of an order now ("Refresh" on the order page).
     *
     * @param int $orderId
     * @return array|null {invoice, result}; null when the order has no invoice.
     * @throws ApiError
     */
    public function refreshOrder(int $orderId): ?array
    {
        $row = $this->repo->current($orderId);
        if (!$row) {
            return null;
        }
        return $this->refresh($row);
    }

    /**
     * The store cancelled the order: its open invoice is cancelled at once, so the buyer can no longer
     * pay it. Only the API is called here (the order is being saved); the next webhook or poll writes
     * the comment.
     *
     * @param int $orderId
     * @return void
     */
    public function cancelForOrder(int $orderId): void
    {
        $row = $this->repo->current($orderId);
        if (!$row || $row['status'] !== 'open') {
            return;
        }
        $kit = $this->kits->forStore((int)$row['store_id']);
        if (!$kit->settings()->configured()) {
            return;
        }
        try {
            $invoice = $this->cancel($kit->settings()->client(), (string)$row['invoice_id']);
            $this->repo->saveState($invoice);
            $this->repo->setNextPoll((string)$row['invoice_id'], time());
            $this->store->log(sprintf('order %d cancelled: invoice %s cancelled too', $orderId, $row['invoice_id']), false);
        } catch (\Exception $e) {
            $this->store->log(
                sprintf('order %d cancelled, but its invoice %s was not: %s', $orderId, $row['invoice_id'], $e->getMessage()),
                true
            );
        }
    }

    /**
     * Fetches an invoice and applies it to its order.
     *
     * @param array $row
     * @return array {invoice, result}
     */
    private function refresh(array $row): array
    {
        $kit = $this->kits->forStore((int)$row['store_id']);
        $settings = $kit->settings();
        if (!$settings->configured()) {
            throw new ApiError(0, 'NOT_CONFIGURED', 'The CoinsPay API key is not set for the store view');
        }
        $client = $settings->client();
        $order = $this->store->order((int)$row['order_id']);
        if ($order === null) {
            throw new ApiError(404, 'ORDER_NOT_FOUND', 'The order is gone');
        }
        // The store cancelled the order and the invoice is still open: it is cancelled now. This backs up
        // the order observer, which a direct database change bypasses.
        $storeCancelled = $order['state'] === 'canceled'
            && !in_array($this->repo->pluginEvent((int)$row['order_id']), ['expired', 'cancelled'], true);
        if ($row['status'] === 'open' && $storeCancelled) {
            $invoice = $this->cancel($client, (string)$row['invoice_id']);
        } else {
            $invoice = $client->invoice((string)$row['invoice_id']);
        }
        return ['invoice' => $invoice, 'result' => $kit->sync()->apply($invoice)];
    }

    /**
     * Cancels an invoice; one that is not open any more (paid or expired meanwhile) is fetched instead.
     *
     * @param ApiClient $client
     * @param string $invoiceId
     * @return array
     */
    private function cancel(ApiClient $client, string $invoiceId): array
    {
        try {
            return $client->cancelInvoice($invoiceId);
        } catch (ApiError $e) {
            return $client->invoice($invoiceId);
        }
    }
}
