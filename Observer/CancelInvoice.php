<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Observer;

use CoinsPay\Payment\Model\Config;
use CoinsPay\Payment\Model\Poller;
use CoinsPay\Payment\Model\Writing;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;

/**
 * The store cancelled an order paid with CoinsPay (the staff, a mass action, Magento's own cron for
 * old pending payment orders): its open invoice is cancelled, so the buyer can no longer pay it.
 * The module's own cancels on an expired invoice are skipped.
 */
class CancelInvoice implements ObserverInterface
{
    /** @var Poller */
    private $poller;

    /** @var Writing */
    private $writing;

    /**
     * @param Poller $poller
     * @param Writing $writing
     */
    public function __construct(Poller $poller, Writing $writing)
    {
        $this->poller = $poller;
        $this->writing = $writing;
    }

    /**
     * Handles sales_order_save_after.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        $order = $observer->getEvent()->getData('order');
        if (!$order instanceof Order || $this->writing->active()) {
            return;
        }
        $payment = $order->getPayment();
        if ($payment === null || $payment->getMethod() !== Config::METHOD) {
            return;
        }
        if ($order->getState() === Order::STATE_CANCELED && $order->getOrigData('state') !== Order::STATE_CANCELED) {
            $this->poller->cancelForOrder((int)$order->getId());
        }
    }
}
