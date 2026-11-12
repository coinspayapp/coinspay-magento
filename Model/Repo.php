<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Model;

use CoinsPay\Payment\Model\Core\Decision;
use CoinsPay\Payment\Model\Core\RepoInterface;
use CoinsPay\Payment\Model\Core\Sync;
use CoinsPay\Payment\Model\Core\Util;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Lock\LockManagerInterface;

/**
 * The module's tables (etc/db_schema.xml) and the lock of an order. Invoices are shared by the
 * whole installation (an invoice id is unique); keys of the key-value store carry the scope given
 * by scoped(), so each website has its own last test webhook and warnings.
 */
class Repo implements RepoInterface
{
    /** Seconds to wait for the lock of an order. */
    private const LOCK_WAIT = 10;

    /** @var ResourceConnection */
    private $resource;

    /** @var LockManagerInterface */
    private $locks;

    /** @var string */
    private $scope = 'g';

    /**
     * @param ResourceConnection $resource
     * @param LockManagerInterface $locks
     */
    public function __construct(ResourceConnection $resource, LockManagerInterface $locks)
    {
        $this->resource = $resource;
        $this->locks = $locks;
    }

    /**
     * The same tables with the key-value store of another scope, e.g. "w1" for website 1.
     *
     * @param string $scope
     * @return Repo
     */
    public function scoped(string $scope): Repo
    {
        $copy = clone $this;
        $copy->scope = $scope;
        return $copy;
    }

    /**
     * @inheritdoc
     */
    public function add(int $orderId, int $storeId, array $invoice, int $attempt, bool $testBuyer, int $pollUntil): void
    {
        $now = time();
        $this->db()->insert($this->invoices(), [
            'invoice_id' => strtolower(Util::value($invoice, 'id')),
            'order_id' => $orderId,
            'store_id' => $storeId,
            'number' => (int)Util::value($invoice, 'number'),
            'attempt' => $attempt,
            'amount' => Util::normalize(Util::value($invoice, 'amount')),
            'currency' => strtoupper(Util::value($invoice, 'currency')),
            'test' => empty($invoice['test']) ? 0 : 1,
            'test_buyer' => $testBuyer ? 1 : 0,
            'status' => Util::value($invoice, 'status'),
            'pay_url' => Util::value($invoice, 'pay_url'),
            'snapshot' => Util::json(Decision::snapshot($invoice)),
            'plugin_status' => $this->pluginStatus($orderId),
            'plugin_event' => $this->pluginEvent($orderId),
            'next_poll' => $now + Sync::INTERVAL_OPEN,
            'poll_until' => $pollUntil,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @inheritdoc
     */
    public function find(string $invoiceId): ?array
    {
        $select = $this->db()->select()->from($this->invoices())->where('invoice_id = ?', strtolower($invoiceId));
        $row = $this->db()->fetchRow($select);
        return $row ?: null;
    }

    /**
     * @inheritdoc
     */
    public function forOrder(int $orderId): array
    {
        $select = $this->db()->select()
            ->from($this->invoices())
            ->where('order_id = ?', $orderId)
            ->order(['attempt DESC', 'created_at DESC']);
        return $this->db()->fetchAll($select);
    }

    /**
     * @inheritdoc
     */
    public function current(int $orderId): ?array
    {
        $select = $this->db()->select()
            ->from($this->invoices())
            ->where('order_id = ?', $orderId)
            ->order(['attempt DESC', 'created_at DESC'])
            ->limit(1);
        $row = $this->db()->fetchRow($select);
        return $row ?: null;
    }

    /**
     * @inheritdoc
     */
    public function paidInvoice(int $orderId): string
    {
        $select = $this->db()->select()
            ->from($this->invoices(), ['invoice_id'])
            ->where('order_id = ?', $orderId)
            ->where('paid_applied = 1')
            ->limit(1);
        return (string)$this->db()->fetchOne($select);
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
        $this->db()->update(
            $this->invoices(),
            ['plugin_status' => $status, 'plugin_event' => $event],
            ['order_id = ?' => $orderId]
        );
    }

    /**
     * @inheritdoc
     */
    public function saveState(array $invoice): void
    {
        $this->db()->update(
            $this->invoices(),
            [
                'status' => Util::value($invoice, 'status'),
                'snapshot' => Util::json(Decision::snapshot($invoice)),
                'updated_at' => time(),
            ],
            ['invoice_id = ?' => strtolower(Util::value($invoice, 'id'))]
        );
    }

    /**
     * @inheritdoc
     */
    public function setFingerprint(string $invoiceId, string $fingerprint): void
    {
        $this->db()->update($this->invoices(), ['fingerprint' => $fingerprint], ['invoice_id = ?' => strtolower($invoiceId)]);
    }

    /**
     * @inheritdoc
     */
    public function markPaid(string $invoiceId): void
    {
        $this->db()->update($this->invoices(), ['paid_applied' => 1], ['invoice_id = ?' => strtolower($invoiceId)]);
    }

    /**
     * @inheritdoc
     */
    public function setNextPoll(string $invoiceId, ?int $at): void
    {
        $this->db()->update($this->invoices(), ['next_poll' => $at], ['invoice_id = ?' => strtolower($invoiceId)]);
    }

    /**
     * @inheritdoc
     */
    public function due(int $limit): array
    {
        $now = time();
        $select = $this->db()->select()
            ->from($this->invoices())
            ->where('next_poll IS NOT NULL')
            ->where('next_poll <= ?', $now)
            ->where('poll_until > ?', $now)
            ->order('next_poll ASC')
            ->limit($limit);
        return $this->db()->fetchAll($select);
    }

    /**
     * @inheritdoc
     */
    public function expirePolling(): void
    {
        $this->db()->update(
            $this->invoices(),
            ['next_poll' => null],
            ['next_poll IS NOT NULL', 'poll_until <= ?' => time()]
        );
    }

    /**
     * Open invoices waiting for a poll that is late by more than the given seconds: the cron is silent.
     *
     * @param int $late
     * @return int
     */
    public function overdue(int $late): int
    {
        $now = time();
        $select = $this->db()->select()
            ->from($this->invoices(), ['n' => 'COUNT(*)'])
            ->where('next_poll IS NOT NULL')
            ->where('next_poll < ?', $now - $late)
            ->where('poll_until > ?', $now);
        return (int)$this->db()->fetchOne($select);
    }

    /**
     * @inheritdoc
     */
    public function kvGet(string $key, int $maxAge = 0): ?string
    {
        $select = $this->db()->select()->from($this->kv(), ['v', 'updated_at'])->where('k = ?', $this->key($key));
        $row = $this->db()->fetchRow($select);
        if (!$row || ($maxAge > 0 && (int)$row['updated_at'] < time() - $maxAge)) {
            return null;
        }
        return (string)$row['v'];
    }

    /**
     * @inheritdoc
     */
    public function kvSet(string $key, string $value): void
    {
        $this->db()->insertOnDuplicate(
            $this->kv(),
            ['k' => $this->key($key), 'v' => $value, 'updated_at' => time()],
            ['v', 'updated_at']
        );
    }

    /**
     * @inheritdoc
     */
    public function kvDelete(string $key): void
    {
        $this->db()->delete($this->kv(), ['k = ?' => $this->key($key)]);
    }

    /**
     * @inheritdoc
     */
    public function lock(int $orderId): bool
    {
        return $this->locks->lock($this->lockName($orderId), self::LOCK_WAIT);
    }

    /**
     * @inheritdoc
     */
    public function unlock(int $orderId): void
    {
        $this->locks->unlock($this->lockName($orderId));
    }

    /**
     * Name of the lock of an order.
     *
     * @param int $orderId
     * @return string
     */
    private function lockName(int $orderId): string
    {
        return 'coinspay_order_' . $orderId;
    }

    /**
     * Key of the key-value store in the scope.
     *
     * @param string $key
     * @return string
     */
    private function key(string $key): string
    {
        return $this->scope . ':' . $key;
    }

    /**
     * The database connection.
     *
     * @return AdapterInterface
     */
    private function db(): AdapterInterface
    {
        return $this->resource->getConnection();
    }

    /**
     * Table of invoices.
     *
     * @return string
     */
    private function invoices(): string
    {
        return $this->resource->getTableName('coinspay_invoice');
    }

    /**
     * Table of the key-value store.
     *
     * @return string
     */
    private function kv(): string
    {
        return $this->resource->getTableName('coinspay_kv');
    }
}
