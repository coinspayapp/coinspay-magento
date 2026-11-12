<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Model\Core;

/**
 * Applies the state of an invoice to its order: lock → fresh order → decision → what the store
 * owner chose for the event (an invoice, a cancel, a hold, a status, or a comment only).
 */
class Sync
{
    /** Polling of an open invoice. */
    public const INTERVAL_OPEN = 300;

    /** Polling of an expired or cancelled invoice: a late payment may still come. */
    public const INTERVAL_CLOSED = 1800;

    /** Events a paid invoice brings that reach the order whoever moved it last. */
    private const ALWAYS = ['mismatch', 'paid_cancelled', 'test_foreign'];

    /** @var StoreInterface */
    private $store;

    /** @var Settings */
    private $settings;

    /** @var RepoInterface */
    private $repo;

    /** @var Currencies */
    private $currencies;

    /** @var Text */
    private $text;

    /**
     * @param StoreInterface $store
     * @param Settings $settings
     * @param RepoInterface $repo
     * @param Currencies $currencies
     * @param Text $text
     */
    public function __construct(
        StoreInterface $store,
        Settings $settings,
        RepoInterface $repo,
        Currencies $currencies,
        Text $text
    ) {
        $this->store = $store;
        $this->settings = $settings;
        $this->repo = $repo;
        $this->currencies = $currencies;
        $this->text = $text;
    }

    /**
     * Applies an invoice from the API to its order.
     *
     * @param array $invoice Invoice from the API.
     * @return string What happened, for the log and the webhook answer.
     * @throws \RuntimeException Another request holds the order too long; the caller retries later.
     */
    public function apply(array $invoice): string
    {
        $row = $this->repo->find(Util::value($invoice, 'id'));
        if (!$row) {
            return 'not an invoice of this store';
        }
        $orderId = (int)$row['order_id'];
        if (!$this->repo->lock($orderId)) {
            throw new \RuntimeException('the order is busy');
        }
        try {
            return $this->applyLocked($orderId, $invoice);
        } finally {
            $this->repo->unlock($orderId);
        }
    }

    /**
     * The order total now, in the invoice currency.
     *
     * The store may have changed it after the invoice was made. Null when the invoice is in neither the
     * order nor the base currency.
     *
     * @param array $order
     * @param string $currency
     * @return string|null
     */
    public function expectedAmount(array $order, string $currency): ?string
    {
        $currency = strtoupper($currency);
        if ($currency === strtoupper((string)$order['currency'])) {
            $total = (float)$order['total'];
        } elseif ($currency === strtoupper((string)$order['base_currency'])) {
            $total = (float)$order['base_total'];
        } else {
            return null;
        }
        try {
            $scale = $this->currencies->scale($currency);
        } catch (ApiError $e) {
            $scale = 2;
        }
        return Util::formatAmount($total, $scale);
    }

    /**
     * Applies the invoice under the lock of the order.
     *
     * @param int $orderId
     * @param array $invoice
     * @return string
     */
    private function applyLocked(int $orderId, array $invoice): string
    {
        $invoiceId = strtolower(Util::value($invoice, 'id'));
        $order = $this->store->order($orderId);
        $row = $this->repo->find($invoiceId);
        if (!$order || !$row) {
            return 'the order is gone';
        }

        $this->repo->saveState($invoice);
        $fingerprint = Decision::fingerprint($invoice);
        if ($row['fingerprint'] === $fingerprint) {
            $this->settle($invoiceId, $invoice);
            return 'no change';
        }

        $current = $this->repo->current($orderId);
        $paidInvoice = $this->repo->paidInvoice($orderId);
        $pluginStatus = $this->repo->pluginStatus($orderId);
        $decision = Decision::decide(
            $invoice,
            [
                'is_paid' => $paidInvoice !== '',
                'paid_invoice' => $paidInvoice,
                // Cancelled, and not by this module on an expired or cancelled invoice: the store did it
                // (the staff, or Magento's own cron for old pending payment orders).
                'is_store_cancelled' => $order['state'] === 'canceled'
                    && !in_array($this->repo->pluginEvent($orderId), ['expired', 'cancelled'], true),
                'expected_amount' => $this->expectedAmount($order, Util::value($invoice, 'currency')),
                'is_current' => $current && $current['invoice_id'] === $invoiceId,
                'test_allowed' => !empty($row['test_buyer']),
            ],
            $this->text
        );

        $event = $decision['event'];
        $action = $this->applyEvent($order, $row, $event, $decision['note'], $invoice, $pluginStatus);
        // Only after the order took the event: if writing it failed, the next webhook or poll must
        // decide again, not see "no change" and lose the payment.
        $this->repo->setFingerprint($invoiceId, $fingerprint);

        $this->settle($invoiceId, $invoice);
        $this->store->log(
            sprintf('order %d, invoice %s (%s): %s → %s', $orderId, $invoiceId, Util::value($invoice, 'status'), $event, $action),
            false
        );
        return $event . ': ' . $action;
    }

    /**
     * Writes the event to the order: what the store owner chose for it, or a comment only.
     *
     * @param array $order
     * @param array $row
     * @param string $event
     * @param string $note
     * @param array $invoice
     * @param string $pluginStatus
     * @return string
     */
    private function applyEvent(
        array $order,
        array $row,
        string $event,
        string $note,
        array $invoice,
        string $pluginStatus
    ): string {
        if ($event === Decision::NONE) {
            return 'nothing';
        }
        $orderId = (int)$order['id'];
        $statusNow = (string)$order['status'];
        $comment = $this->comment($note, !empty($invoice['test']));
        $hasMoney = Util::positive(Util::value($invoice, 'amount_paid'))
            || Util::positive(Util::value($invoice, 'amount_pending'));

        // The store switched the order to another payment method: it is not ours any more, only
        // money that came by CoinsPay is noted.
        if (empty($order['ours'])) {
            if (!$hasMoney) {
                return 'other payment method';
            }
            $this->store->comment($orderId, $comment . ' ' . $this->text->t('note_other_method'));
            return 'comment (other payment method)';
        }

        if ($event === Decision::COMMENT) {
            $this->store->comment($orderId, $comment);
            return 'comment';
        }

        $target = $this->settings->statusFor($event);
        if (in_array($event, Decision::PAID, true)) {
            return $this->pay($orderId, (string)$row['invoice_id'], $event, $target, $comment);
        }

        if ($target === null) {
            $this->store->log(
                sprintf('order %d: the order status for "%s" is not set or was deleted; only a comment is written', $orderId, $event),
                true
            );
            $this->repo->kvSet('status_problem', $event);
            $this->store->comment($orderId, $comment . ' ' . $this->text->t('note_status_missing'));
            return 'comment (status not set)';
        }

        // Disputes of a paid invoice always reach the order. Money on its way, a part or an expiry
        // change only an order the module still holds: the store has not moved it since.
        $held = $pluginStatus === '' || $statusNow === $pluginStatus;
        if ($target === Settings::NO_CHANGE || $target === $statusNow || (!in_array($event, self::ALWAYS, true) && !$held)) {
            $this->store->comment($orderId, $comment);
            return 'comment';
        }

        $state = Settings::STATES[$event];
        if ($state === 'canceled') {
            $after = $this->store->cancel($orderId, $target, $comment);
        } elseif ($state === 'holded') {
            $after = $this->store->hold($orderId, $target, $comment);
        } else {
            $after = $this->store->setStatus($orderId, $target, $comment);
        }
        if ($after === null) {
            $this->store->comment($orderId, $comment . ' ' . $this->text->t('note_not_moved'));
            return 'comment (the order cannot take the status)';
        }
        $this->repo->setPluginStatus($orderId, $after, $event);
        return 'status ' . $after;
    }

    /**
     * Records a payment: a paid Magento invoice.
     *
     * A cancelled order cannot be invoiced (Magento does not reopen it): it is put to the status of
     * "paid order that the store cancelled" with an explanation.
     *
     * @param int $orderId
     * @param string $invoiceId
     * @param string $event
     * @param string|null $target
     * @param string $comment
     * @return string
     */
    private function pay(int $orderId, string $invoiceId, string $event, ?string $target, string $comment): string
    {
        if ($target === null) {
            $this->store->log(sprintf('order %d: the order status for "%s" is not set; the default one is used', $orderId, $event), true);
            $this->repo->kvSet('status_problem', $event);
        }
        $after = $this->store->pay($orderId, $invoiceId, $target ?? '', $comment);
        if ($after !== null) {
            // Marked only once the order has it: marked before, a failed write would leave the order unpaid.
            $this->repo->markPaid($invoiceId);
            $this->repo->setPluginStatus($orderId, $after, $event);
            return 'invoiced, status ' . $after;
        }

        $note = $comment . ' ' . $this->text->t('note_not_invoiced');
        $review = $this->settings->statusFor('paid_cancelled');
        $after = $review !== null && $review !== Settings::NO_CHANGE ? $this->store->hold($orderId, $review, $note) : null;
        if ($after === null) {
            $this->store->comment($orderId, $note);
        } else {
            $this->repo->setPluginStatus($orderId, $after, 'paid_cancelled');
        }
        $this->repo->markPaid($invoiceId);
        return 'not invoiced: the order cannot be invoiced';
    }

    /**
     * Polling of the invoice after this state.
     *
     * Open — every 5 minutes, paid — never, expired or cancelled — every 30 minutes until the end of the
     * polling time (a late payment may come).
     *
     * @param string $invoiceId
     * @param array $invoice
     * @return void
     */
    private function settle(string $invoiceId, array $invoice): void
    {
        $status = Util::value($invoice, 'status');
        if ($status === 'open') {
            $this->repo->setNextPoll($invoiceId, time() + self::INTERVAL_OPEN);
        } elseif ($status === 'paid') {
            $this->repo->setNextPoll($invoiceId, null);
        } else {
            $this->repo->setNextPoll($invoiceId, time() + self::INTERVAL_CLOSED);
        }
    }

    /**
     * Order comments start with "CoinsPay" (or "CoinsPay (test)"), so the staff tell them from other ones.
     *
     * @param string $note
     * @param bool $test
     * @return string
     */
    private function comment(string $note, bool $test): string
    {
        return $this->text->t($test ? 'comment_prefix_test' : 'comment_prefix') . ' ' . $note;
    }
}
