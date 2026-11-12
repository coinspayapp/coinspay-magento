<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Model;

use CoinsPay\Payment\Model\Core\ApiError;
use CoinsPay\Payment\Model\Core\HttpInterface;
use Magento\Framework\HTTP\Client\CurlFactory;

/**
 * The transport of the API client on Magento's cURL client.
 */
class Http implements HttpInterface
{
    private const TIMEOUT = 15;
    private const CONNECT_TIMEOUT = 10;

    /** @var CurlFactory */
    private $curlFactory;

    /**
     * @param CurlFactory $curlFactory
     */
    public function __construct(CurlFactory $curlFactory)
    {
        $this->curlFactory = $curlFactory;
    }

    /**
     * @inheritdoc
     */
    public function send(string $method, string $url, array $headers, ?string $body, string $userAgent): array
    {
        $client = $this->curlFactory->create();
        $client->setTimeout(self::TIMEOUT);
        $client->setOptions([
            // A redirect would resend the key to another address.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_USERAGENT => $userAgent,
        ]);
        $client->setHeaders($headers);
        try {
            if ($method === 'GET') {
                $client->get($url);
            } else {
                $client->post($url, (string)$body);
            }
        } catch (\Exception $e) {
            throw new ApiError(0, 'NETWORK', $e->getMessage());
        }
        return [(int)$client->getStatus(), (string)$client->getBody()];
    }
}
