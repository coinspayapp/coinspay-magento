<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Block\Order;

use CoinsPay\Payment\Model\Config;
use CoinsPay\Payment\Model\Links;
use CoinsPay\Payment\Model\OrderStore;
use CoinsPay\Payment\Model\Repo;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Sales\Model\Order;

/**
 * "Go to payment" on the order page of the customer account, while the order waits for its crypto
 * payment. The order controller of Magento has already checked that the order is the customer's.
 */
class Pay extends Template
{
    /** @var OrderStore */
    private $store;

    /** @var Repo */
    private $repo;

    /** @var Links */
    private $links;

    /**
     * @param Context $context
     * @param OrderStore $store
     * @param Repo $repo
     * @param Links $links
     * @param array $data
     */
    public function __construct(Context $context, OrderStore $store, Repo $repo, Links $links, array $data = [])
    {
        parent::__construct($context, $data);
        $this->store = $store;
        $this->repo = $repo;
        $this->links = $links;
    }

    /**
     * The "Go to payment" link, or '' when the order does not wait for a CoinsPay payment.
     *
     * @return string
     */
    public function payUrl(): string
    {
        $orderId = (int)$this->getRequest()->getParam('order_id');
        $order = $orderId > 0 ? $this->store->load($orderId) : null;
        if ($order === null
            || $order->getState() !== Order::STATE_PENDING_PAYMENT
            || $order->getPayment() === null
            || $order->getPayment()->getMethod() !== Config::METHOD
            || $this->repo->paidInvoice($orderId) !== ''
        ) {
            return '';
        }
        $row = $this->repo->current($orderId);
        if ($row && $row['status'] !== 'open') {
            return '';
        }
        return $this->links->payAgain($orderId, (int)$order->getStoreId());
    }
}
