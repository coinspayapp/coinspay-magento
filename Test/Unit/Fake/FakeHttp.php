<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Test\Unit\Fake;

use CoinsPay\Payment\Model\Core\HttpInterface;

/**
 * The CoinsPay API in memory: answers by "METHOD path", remembers the requests.
 */
class FakeHttp implements HttpInterface
{
    /** @var array "GET /currencies" → [status, body array] */
    public $answers = [];

    /** @var array [method, url, headers, body] */
    public $requests = [];

    /**
     * @inheritdoc
     */
    public function send(string $method, string $url, array $headers, ?string $body, string $userAgent): array
    {
        $this->requests[] = [$method, $url, $headers, $body];
        $path = (string)preg_replace('#^https?://[^/]+/api/v1#', '', $url);
        $answer = $this->answers[$method . ' ' . $path] ?? [404, ['error' => ['code' => 'NOT_FOUND', 'message' => 'Not found']]];
        return [$answer[0], (string)json_encode($answer[1])];
    }
}
