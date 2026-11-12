<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Observer;

use CoinsPay\Payment\Model\Config;
use CoinsPay\Payment\Model\Kits;
use CoinsPay\Payment\Model\TestBuyer;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Api\Data\CartInterface;

/**
 * Hides the payment method at checkout when it cannot take the order: no API key, a test key and
 * not the test buyer, a currency CoinsPay does not accept.
 */
class Availability implements ObserverInterface
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
     * Handles payment_method_is_active.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        $event = $observer->getEvent();
        $method = $event->getData('method_instance');
        $result = $event->getData('result');
        if (!$method || $method->getCode() !== Config::METHOD || !$result || !$result->getData('is_available')) {
            return;
        }
        $quote = $event->getData('quote');
        $storeId = $quote instanceof CartInterface ? (int)$quote->getStoreId() : null;
        if (!$this->available($quote instanceof CartInterface ? $quote : null, $storeId)) {
            $result->setData('is_available', false);
        }
    }

    /**
     * Whether the method can take the cart.
     *
     * @param CartInterface|null $quote
     * @param int|null $storeId
     * @return bool
     */
    private function available(?CartInterface $quote, ?int $storeId): bool
    {
        $kit = $this->kits->forStore($storeId);
        $settings = $kit->settings();
        if (!$settings->configured() || $this->config->linkSecret() === '') {
            return false;
        }
        if ($settings->isTestMode() && $settings->flag('test_buyer_only') && !$this->testBuyer->isActive()) {
            return false;
        }
        if ($quote === null || !$quote->getCurrency()) {
            return true;
        }
        // The invoice is made in the order currency or the base one; an unknown list (the API is down)
        // does not hide the method: the invoice error tells the buyer.
        $currencies = $kit->currencies();
        $quoteCurrency = (string)$quote->getCurrency()->getQuoteCurrencyCode();
        $baseCurrency = (string)$quote->getCurrency()->getBaseCurrencyCode();
        $quoteSupported = $currencies->supported($quoteCurrency);
        return $quoteSupported !== false || $currencies->supported($baseCurrency) !== false;
    }
}
