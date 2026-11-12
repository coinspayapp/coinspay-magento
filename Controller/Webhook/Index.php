<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Controller\Webhook;

use CoinsPay\Payment\Model\Webhook;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Model\StoreManagerInterface;

/**
 * POST /coinspay/webhook: events of CoinsPay. They carry a signature instead of a form key.
 */
class Index implements HttpPostActionInterface, CsrfAwareActionInterface
{
    /** @var RequestInterface */
    private $request;

    /** @var JsonFactory */
    private $jsonFactory;

    /** @var StoreManagerInterface */
    private $storeManager;

    /** @var Webhook */
    private $webhook;

    /**
     * @param RequestInterface $request
     * @param JsonFactory $jsonFactory
     * @param StoreManagerInterface $storeManager
     * @param Webhook $webhook
     */
    public function __construct(
        RequestInterface $request,
        JsonFactory $jsonFactory,
        StoreManagerInterface $storeManager,
        Webhook $webhook
    ) {
        $this->request = $request;
        $this->jsonFactory = $jsonFactory;
        $this->storeManager = $storeManager;
        $this->webhook = $webhook;
    }

    /**
     * @inheritdoc
     */
    public function execute()
    {
        $body = '';
        $signature = '';
        if ($this->request instanceof HttpRequest) {
            $body = (string)$this->request->getContent();
            $signature = (string)$this->request->getHeader('X-Cpay-Signature');
        }
        [$status, $answer] = $this->webhook->handle($body, $signature, (int)$this->storeManager->getStore()->getId());
        $result = $this->jsonFactory->create();
        $result->setHttpResponseCode($status);
        return $result->setData(['result' => $answer]);
    }

    /**
     * @inheritdoc
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * @inheritdoc
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
