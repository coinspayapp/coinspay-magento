<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Model;

use CoinsPay\Payment\Model\Core\Util;

/**
 * Links the buyer follows: the CoinsPay payment page in the language of the store view, and the
 * module's "pay again" and return links of an order.
 */
class Links
{
    /** @var Kits */
    private $kits;

    /** @var OrderStore */
    private $store;

    /** @var Config */
    private $config;

    /**
     * @param Kits $kits
     * @param OrderStore $store
     * @param Config $config
     */
    public function __construct(Kits $kits, OrderStore $store, Config $config)
    {
        $this->kits = $kits;
        $this->store = $store;
        $this->config = $config;
    }

    /**
     * The payment page of an invoice in the language of the store view (the page knows English and Russian).
     *
     * @param array $invoice
     * @param int $storeId
     * @return string
     */
    public function payPage(array $invoice, int $storeId): string
    {
        $url = Util::value($invoice, 'pay_url');
        $lang = strpos($this->config->storeLocale($storeId), 'ru_') === 0 ? 'ru' : 'en';
        return $url . (strpos($url, '?') === false ? '?' : '&') . 'lang=' . $lang;
    }

    /**
     * "Go to payment" of an order: makes or finds its invoice and opens the payment page.
     *
     * @param int $orderId
     * @param int $storeId
     * @return string
     */
    public function payAgain(int $orderId, int $storeId): string
    {
        $token = $this->kits->forStore($storeId)->tokens()->orderToken($orderId);
        return $this->store->storefrontUrl('coinspay/payment/redirect', $storeId, ['order' => $orderId, 'token' => $token]);
    }

    /**
     * The return page of an order; with $restore, it puts the items back in the cart.
     *
     * @param int $orderId
     * @param int $storeId
     * @param bool $restore
     * @return string
     */
    public function back(int $orderId, int $storeId, bool $restore = false): string
    {
        $token = $this->kits->forStore($storeId)->tokens()->orderToken($orderId);
        $query = ['order' => $orderId, 'token' => $token];
        if ($restore) {
            $query['restore'] = 1;
        }
        return $this->store->storefrontUrl('coinspay/payment/back', $storeId, $query);
    }
}
