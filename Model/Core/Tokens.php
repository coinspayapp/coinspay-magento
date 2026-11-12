<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Model\Core;

/**
 * Signed values of the module's own links, keyed by the secret made on install.
 */
class Tokens
{
    /** A test buyer link works for a day. */
    public const TEST_BUYER_TTL = 86400;

    /** @var string */
    private $secret;

    /**
     * @param string $secret
     */
    public function __construct(string $secret)
    {
        $this->secret = $secret;
    }

    /**
     * Token of the links of an order (return, pay again): nobody else can make the module show or pay that order.
     *
     * @param int $orderId
     * @return string
     */
    public function orderToken(int $orderId): string
    {
        return $this->sign('order|' . $orderId);
    }

    /**
     * Whether the token belongs to the order.
     *
     * @param int $orderId
     * @param string $token
     * @return bool
     */
    public function checkOrder(int $orderId, string $token): bool
    {
        return $this->secret !== '' && hash_equals($this->orderToken($orderId), $token);
    }

    /**
     * Value of a test buyer link: "<expiry>.<signature>".
     *
     * @param int|null $now
     * @return string
     */
    public function testBuyer(?int $now = null): string
    {
        $until = ($now ?? time()) + self::TEST_BUYER_TTL;
        return $until . '.' . $this->sign('test|' . $until);
    }

    /**
     * Whether a test buyer value is genuine and not expired.
     *
     * @param string $value
     * @param int|null $now
     * @return bool
     */
    public function checkTestBuyer(string $value, ?int $now = null): bool
    {
        $parts = explode('.', $value, 2);
        if ($this->secret === '' || count($parts) !== 2 || !ctype_digit($parts[0])) {
            return false;
        }
        if ((int)$parts[0] < ($now ?? time())) {
            return false;
        }
        return hash_equals($this->sign('test|' . $parts[0]), $parts[1]);
    }

    /**
     * HMAC of a value with the secret.
     *
     * @param string $data
     * @return string
     */
    private function sign(string $data): string
    {
        return substr(hash_hmac('sha256', $data, $this->secret), 0, 32);
    }
}
