<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Model\Core;

/**
 * Currencies CoinsPay accepts prices in, with their decimals; cached for 12 hours.
 */
class Currencies
{
    public const TTL = 43200;

    /** After a failure, the next try comes in 5 minutes. */
    public const RETRY = 300;

    /** @var Settings */
    private $settings;

    /** @var RepoInterface */
    private $repo;

    /** @var StoreInterface */
    private $store;

    /** @var array|null per request */
    private $list;

    /**
     * @param Settings $settings
     * @param RepoInterface $repo
     * @param StoreInterface $store
     */
    public function __construct(Settings $settings, RepoInterface $repo, StoreInterface $store)
    {
        $this->settings = $settings;
        $this->repo = $repo;
        $this->store = $store;
    }

    /**
     * Decimals of a currency.
     *
     * @param string $code
     * @return int
     * @throws ApiError CURRENCY_NOT_SUPPORTED or the list is not available.
     */
    public function scale(string $code): int
    {
        $list = $this->load(true);
        $code = strtoupper($code);
        if (!isset($list[$code])) {
            throw new ApiError(400, 'CURRENCY_NOT_SUPPORTED', 'CoinsPay does not accept prices in ' . $code);
        }
        return $list[$code];
    }

    /**
     * Whether CoinsPay accepts the currency; null when the list is not available.
     *
     * @param string $code
     * @return bool|null
     */
    public function supported(string $code): ?bool
    {
        try {
            $list = $this->load(false);
        } catch (ApiError $e) {
            return null;
        }
        return isset($list[strtoupper($code)]);
    }

    /**
     * Forgets the cached list.
     *
     * @return void
     */
    public function flush(): void
    {
        $this->list = null;
        $this->repo->kvDelete($this->key());
        $this->repo->kvDelete($this->key() . '_failed');
    }

    /**
     * The list, from the cache or the API.
     *
     * @param bool $force Ask the API even right after a failure.
     * @return array code → scale
     */
    private function load(bool $force): array
    {
        if ($this->list !== null) {
            return $this->list;
        }
        $cached = $this->repo->kvGet($this->key(), self::TTL);
        if ($cached !== null) {
            $list = Util::decode($cached);
            if ($list) {
                return $this->list = $list;
            }
        }
        if (!$force && $this->repo->kvGet($this->key() . '_failed', self::RETRY) !== null) {
            throw new ApiError(0, 'NETWORK', 'The currency list is not available');
        }

        try {
            $data = $this->settings->client()->currencies();
        } catch (ApiError $e) {
            $this->repo->kvSet($this->key() . '_failed', '1');
            $this->store->log('currency list not loaded: ' . $e->summary(), true);
            throw $e;
        }

        $list = [];
        foreach (['fiat', 'crypto'] as $kind) {
            if (empty($data[$kind]) || !is_array($data[$kind])) {
                continue;
            }
            foreach ($data[$kind] as $currency) {
                if (isset($currency['code'], $currency['scale'])) {
                    $list[strtoupper((string)$currency['code'])] = (int)$currency['scale'];
                }
            }
        }
        $this->repo->kvSet($this->key(), Util::json($list));
        $this->repo->kvDelete($this->key() . '_failed');
        return $this->list = $list;
    }

    /**
     * Cache key: per API address and mode.
     *
     * @return string
     */
    private function key(): string
    {
        $mode = $this->settings->isTestMode() ? 'test' : 'live';
        return 'currencies_' . substr(hash('sha256', $this->settings->apiUrl() . '|' . $mode), 0, 12);
    }
}
