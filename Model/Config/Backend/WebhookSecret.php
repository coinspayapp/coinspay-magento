<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Model\Config\Backend;

use Magento\Config\Model\Config\Backend\Encrypted;
use Magento\Framework\Exception\LocalizedException;

/**
 * The webhook secret: kept encrypted, never shown back, checked for its form.
 */
class WebhookSecret extends Encrypted
{
    /**
     * @inheritdoc
     */
    public function beforeSave()
    {
        $value = trim((string)$this->getValue());
        if ($value !== '' && !preg_match('/^\*+$/', $value) && !preg_match('/^whsec_[A-Za-z0-9_-]{8,}$/', $value)) {
            throw new LocalizedException(__('The webhook secret starts with whsec_.'));
        }
        $this->setValue($value);
        return parent::beforeSave();
    }
}
