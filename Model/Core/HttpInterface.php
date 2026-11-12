<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Model\Core;

/**
 * The HTTP transport of the API client: Magento's cURL client in the shop, a fake in tests.
 */
interface HttpInterface
{
    /**
     * Sends a request without following redirects (a redirect would resend the key elsewhere).
     *
     * @param string $method
     * @param string $url
     * @param array $headers name → value
     * @param string|null $body
     * @param string $userAgent
     * @return array {0: HTTP status, 1: raw body}
     * @throws ApiError NETWORK when no answer came.
     */
    public function send(string $method, string $url, array $headers, ?string $body, string $userAgent): array;
}
