<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Test\Unit\Fake;

use CoinsPay\Payment\Model\Core\Decision;
use CoinsPay\Payment\Model\Core\RepoInterface;
use CoinsPay\Payment\Model\Core\Util;

/**
 * The module's tables in memory.
 */
class FakeRepo implements RepoInterface
{
    /** @var array invoice id → row */
    public $rows = [];

    /** @var array */
    public $kv = [];

    /** @var int */
    private $sequence = 0;

    /**
     * @inheritdoc
     */
    public function add(int $orderId, int $storeId, array $invoice, int $attempt, bool $testBuyer, int $pollUntil): void
    {
        $id = strtolower(Util::value($invoice, 'id'));
        $this->rows[$id] = [
            'invoice_id' => $id,
            'order_id' => $orderId,
            'store_id' => $storeId,
            'attempt' => $attempt,
            'amount' => Util::normalize(Util::value($invoice, 'amount')),
            'currency' => strtoupper(Util::value($invoice, 'currency')),
            'test' => empty($invoice['test']) ? 0 : 1,
            'test_buyer' => $testBuyer ? 1 : 0,
            'status' => Util::value($invoice, 'status'),
            'snapshot' => Util::json(Decision::snapshot($invoice)),
            'fingerprint' => '',
            'paid_applied' => 0,
            'plugin_status' => $this->pluginStatus($orderId),
            'plugin_event' => $this->pluginEvent($orderId),
            'next_poll' => null,
            'poll_until' => $pollUntil,
            'sequence' => ++$this->sequence,
        ];
    }

    /**
     * @inheritdoc
     */
    public function find(string $invoiceId): ?array
    {
        return $this->rows[strtolower($invoiceId)] ?? null;
    }

    /**
     * @inheritdoc
     */
    public function forOrder(int $orderId): array
    {
        $rows = array_values(array_filter($this->rows, static function (array $row) use ($orderId): bool {
            return (int)$row['order_id'] === $orderId;
        }));
        usort($rows, static function (array $a, array $b): int {
            return $b['sequence'] <=> $a['sequence'];
        });
        return $rows;
    }

    /**
     * @inheritdoc
     */
    public function current(int $orderId): ?array
    {
        return $this->forOrder($orderId)[0] ?? null;
    }

    /**
     * @inheritdoc
     */
    public function paidInvoice(int $orderId): string
    {
        foreach ($this->forOrder($orderId) as $row) {
            if ($row['paid_applied']) {
                return $row['invoice_id'];
            }
        }
        return '';
    }

    /**
     * @inheritdoc
     */
    public function pluginStatus(int $orderId): string
    {
        $row = $this->current($orderId);
        return $row ? (string)$row['plugin_status'] : '';
    }

    /**
     * @inheritdoc
     */
    public function pluginEvent(int $orderId): string
    {
        $row = $this->current($orderId);
        return $row ? (string)$row['plugin_event'] : '';
    }

    /**
     * @inheritdoc
     */
    public function setPluginStatus(int $orderId, string $status, string $event): void
    {
        foreach ($this->rows as $id => $row) {
            if ((int)$row['order_id'] === $orderId) {
                $this->rows[$id]['plugin_status'] = $status;
                $this->rows[$id]['plugin_event'] = $event;
            }
        }
    }

    /**
     * @inheritdoc
     */
    public function saveState(array $invoice): void
    {
        $id = strtolower(Util::value($invoice, 'id'));
        if (isset($this->rows[$id])) {
            $this->rows[$id]['status'] = Util::value($invoice, 'status');
            $this->rows[$id]['snapshot'] = Util::json(Decision::snapshot($invoice));
        }
    }

    /**
     * @inheritdoc
     */
    public function setFingerprint(string $invoiceId, string $fingerprint): void
    {
        $this->rows[strtolower($invoiceId)]['fingerprint'] = $fingerprint;
    }

    /**
     * @inheritdoc
     */
    public function markPaid(string $invoiceId): void
    {
        $this->rows[strtolower($invoiceId)]['paid_applied'] = 1;
    }

    /**
     * @inheritdoc
     */
    public function setNextPoll(string $invoiceId, ?int $at): void
    {
        $this->rows[strtolower($invoiceId)]['next_poll'] = $at;
    }

    /**
     * @inheritdoc
     */
    public function due(int $limit): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function expirePolling(): void
    {
    }

    /**
     * @inheritdoc
     */
    public function kvGet(string $key, int $maxAge = 0): ?string
    {
        return $this->kv[$key] ?? null;
    }

    /**
     * @inheritdoc
     */
    public function kvSet(string $key, string $value): void
    {
        $this->kv[$key] = $value;
    }

    /**
     * @inheritdoc
     */
    public function kvDelete(string $key): void
    {
        unset($this->kv[$key]);
    }

    /**
     * @inheritdoc
     */
    public function lock(int $orderId): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function unlock(int $orderId): void
    {
    }
}
