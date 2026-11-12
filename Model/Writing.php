<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Model;

/**
 * Whether the module itself is changing an order right now: its own cancel must not cancel the
 * invoice again through the order observer. Shared within the request.
 */
class Writing
{
    /** @var int */
    private $depth = 0;

    /**
     * Runs a change of an order made by the module.
     *
     * @param callable $change
     * @return mixed
     */
    public function run(callable $change)
    {
        $this->depth++;
        try {
            return $change();
        } finally {
            $this->depth--;
        }
    }

    /**
     * Whether the module is changing an order.
     *
     * @return bool
     */
    public function active(): bool
    {
        return $this->depth > 0;
    }
}
