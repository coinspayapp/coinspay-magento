<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Block\Adminhtml\System\Config;

use CoinsPay\Payment\Model\TestBuyer;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * "Open the store as the test buyer": a signed link to the store view in scope, valid for a day.
 */
class TestLink extends Scoped
{
    /** @var TestBuyer */
    private $testBuyer;

    /**
     * @param Context $context
     * @param TestBuyer $testBuyer
     * @param array $data
     */
    public function __construct(Context $context, TestBuyer $testBuyer, array $data = [])
    {
        parent::__construct($context, $data);
        $this->testBuyer = $testBuyer;
    }

    /**
     * @inheritdoc
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        return '<a id="' . $this->escapeHtmlAttr($element->getHtmlId()) . '" class="action-default" target="_blank" rel="noopener"'
            . ' href="' . $this->escapeUrl($this->testBuyer->link($this->scopeStoreId())) . '">'
            . $this->escapeHtml(__('Open the store as the test buyer')) . '</a>';
    }
}
