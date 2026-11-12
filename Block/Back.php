<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Block;

use Magento\Framework\View\Element\Template;

/**
 * The return page of an unpaid order; Controller\Payment\Back fills the "view" data.
 */
class Back extends Template
{
    /**
     * A value of the view.
     *
     * @param string $key
     * @return mixed
     */
    public function view(string $key)
    {
        $view = (array)$this->getData('view');
        return $view[$key] ?? null;
    }

    /**
     * The home page of the store.
     *
     * @return string
     */
    public function homeUrl(): string
    {
        return $this->getUrl('');
    }
}
