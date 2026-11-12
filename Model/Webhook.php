<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Model;

use CoinsPay\Payment\Model\Core\ApiError;
use CoinsPay\Payment\Model\Core\Signature;
use CoinsPay\Payment\Model\Core\Util;

/**
 * The webhook receiver. The event only says which invoice changed: its state is fetched from
 * CoinsPay with the store's own key, so a forged or replayed event changes nothing. The signature
 * is checked with the secret of the store view the invoice belongs to (each website may have its
 * own CoinsPay shop); an event without a known invoice uses the store view of the address.
 */
class Webhook
{
    /** @var Repo */
    private $repo;

    /** @var Kits */
    private $kits;

    /** @var OrderStore */
    private $store;

    /**
     * @param Repo $repo
     * @param Kits $kits
     * @param OrderStore $store
     */
    public function __construct(Repo $repo, Kits $kits, OrderStore $store)
    {
        $this->repo = $repo;
        $this->kits = $kits;
        $this->store = $store;
    }

    /**
     * Handles one webhook request.
     *
     * @param string $body Raw request body.
     * @param string $signature Value of x-cpay-signature.
     * @param int $storeId Store view of the webhook address.
     * @return array {0: HTTP status, 1: short answer}
     */
    public function handle(string $body, string $signature, int $storeId): array
    {
        $event = Util::decode($body);
        $invoiceId = self::invoiceId($event);
        $row = $invoiceId !== '' ? $this->repo->find($invoiceId) : null;
        $kit = $this->kits->forStore($row ? (int)$row['store_id'] : $storeId);
        $settings = $kit->settings();

        $secret = $settings->webhookSecret();
        if ($secret === '') {
            return [401, 'webhook secret is not set in the module'];
        }
        if (!Signature::verify($body, $signature, $secret)) {
            $this->store->log('webhook rejected: bad or stale signature', true);
            return [401, 'bad signature'];
        }
        if (empty($event['type'])) {
            return [400, 'not an event'];
        }
        $type = (string)$event['type'];
        if ($type === 'webhook.test') {
            $kit->repo()->kvSet('last_webhook_test', (string)time());
            return [200, 'test event received'];
        }

        // Test and live events share the webhook of the shop; a test key handles only test ones.
        if (!empty($event['test']) !== $settings->isTestMode()) {
            return [200, 'ignored: other mode'];
        }
        if (!$row) {
            return [200, 'ignored: unknown invoice'];
        }

        try {
            $result = $kit->sync()->apply($settings->client()->invoice($invoiceId));
        } catch (ApiError $e) {
            if ($e->isNotFound()) {
                return [200, 'ignored: unknown invoice'];
            }
            $this->store->log(sprintf('webhook %s for invoice %s: %s', $type, $invoiceId, $e->summary()), true);
            return [503, 'CoinsPay API is not available, retry later'];
        } catch (\Exception $e) {
            $this->store->log(sprintf('webhook %s for invoice %s failed: %s', $type, $invoiceId, $e->getMessage()), true);
            return [500, 'processing failed, retry later'];
        }
        return [200, $result];
    }

    /**
     * Invoice id of an event: invoice.* carry data.invoice.id, payment.* carry data.deposit.invoice_id.
     *
     * @param array $event
     * @return string
     */
    public static function invoiceId(array $event): string
    {
        $data = isset($event['data']) && is_array($event['data']) ? $event['data'] : [];
        if (isset($data['invoice']['id']) && is_string($data['invoice']['id'])) {
            return strtolower($data['invoice']['id']);
        }
        if (isset($data['deposit']['invoice_id']) && is_string($data['deposit']['invoice_id'])) {
            return strtolower($data['deposit']['invoice_id']);
        }
        return '';
    }
}
