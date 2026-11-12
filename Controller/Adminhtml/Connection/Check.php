<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Controller\Adminhtml\Connection;

use CoinsPay\Payment\Model\Config;
use CoinsPay\Payment\Model\Core\ApiClient;
use CoinsPay\Payment\Model\Core\Connection;
use CoinsPay\Payment\Model\Core\Settings;
use CoinsPay\Payment\Model\Core\Text;
use CoinsPay\Payment\Model\Http;
use CoinsPay\Payment\Model\Kits;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Model\StoreManagerInterface;

/**
 * "Check connection" of the settings page (AJAX).
 */
class Check extends Action implements HttpPostActionInterface
{
    /** Who may change payment methods may check them. */
    public const ADMIN_RESOURCE = 'Magento_Payment::payment';

    /** @var JsonFactory */
    private $jsonFactory;

    /** @var Kits */
    private $kits;

    /** @var Http */
    private $http;

    /** @var Config */
    private $config;

    /** @var StoreManagerInterface */
    private $storeManager;

    /**
     * @param Context $context
     * @param JsonFactory $jsonFactory
     * @param Kits $kits
     * @param Http $http
     * @param Config $config
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        Context $context,
        JsonFactory $jsonFactory,
        Kits $kits,
        Http $http,
        Config $config,
        StoreManagerInterface $storeManager
    ) {
        parent::__construct($context);
        $this->jsonFactory = $jsonFactory;
        $this->kits = $kits;
        $this->http = $http;
        $this->config = $config;
        $this->storeManager = $storeManager;
    }

    /**
     * @inheritdoc
     */
    public function execute()
    {
        $storeId = $this->storeId();
        $saved = $this->kits->forStore($storeId)->settings();
        $url = rtrim(trim((string)$this->getRequest()->getParam('api_url')), '/');
        $key = trim((string)$this->getRequest()->getParam('api_key'));
        // An untouched key field holds asterisks: the saved key is checked then.
        if ($key === '' || preg_match('/^\*+$/', $key)) {
            $key = $saved->apiKey();
        }
        $client = new ApiClient($this->http, $url === '' ? Settings::DEFAULT_API_URL : $url, $key, $this->config->userAgent());
        $text = new Text(static function (string $phrase, array $args): string {
            return (string)__($phrase, ...$args);
        });
        $base = (string)$this->storeManager->getStore($storeId)->getBaseCurrencyCode();
        $answer = Connection::check($client, $key, $base, $text);
        $this->kits->forStore($storeId)->currencies()->flush();
        return $this->jsonFactory->create()->setData($answer);
    }

    /**
     * Store view of the scope the settings page was opened in.
     *
     * @return int|null
     */
    private function storeId(): ?int
    {
        $store = (int)$this->getRequest()->getParam('store');
        if ($store > 0) {
            return $store;
        }
        $website = (int)$this->getRequest()->getParam('website');
        if ($website > 0) {
            $default = $this->storeManager->getWebsite($website)->getDefaultStore();
            return $default ? (int)$default->getId() : null;
        }
        $view = $this->storeManager->getDefaultStoreView();
        return $view ? (int)$view->getId() : null;
    }
}
