<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Model\Core;

/**
 * Client of the CoinsPay merchant API.
 */
class ApiClient
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /** @var string API address without the trailing slash, e.g. https://coinspay.app/api/v1 */
    private $base;

    /** @var string cpk_… or cpk_test_… */
    private $key;

    /** @var string */
    private $userAgent;

    /** @var HttpInterface */
    private $http;

    /**
     * @param HttpInterface $http
     * @param string $base
     * @param string $key
     * @param string $userAgent
     */
    public function __construct(HttpInterface $http, string $base, string $key, string $userAgent)
    {
        $this->http = $http;
        $this->base = rtrim(trim($base), '/');
        $this->key = trim($key);
        $this->userAgent = $userAgent;
    }

    /**
     * GET /shop: the shop of the key and the key itself.
     *
     * @return array {shop: {id, name, created_at}, key: {test, access}}
     */
    public function shop(): array
    {
        $data = $this->request('GET', '/shop');
        $this->field($data, 'shop');
        return $data;
    }

    /**
     * GET /currencies: fiat and crypto currencies with their scales.
     *
     * @return array
     */
    public function currencies(): array
    {
        return $this->request('GET', '/currencies');
    }

    /**
     * POST /invoices; the same key and body give the same invoice.
     *
     * @param array $body
     * @param string $idempotencyKey
     * @return array Invoice.
     */
    public function createInvoice(array $body, string $idempotencyKey): array
    {
        return $this->field($this->request('POST', '/invoices', $body, ['Idempotency-Key' => $idempotencyKey]), 'invoice');
    }

    /**
     * GET /invoices/{id}.
     *
     * @param string $id
     * @return array
     */
    public function invoice(string $id): array
    {
        return $this->field($this->request('GET', '/invoices/' . $this->id($id)), 'invoice');
    }

    /**
     * POST /invoices/{id}/cancel: an open invoice can no longer be paid.
     *
     * @param string $id
     * @return array
     */
    public function cancelInvoice(string $id): array
    {
        // An empty JSON object: the API refuses a JSON request without a body.
        return $this->field($this->request('POST', '/invoices/' . $this->id($id) . '/cancel', new \stdClass()), 'invoice');
    }

    /**
     * Checks the invoice id before it goes into the path.
     *
     * @param string $id
     * @return string
     */
    private function id(string $id): string
    {
        if (!preg_match(self::UUID, $id)) {
            throw new ApiError(404, 'NOT_FOUND', 'Not an invoice id');
        }
        return strtolower($id);
    }

    /**
     * Sends a request and decodes the answer.
     *
     * @param string $method
     * @param string $path
     * @param array|\stdClass|null $body
     * @param array $headers
     * @return array
     */
    private function request(string $method, string $path, $body = null, array $headers = []): array
    {
        if ($this->base === '' || $this->key === '') {
            throw new ApiError(0, 'NOT_CONFIGURED', 'The CoinsPay API address or key is not set');
        }
        $headers['Authorization'] = 'Bearer ' . $this->key;
        $headers['Accept'] = 'application/json';
        $raw = null;
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $raw = Util::json($body);
        }
        [$status, $answer] = $this->http->send($method, $this->base . $path, $headers, $raw, $this->userAgent);

        $data = json_decode((string)$answer, true);
        if ($status >= 200 && $status < 300 && is_array($data)) {
            return $data;
        }
        $fault = is_array($data) && isset($data['error']) && is_array($data['error']) ? $data['error'] : [];
        throw new ApiError(
            (int)$status,
            isset($fault['code']) ? (string)$fault['code'] : 'HTTP_' . $status,
            isset($fault['message']) ? (string)$fault['message'] : '',
            isset($fault['details']) && is_array($fault['details']) ? $fault['details'] : []
        );
    }

    /**
     * A required object field of the answer.
     *
     * @param array $data
     * @param string $key
     * @return array
     */
    private function field(array $data, string $key): array
    {
        if (!isset($data[$key]) || !is_array($data[$key])) {
            throw new ApiError(0, 'BAD_RESPONSE', 'The answer has no "' . $key . '"');
        }
        return $data[$key];
    }
}
