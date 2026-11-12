<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Block\Adminhtml\Order;

use CoinsPay\Payment\Model\Config;
use CoinsPay\Payment\Model\Core\Util;
use CoinsPay\Payment\Model\Repo;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;

/**
 * CoinsPay invoices of the order on its page in the admin, with "Refresh".
 */
class Invoices extends Template
{
    /** @var Repo */
    private $repo;

    /** @var array|null */
    private $rows;

    /**
     * @param Context $context
     * @param Repo $repo
     * @param array $data
     */
    public function __construct(Context $context, Repo $repo, array $data = [])
    {
        parent::__construct($context, $data);
        $this->repo = $repo;
    }

    /**
     * Invoices of the order, newest first, with what the table shows.
     *
     * @return array
     */
    public function invoices(): array
    {
        if ($this->rows === null) {
            $this->rows = [];
            $orderId = (int)$this->getRequest()->getParam('order_id');
            foreach ($orderId > 0 ? $this->repo->forOrder($orderId) : [] as $row) {
                $snapshot = Util::decode($row['snapshot'] ?? '');
                $this->rows[] = [
                    'number' => (string)$row['number'],
                    'id' => (string)$row['invoice_id'],
                    'status' => (string)$row['status'],
                    'test' => !empty($row['test']),
                    'amount' => $row['amount'] . ' ' . $row['currency'],
                    'paid' => Util::normalize(Util::value($snapshot, 'amount_paid')) . ' ' . $row['currency'],
                    'created' => (int)$row['created_at'],
                ];
            }
        }
        return $this->rows;
    }

    /**
     * Address of "Refresh".
     *
     * @return string
     */
    public function refreshUrl(): string
    {
        return $this->getUrl('coinspay/invoice/refresh');
    }

    /**
     * Id of the order.
     *
     * @return int
     */
    public function orderId(): int
    {
        return (int)$this->getRequest()->getParam('order_id');
    }

    /**
     * A date for people.
     *
     * @param int $time
     * @return string
     */
    public function time(int $time): string
    {
        return $this->formatDate((new \DateTime('@' . $time))->format('Y-m-d H:i:s'), \IntlDateFormatter::MEDIUM, true);
    }

    /**
     * Code of the payment method.
     *
     * @return string
     */
    public function method(): string
    {
        return Config::METHOD;
    }
}
