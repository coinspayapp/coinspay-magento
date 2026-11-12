<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Cron;

use CoinsPay\Payment\Model\Poller;

/**
 * Every 5 minutes: open invoices whose webhook did not arrive.
 */
class Poll
{
    /** @var Poller */
    private $poller;

    /**
     * @param Poller $poller
     */
    public function __construct(Poller $poller)
    {
        $this->poller = $poller;
    }

    /**
     * Runs one batch.
     *
     * @return void
     */
    public function execute(): void
    {
        $this->poller->run();
    }
}
