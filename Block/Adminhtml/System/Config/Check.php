<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Block\Adminhtml\System\Config;

use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * "Check connection": checks the address and key entered above, saved or not (view/adminhtml/web/js/check.js).
 */
class Check extends Scoped
{
    /**
     * @inheritdoc
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        $id = $element->getHtmlId();
        // The fields of the same group: Magento prefixes the ids with the merchant country (payment_us_…).
        $group = (string)preg_replace('/_check$/', '', $id);
        $init = [
            'CoinsPay_Payment/js/check' => [
                'url' => $this->getUrl('coinspay/connection/check'),
                'result' => $id . '_result',
                'apiUrl' => $group . '_api_url',
                'apiKey' => $group . '_api_key',
                'website' => (int)$this->getRequest()->getParam('website'),
                'store' => (int)$this->getRequest()->getParam('store'),
            ],
        ];
        return '<button type="button" class="action-default" id="' . $this->escapeHtmlAttr($id) . '"'
            . ' data-mage-init="' . $this->escapeHtmlAttr((string)json_encode($init)) . '">'
            . '<span>' . $this->escapeHtml(__('Check connection')) . '</span></button>'
            . '<p class="note"><span>' . $this->escapeHtml(__('Uses the address and key entered above, saved or not.')) . '</span></p>'
            . '<div id="' . $this->escapeHtmlAttr($id . '_result') . '" class="coinspay-check-result" aria-live="polite"></div>';
    }
}
