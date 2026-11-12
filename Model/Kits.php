<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Model;

use CoinsPay\Payment\Model\Core\Kit;
use CoinsPay\Payment\Model\Core\Settings;
use CoinsPay\Payment\Model\Core\Text;
use CoinsPay\Payment\Model\Core\Tokens;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The core of each store view: settings come from its scope, the key-value store is of its website.
 */
class Kits
{
    /** @var Config */
    private $config;

    /** @var OrderStore */
    private $store;

    /** @var Repo */
    private $repo;

    /** @var Http */
    private $http;

    /** @var Phrases */
    private $phrases;

    /** @var StoreManagerInterface */
    private $storeManager;

    /** @var Kit[] */
    private $kits = [];

    /**
     * @param Config $config
     * @param OrderStore $store
     * @param Repo $repo
     * @param Http $http
     * @param Phrases $phrases
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        Config $config,
        OrderStore $store,
        Repo $repo,
        Http $http,
        Phrases $phrases,
        StoreManagerInterface $storeManager
    ) {
        $this->config = $config;
        $this->store = $store;
        $this->repo = $repo;
        $this->http = $http;
        $this->phrases = $phrases;
        $this->storeManager = $storeManager;
    }

    /**
     * The core of a store view; the current one when none is given.
     *
     * @param int|null $storeId
     * @return Kit
     */
    public function forStore(?int $storeId = null): Kit
    {
        $store = $this->storeManager->getStore($storeId);
        $id = (int)$store->getId();
        if (!isset($this->kits[$id])) {
            $config = $this->config;
            $settings = new Settings(
                static function (string $key) use ($config, $id) {
                    return $config->value($key, $id);
                },
                [$config, 'statusExists'],
                $this->http,
                $config->userAgent()
            );
            $phrases = $this->phrases;
            $locale = $config->notesLocale();
            $text = new Text(static function (string $phrase, array $args) use ($phrases, $locale): string {
                return $phrases->translate($phrase, $args, $locale);
            });
            $this->kits[$id] = new Kit(
                $this->store,
                $this->repo->scoped('w' . (int)$store->getWebsiteId()),
                $settings,
                new Tokens($config->linkSecret()),
                $text
            );
        }
        return $this->kits[$id];
    }
}
