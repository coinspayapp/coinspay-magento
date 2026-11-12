<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Test\Unit\Fake;

use CoinsPay\Payment\Model\Core\StoreInterface;

/**
 * Orders in memory with Magento's rules for states: a cancelled order is not invoiced or put on
 * hold, an invoiced one is not cancelled. A write fails as many times as asked.
 */
class FakeStore implements StoreInterface
{
    /** Statuses of each state, as Magento assigns them after the module's data patch. */
    public const STATUSES = [
        'pending_payment' => ['pending_payment', 'coinspay_awaiting'],
        'processing' => ['processing', 'fraud'],
        'holded' => ['holded', 'coinspay_review'],
        'canceled' => ['canceled', 'coinspay_review'],
    ];

    /** @var array id → order */
    public $orders = [];

    /** @var array [action, order id, status, comment] */
    public $writes = [];

    /** @var int Writes that throw before one succeeds. */
    public $failures = 0;

    /** @var array */
    public $logs = [];

    /**
     * Adds an order placed with this module and waiting for the payment.
     *
     * @param int $id
     * @param array $overrides
     * @return void
     */
    public function place(int $id, array $overrides = []): void
    {
        $this->orders[$id] = $overrides + [
            'id' => $id,
            'number' => sprintf('%09d', $id),
            'store_id' => 1,
            'state' => 'pending_payment',
            'status' => 'coinspay_awaiting',
            'total' => '49.9',
            'base_total' => '49.9',
            'currency' => 'USD',
            'base_currency' => 'USD',
            'ours' => true,
            'test_buyer' => false,
            'items' => [['name' => 'Espresso beans', 'quantity' => 1]],
            'invoiced' => false,
        ];
    }

    /**
     * Statuses of a state.
     *
     * @param string $status
     * @param string $state
     * @return bool
     */
    public static function statusExists(string $status, string $state): bool
    {
        return in_array($status, self::STATUSES[$state] ?? [], true);
    }

    /**
     * @inheritdoc
     */
    public function order(int $orderId): ?array
    {
        return $this->orders[$orderId] ?? null;
    }

    /**
     * @inheritdoc
     */
    public function comment(int $orderId, string $comment): string
    {
        $this->write('comment', $orderId, $this->orders[$orderId]['status'], $comment);
        return $this->orders[$orderId]['status'];
    }

    /**
     * @inheritdoc
     */
    public function setStatus(int $orderId, string $status, string $comment): ?string
    {
        if (!self::statusExists($status, $this->orders[$orderId]['state'])) {
            return null;
        }
        return $this->move($orderId, 'status', $this->orders[$orderId]['state'], $status, $comment);
    }

    /**
     * @inheritdoc
     */
    public function pay(int $orderId, string $invoiceId, string $status, string $comment): ?string
    {
        $order = $this->orders[$orderId];
        if ($order['state'] === 'canceled' || $order['invoiced']) {
            return null;
        }
        $after = $this->move($orderId, 'pay', 'processing', $status !== '' ? $status : 'processing', $comment);
        $this->orders[$orderId]['invoiced'] = true;
        return $after;
    }

    /**
     * @inheritdoc
     */
    public function cancel(int $orderId, string $status, string $comment): ?string
    {
        $order = $this->orders[$orderId];
        if ($order['state'] === 'canceled' || $order['invoiced']) {
            return null;
        }
        return $this->move($orderId, 'cancel', 'canceled', $status, $comment);
    }

    /**
     * @inheritdoc
     */
    public function hold(int $orderId, string $status, string $comment): ?string
    {
        $state = $this->orders[$orderId]['state'];
        if ($state === 'canceled') {
            return self::statusExists($status, 'canceled') ? $this->move($orderId, 'hold', 'canceled', $status, $comment) : null;
        }
        return $this->move($orderId, 'hold', 'holded', $status, $comment);
    }

    /**
     * @inheritdoc
     */
    public function returnUrl(int $orderId, string $token): string
    {
        return 'https://shop.example/coinspay/payment/back/order/' . $orderId . '/token/' . $token . '/';
    }

    /**
     * @inheritdoc
     */
    public function siteUrl(): string
    {
        return 'https://shop.example/';
    }

    /**
     * @inheritdoc
     */
    public function log(string $message, bool $error): void
    {
        $this->logs[] = [$message, $error];
    }

    /**
     * Moves the order to a state and status.
     *
     * @param int $orderId
     * @param string $action
     * @param string $state
     * @param string $status
     * @param string $comment
     * @return string
     */
    private function move(int $orderId, string $action, string $state, string $status, string $comment): string
    {
        $this->write($action, $orderId, $status, $comment);
        $this->orders[$orderId]['state'] = $state;
        $this->orders[$orderId]['status'] = $status;
        return $status;
    }

    /**
     * Records a write, or fails it.
     *
     * @param string $action
     * @param int $orderId
     * @param string $status
     * @param string $comment
     * @return void
     */
    private function write(string $action, int $orderId, string $status, string $comment): void
    {
        if ($this->failures > 0) {
            $this->failures--;
            throw new \RuntimeException('the order is not writable');
        }
        $this->writes[] = [$action, $orderId, $status, $comment];
    }
}
