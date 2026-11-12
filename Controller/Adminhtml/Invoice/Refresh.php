<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Controller\Adminhtml\Invoice;

use CoinsPay\Payment\Model\Core\Util;
use CoinsPay\Payment\Model\Poller;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;

/**
 * "Refresh" on the order page: asks CoinsPay about the invoice of the order now and applies it.
 */
class Refresh extends Action implements HttpPostActionInterface
{
    /** Who may see orders may refresh their payment. */
    public const ADMIN_RESOURCE = 'Magento_Sales::actions_view';

    /** @var JsonFactory */
    private $jsonFactory;

    /** @var Poller */
    private $poller;

    /**
     * @param Context $context
     * @param JsonFactory $jsonFactory
     * @param Poller $poller
     */
    public function __construct(Context $context, JsonFactory $jsonFactory, Poller $poller)
    {
        parent::__construct($context);
        $this->jsonFactory = $jsonFactory;
        $this->poller = $poller;
    }

    /**
     * @inheritdoc
     */
    public function execute()
    {
        $result = $this->jsonFactory->create();
        try {
            $answer = $this->poller->refreshOrder((int)$this->getRequest()->getParam('order_id'));
        } catch (\Exception $e) {
            return $result->setData(['ok' => false, 'message' => (string)__('The invoice was not refreshed: %1', $e->getMessage())]);
        }
        if ($answer === null) {
            return $result->setData(['ok' => false, 'message' => (string)__('The order has no CoinsPay invoice.')]);
        }
        $status = Util::value($answer['invoice'], 'status');
        return $result->setData([
            'ok' => true,
            'message' => (string)__('Refreshed: the invoice is %1.', $status),
            'status' => $status,
            'result' => $answer['result'],
        ]);
    }
}
