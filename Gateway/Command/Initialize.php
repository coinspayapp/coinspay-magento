<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Gateway\Command;

use CoinsPay\Payment\Model\Config;
use CoinsPay\Payment\Model\Core\Settings;
use CoinsPay\Payment\Model\Kits;
use CoinsPay\Payment\Model\TestBuyer;
use Magento\Payment\Gateway\CommandInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;

/**
 * Placing the order: it waits for the payment in the pending_payment state with the status chosen
 * for "Order placed". The invoice is made right after, when the buyer goes to pay
 * (Controller\Payment\Redirect): an API error then does not lose the order the buyer just placed.
 */
class Initialize implements CommandInterface
{
    /** @var Kits */
    private $kits;

    /** @var Config */
    private $config;

    /** @var TestBuyer */
    private $testBuyer;

    /**
     * @param Kits $kits
     * @param Config $config
     * @param TestBuyer $testBuyer
     */
    public function __construct(Kits $kits, Config $config, TestBuyer $testBuyer)
    {
        $this->kits = $kits;
        $this->config = $config;
        $this->testBuyer = $testBuyer;
    }

    /**
     * @inheritdoc
     */
    public function execute(array $commandSubject)
    {
        $payment = SubjectReader::readPayment($commandSubject)->getPayment();
        $stateObject = SubjectReader::readStateObject($commandSubject);
        if (!$payment instanceof Payment) {
            return null;
        }
        $order = $payment->getOrder();
        $storeId = (int)$order->getStoreId();

        $status = $this->kits->forStore($storeId)->settings()->statusFor('created');
        if ($status === null || $status === Settings::NO_CHANGE) {
            $status = $this->config->defaultStatus(Order::STATE_PENDING_PAYMENT);
        }
        $stateObject->setData('state', Order::STATE_PENDING_PAYMENT);
        $stateObject->setData('status', $status);
        $stateObject->setData('is_notified', false);

        // The order e-mail waits for the payment (OrderStore::pay sends it), unless the store wants it now.
        if ($this->config->flag('order_email_after_payment', $storeId)) {
            $order->setCanSendNewEmailFlag(false);
        }
        if ($this->testBuyer->isActive()) {
            $payment->setAdditionalInformation('coinspay_test_buyer', 1);
        }
        return null;
    }
}
