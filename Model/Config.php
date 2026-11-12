<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Sales\Model\Order\Config as OrderConfig;
use Magento\Store\Model\ScopeInterface;

/**
 * Settings of the module (payment/coinspay/*) in the scope of a store view. Keys and secrets are
 * declared encrypted in etc/config.xml, so Magento decrypts them on read.
 */
class Config
{
    /** Code of the payment method. */
    public const METHOD = 'coinspay';

    /** Version of the module, for the User-Agent of API requests and the settings page. */
    public const VERSION = '0.1.1';

    private const PATH = 'payment/coinspay/';

    /** @var ScopeConfigInterface */
    private $scopeConfig;

    /** @var OrderConfig */
    private $orderConfig;

    /** @var ProductMetadataInterface */
    private $productMetadata;

    /** @var array state → status codes, per request */
    private $statuses = [];

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param OrderConfig $orderConfig
     * @param ProductMetadataInterface $productMetadata
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        OrderConfig $orderConfig,
        ProductMetadataInterface $productMetadata
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->orderConfig = $orderConfig;
        $this->productMetadata = $productMetadata;
    }

    /**
     * A setting of the module for a store view.
     *
     * @param string $key
     * @param int|null $storeId
     * @return mixed
     */
    public function value(string $key, ?int $storeId)
    {
        return $this->scopeConfig->getValue(self::PATH . $key, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * A yes/no setting of the module.
     *
     * @param string $key
     * @param int|null $storeId
     * @return bool
     */
    public function flag(string $key, ?int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(self::PATH . $key, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Secret of the module's own links (return, pay again, test buyer); made on install.
     *
     * @return string
     */
    public function linkSecret(): string
    {
        return (string)$this->scopeConfig->getValue(self::PATH . 'link_secret');
    }

    /**
     * Whether a status belongs to an order state.
     *
     * @param string $status
     * @param string $state
     * @return bool
     */
    public function statusExists(string $status, string $state): bool
    {
        if (!isset($this->statuses[$state])) {
            $this->statuses[$state] = array_map('strval', array_keys($this->orderConfig->getStateStatuses($state)));
        }
        return in_array($status, $this->statuses[$state], true);
    }

    /**
     * Default status of an order state.
     *
     * @param string $state
     * @return string
     */
    public function defaultStatus(string $state): string
    {
        return (string)$this->orderConfig->getStateDefaultStatus($state);
    }

    /**
     * Locale of order comments: the default locale of the installation, the language of the staff.
     *
     * @return string
     */
    public function notesLocale(): string
    {
        return (string)$this->scopeConfig->getValue('general/locale/code') ?: 'en_US';
    }

    /**
     * Locale of a store view.
     *
     * @param int|null $storeId
     * @return string
     */
    public function storeLocale(?int $storeId): string
    {
        return (string)$this->scopeConfig->getValue('general/locale/code', ScopeInterface::SCOPE_STORE, $storeId) ?: 'en_US';
    }

    /**
     * Minutes after which Magento itself cancels a pending payment order (Sales → Orders Cron Settings).
     *
     * @param int|null $storeId
     * @return int
     */
    public function pendingPaymentLifetime(?int $storeId): int
    {
        return (int)$this->scopeConfig->getValue('sales/orders/delete_pending_after', ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * User-Agent of API requests.
     *
     * @return string
     */
    public function userAgent(): string
    {
        return 'CoinsPay-Magento/' . self::VERSION . ' Magento/' . $this->productMetadata->getVersion() . ' PHP/' . PHP_VERSION;
    }
}
