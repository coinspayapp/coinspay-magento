<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Model\Config\Source;

use CoinsPay\Payment\Model\Core\Settings;
use Magento\Framework\Data\OptionSourceInterface;
use Magento\Sales\Model\Order\Config as OrderConfig;

/**
 * Statuses of one order state for an event on the settings page, the store's own ones included;
 * with "do not change the status" and "same as Paid" where the event allows them (etc/di.xml).
 */
class Status implements OptionSourceInterface
{
    /** @var OrderConfig */
    private $orderConfig;

    /** @var string */
    private $state;

    /** @var bool */
    private $noChange;

    /** @var bool */
    private $sameAsPaid;

    /**
     * @param OrderConfig $orderConfig
     * @param string $state
     * @param bool $noChange
     * @param bool $sameAsPaid
     */
    public function __construct(OrderConfig $orderConfig, string $state = 'processing', bool $noChange = false, bool $sameAsPaid = false)
    {
        $this->orderConfig = $orderConfig;
        $this->state = $state;
        $this->noChange = $noChange;
        $this->sameAsPaid = $sameAsPaid;
    }

    /**
     * @inheritdoc
     */
    public function toOptionArray()
    {
        $options = [];
        if ($this->sameAsPaid) {
            $options[] = ['value' => Settings::SAME_AS_PAID, 'label' => __('Same as "Paid"')];
        }
        if ($this->noChange) {
            $options[] = ['value' => Settings::NO_CHANGE, 'label' => __('Do not change the order (comment only)')];
        }
        foreach ($this->orderConfig->getStateStatuses($this->state) as $code => $label) {
            $options[] = ['value' => (string)$code, 'label' => $label];
        }
        return $options;
    }
}
