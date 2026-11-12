<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Model;

use CoinsPay\Payment\Model\Core\Tokens;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;

/**
 * The CoinsPay test buyer: a browser that opened the signed link from the settings. With a test
 * key, the payment method shows to it only (by default), and only its orders a test payment completes.
 */
class TestBuyer
{
    public const COOKIE = 'coinspay_test_buyer';

    /** @var CookieManagerInterface */
    private $cookies;

    /** @var CookieMetadataFactory */
    private $cookieMetadataFactory;

    /** @var Config */
    private $config;

    /** @var OrderStore */
    private $store;

    /**
     * @param CookieManagerInterface $cookies
     * @param CookieMetadataFactory $cookieMetadataFactory
     * @param Config $config
     * @param OrderStore $store
     */
    public function __construct(
        CookieManagerInterface $cookies,
        CookieMetadataFactory $cookieMetadataFactory,
        Config $config,
        OrderStore $store
    ) {
        $this->cookies = $cookies;
        $this->cookieMetadataFactory = $cookieMetadataFactory;
        $this->config = $config;
        $this->store = $store;
    }

    /**
     * Whether this browser is the test buyer now.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        $value = (string)$this->cookies->getCookie(self::COOKIE, '');
        return $value !== '' && $this->tokens()->checkTestBuyer($value);
    }

    /**
     * Makes this browser the test buyer for a day, when the link value is genuine.
     *
     * @param string $value
     * @return bool
     */
    public function activate(string $value): bool
    {
        if (!$this->tokens()->checkTestBuyer($value)) {
            return false;
        }
        $metadata = $this->cookieMetadataFactory->createPublicCookieMetadata()
            ->setDuration(Tokens::TEST_BUYER_TTL)
            ->setPath('/')
            ->setHttpOnly(true);
        $this->cookies->setPublicCookie(self::COOKIE, $value, $metadata);
        return true;
    }

    /**
     * The test buyer link of a store view, valid for a day.
     *
     * @param int|null $storeId
     * @return string
     */
    public function link(?int $storeId): string
    {
        return $this->store->storefrontUrl('coinspay/payment/test', $storeId, ['t' => $this->tokens()->testBuyer()]);
    }

    /**
     * Signed values with the secret of the installation.
     *
     * @return Tokens
     */
    private function tokens(): Tokens
    {
        return new Tokens($this->config->linkSecret());
    }
}
