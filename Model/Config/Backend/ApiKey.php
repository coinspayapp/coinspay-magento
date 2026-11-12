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
 * The API key: kept encrypted, never shown back (the field shows asterisks), checked for its form.
 */
class ApiKey extends Encrypted
{
    /**
     * @inheritdoc
     */
    public function beforeSave()
    {
        $value = trim((string)$this->getValue());
        if ($value !== '' && !preg_match('/^\*+$/', $value) && !preg_match('/^cpk_[A-Za-z0-9_-]{8,}$/', $value)) {
            throw new LocalizedException(
                __('This does not look like a CoinsPay API key: it starts with cpk_ or cpk_test_.')
            );
        }
        $this->setValue($value);
        return parent::beforeSave();
    }
}
