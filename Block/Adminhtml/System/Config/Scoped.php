<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * A row of the settings page that shows something of the store view in the scope of the page:
 * the chosen store view, the default one of the chosen website, or the default store view.
 */
abstract class Scoped extends Field
{
    /**
     * Store view of the scope of the settings page.
     *
     * @return int
     */
    protected function scopeStoreId(): int
    {
        $store = (int)$this->getRequest()->getParam('store');
        if ($store > 0) {
            return $store;
        }
        $website = (int)$this->getRequest()->getParam('website');
        if ($website > 0) {
            $default = $this->_storeManager->getWebsite($website)->getDefaultStore();
            if ($default) {
                return (int)$default->getId();
            }
        }
        $view = $this->_storeManager->getDefaultStoreView();
        return $view ? (int)$view->getId() : 0;
    }

    /**
     * No "use default" checkbox: the row shows, it does not save.
     *
     * @param AbstractElement $element
     * @return string
     */
    public function render(AbstractElement $element)
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }
}
