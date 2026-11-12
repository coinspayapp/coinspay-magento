<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Model\Ui;

use CoinsPay\Payment\Model\Config;
use CoinsPay\Payment\Model\Kits;
use Magento\Checkout\Model\ConfigProviderInterface;

/**
 * What the checkout shows under the payment method: where the buyer pays, and the test mode.
 */
class ConfigProvider implements ConfigProviderInterface
{
    /** @var Kits */
    private $kits;

    /**
     * @param Kits $kits
     */
    public function __construct(Kits $kits)
    {
        $this->kits = $kits;
    }

    /**
     * @inheritdoc
     */
    public function getConfig()
    {
        $settings = $this->kits->forStore()->settings();
        return [
            'payment' => [
                Config::METHOD => [
                    'description' => (string)__('After you place the order, you choose the coin and the network on the CoinsPay payment page.'),
                    'testNote' => $settings->isTestMode()
                        ? (string)__('Test mode: the invoice is paid with a button, no coins move.')
                        : '',
                ],
            ],
        ];
    }
}
