<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Model\Core;

/**
 * The invoice of an order when the buyer goes to pay it: right after the order is placed, or again
 * from the order page while it is unpaid.
 */
class Payment
{
    /** After the invoice expires, late payments are looked for during a day. */
    public const POLL_AFTER_EXPIRY = 86400;

    /** @var StoreInterface */
    private $store;

    /** @var Settings */
    private $settings;

    /** @var RepoInterface */
    private $repo;

    /** @var Currencies */
    private $currencies;

    /** @var Sync */
    private $sync;

    /** @var Tokens */
    private $tokens;

    /** @var Text */
    private $text;

    /**
     * @param StoreInterface $store
     * @param Settings $settings
     * @param RepoInterface $repo
     * @param Currencies $currencies
     * @param Sync $sync
     * @param Tokens $tokens
     * @param Text $text
     */
    public function __construct(
        StoreInterface $store,
        Settings $settings,
        RepoInterface $repo,
        Currencies $currencies,
        Sync $sync,
        Tokens $tokens,
        Text $text
    ) {
        $this->store = $store;
        $this->settings = $settings;
        $this->repo = $repo;
        $this->currencies = $currencies;
        $this->sync = $sync;
        $this->tokens = $tokens;
        $this->text = $text;
    }

    /**
     * Invoice to send the buyer to: the open one of the same amount, or a new one.
     *
     * @param int $orderId
     * @return array Invoice: open, or paid when the order is already paid.
     * @throws ApiError
     */
    public function invoiceFor(int $orderId): array
    {
        $order = $this->store->order($orderId);
        if (!$order) {
            throw new ApiError(404, 'ORDER_NOT_FOUND', 'No order ' . $orderId);
        }
        $currency = $this->currencyFor($order);
        $body = InvoiceRequest::body(
            [
                'currency' => $currency,
                'amount' => $this->sync->expectedAmount($order, $currency),
                'number' => (string)$order['number'],
                'items' => $order['items'],
                'return_url' => $this->store->returnUrl($orderId, $this->tokens->orderToken($orderId)),
            ],
            [
                'lifetime' => $this->settings->lifetime(),
                'stablecoins_only' => $this->settings->flag('stablecoins_only'),
                'buyer_pays_fee' => $this->settings->flag('buyer_pays_fee'),
                'buyer_pays_network' => $this->settings->flag('buyer_pays_network'),
                'auto_return' => $this->settings->flag('auto_return'),
            ]
        );
        $client = $this->settings->client();

        $rows = $this->repo->forOrder($orderId);
        $current = $rows[0] ?? null;
        if ($current
            && $current['amount'] === $body['amount']
            && $current['currency'] === $body['currency']
            && (bool)$current['test'] === $this->settings->isTestMode()
        ) {
            try {
                $invoice = $client->invoice((string)$current['invoice_id']);
                if (in_array(Util::value($invoice, 'status'), ['open', 'paid'], true)) {
                    $this->sync->apply($invoice);
                    return $invoice;
                }
            } catch (ApiError $e) {
                if (!$e->isNotFound()) {
                    throw $e;
                }
            }
        }

        // The same attempt and body give the same key: a repeated click gets the same invoice.
        $attempt = count($rows) + 1;
        $key = InvoiceRequest::idempotencyKey($this->store->siteUrl(), $orderId, $attempt, $body);
        $invoice = $client->createInvoice($body, $key);
        if (!$this->repo->find(Util::value($invoice, 'id'))) {
            $pollUntil = time() + $this->settings->lifetime() * 60 + self::POLL_AFTER_EXPIRY;
            $this->repo->add($orderId, (int)$order['store_id'], $invoice, $attempt, !empty($order['test_buyer']), $pollUntil);
        }

        $note = $this->text->t(empty($invoice['test']) ? 'comment_prefix' : 'comment_prefix_test') . ' '
            . $this->text->t(
                'note_created',
                Util::value($invoice, 'number'),
                Util::value($invoice, 'amount') . ' ' . Util::value($invoice, 'currency')
            );
        $statusNow = $this->store->comment($orderId, $note);
        if (!$rows) {
            // The order got its first status on placement: the module holds it until the store moves it.
            $this->repo->setPluginStatus($orderId, $statusNow, 'created');
        }

        if ($current && $current['invoice_id'] !== strtolower(Util::value($invoice, 'id'))) {
            $this->cancelReplaced($client, $current);
        }
        $this->store->log(sprintf('order %d: invoice %s created', $orderId, Util::value($invoice, 'id')), false);
        return $invoice;
    }

    /**
     * Currency of the invoice: the order's when CoinsPay takes it, else the base currency.
     *
     * @param array $order
     * @return string
     * @throws ApiError CURRENCY_NOT_SUPPORTED when it takes neither.
     */
    public function currencyFor(array $order): string
    {
        foreach ([(string)$order['currency'], (string)$order['base_currency']] as $code) {
            try {
                $this->currencies->scale($code);
                return strtoupper($code);
            } catch (ApiError $e) {
                if ($e->errorCode() !== 'CURRENCY_NOT_SUPPORTED') {
                    throw $e;
                }
            }
        }
        throw new ApiError(400, 'CURRENCY_NOT_SUPPORTED', 'CoinsPay does not accept prices in ' . $order['currency']);
    }

    /**
     * What to tell the buyer when the invoice cannot be created.
     *
     * @param ApiError $error
     * @param Text $text
     * @return string
     */
    public static function errorMessage(ApiError $error, Text $text): string
    {
        switch ($error->errorCode()) {
            case 'CURRENCY_NOT_SUPPORTED':
                return $text->t('error_currency');
            case 'QUOTE_UNAVAILABLE':
            case 'NETWORK':
            case 'RATE_LIMITED':
                return $text->t('error_unavailable');
            default:
                return $text->t('error_failed');
        }
    }

    /**
     * Cancels the invoice a new one replaced (the order total, currency or mode changed), so the buyer
     * cannot pay the old amount. Only an open invoice without money is cancelled; a paid one is still
     * checked against the order total when it arrives.
     *
     * @param ApiClient $client
     * @param array $row
     * @return void
     */
    private function cancelReplaced(ApiClient $client, array $row): void
    {
        $snapshot = Util::decode($row['snapshot'] ?? '');
        if ($row['status'] !== 'open' || !$snapshot
            || Util::positive(Util::value($snapshot, 'amount_paid'))
            || Util::positive(Util::value($snapshot, 'amount_pending'))
        ) {
            return;
        }
        try {
            $client->cancelInvoice((string)$row['invoice_id']);
            $this->store->log(sprintf('invoice %s replaced and cancelled', $row['invoice_id']), false);
        } catch (ApiError $e) {
            $this->store->log(sprintf('replaced invoice %s was not cancelled: %s', $row['invoice_id'], $e->summary()), false);
        }
    }
}
