<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Model\Core;

/**
 * The core of one store view: its settings and the services built on them.
 */
class Kit
{
    /** @var StoreInterface */
    private $store;

    /** @var RepoInterface */
    private $repo;

    /** @var Settings */
    private $settings;

    /** @var Tokens */
    private $tokens;

    /** @var Text */
    private $text;

    /** @var Currencies|null */
    private $currencies;

    /** @var Sync|null */
    private $sync;

    /** @var Payment|null */
    private $payment;

    /**
     * @param StoreInterface $store
     * @param RepoInterface $repo
     * @param Settings $settings
     * @param Tokens $tokens
     * @param Text $text Texts of order comments.
     */
    public function __construct(StoreInterface $store, RepoInterface $repo, Settings $settings, Tokens $tokens, Text $text)
    {
        $this->store = $store;
        $this->repo = $repo;
        $this->settings = $settings;
        $this->tokens = $tokens;
        $this->text = $text;
    }

    /**
     * Orders.
     *
     * @return StoreInterface
     */
    public function store(): StoreInterface
    {
        return $this->store;
    }

    /**
     * The module's tables; the key-value store is of this website.
     *
     * @return RepoInterface
     */
    public function repo(): RepoInterface
    {
        return $this->repo;
    }

    /**
     * Settings of the store view.
     *
     * @return Settings
     */
    public function settings(): Settings
    {
        return $this->settings;
    }

    /**
     * Signed values of the module's links.
     *
     * @return Tokens
     */
    public function tokens(): Tokens
    {
        return $this->tokens;
    }

    /**
     * Texts of order comments.
     *
     * @return Text
     */
    public function text(): Text
    {
        return $this->text;
    }

    /**
     * Currencies CoinsPay accepts.
     *
     * @return Currencies
     */
    public function currencies(): Currencies
    {
        if ($this->currencies === null) {
            $this->currencies = new Currencies($this->settings, $this->repo, $this->store);
        }
        return $this->currencies;
    }

    /**
     * Applies invoices to orders.
     *
     * @return Sync
     */
    public function sync(): Sync
    {
        if ($this->sync === null) {
            $this->sync = new Sync($this->store, $this->settings, $this->repo, $this->currencies(), $this->text);
        }
        return $this->sync;
    }

    /**
     * Makes invoices of orders.
     *
     * @return Payment
     */
    public function payment(): Payment
    {
        if ($this->payment === null) {
            $this->payment = new Payment(
                $this->store,
                $this->settings,
                $this->repo,
                $this->currencies(),
                $this->sync(),
                $this->tokens,
                $this->text
            );
        }
        return $this->payment;
    }
}
