<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Controller\Payment;

use CoinsPay\Payment\Model\TestBuyer;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;

/**
 * The test buyer link from the settings: this browser becomes the CoinsPay test buyer for a day.
 */
class Test implements HttpGetActionInterface
{
    /** @var RequestInterface */
    private $request;

    /** @var RedirectFactory */
    private $redirectFactory;

    /** @var ManagerInterface */
    private $messages;

    /** @var TestBuyer */
    private $testBuyer;

    /**
     * @param RequestInterface $request
     * @param RedirectFactory $redirectFactory
     * @param ManagerInterface $messages
     * @param TestBuyer $testBuyer
     */
    public function __construct(
        RequestInterface $request,
        RedirectFactory $redirectFactory,
        ManagerInterface $messages,
        TestBuyer $testBuyer
    ) {
        $this->request = $request;
        $this->redirectFactory = $redirectFactory;
        $this->messages = $messages;
        $this->testBuyer = $testBuyer;
    }

    /**
     * @inheritdoc
     */
    public function execute()
    {
        if ($this->testBuyer->activate((string)$this->request->getParam('t'))) {
            $this->messages->addSuccessMessage(
                __('You are the CoinsPay test buyer in this browser for a day: with a test key the payment method shows for you.')
            );
        } else {
            $this->messages->addErrorMessage(
                __('The test buyer link is wrong or expired. Make a new one in the CoinsPay settings.')
            );
        }
        return $this->redirectFactory->create()->setPath('/');
    }
}
