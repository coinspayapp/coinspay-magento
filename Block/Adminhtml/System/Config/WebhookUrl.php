<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Block\Adminhtml\System\Config;

use CoinsPay\Payment\Model\Kits;
use CoinsPay\Payment\Model\OrderStore;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * The webhook address of the store view in scope, and when the last test event arrived.
 */
class WebhookUrl extends Scoped
{
    /** @var OrderStore */
    private $store;

    /** @var Kits */
    private $kits;

    /**
     * @param Context $context
     * @param OrderStore $store
     * @param Kits $kits
     * @param array $data
     */
    public function __construct(Context $context, OrderStore $store, Kits $kits, array $data = [])
    {
        parent::__construct($context, $data);
        $this->store = $store;
        $this->kits = $kits;
    }

    /**
     * @inheritdoc
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        $storeId = $this->scopeStoreId();
        $url = $this->store->storefrontUrl('coinspay/webhook', $storeId);
        $id = $element->getHtmlId();
        $html = '<input id="' . $this->escapeHtmlAttr($id) . '" class="input-text admin__control-text" type="text" readonly="readonly"'
            . ' value="' . $this->escapeHtmlAttr($url) . '"/>';
        $last = $this->kits->forStore($storeId)->repo()->kvGet('last_webhook_test');
        if ($last !== null) {
            $time = $this->formatDate(
                (new \DateTime('@' . (int)$last))->format('Y-m-d H:i:s'),
                \IntlDateFormatter::MEDIUM,
                true
            );
            $html .= '<p class="note"><span>' . $this->escapeHtml(__('Last test event from the dashboard: %1.', $time)) . '</span></p>';
        }
        return $html;
    }
}
