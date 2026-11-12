<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Model\Core;

/**
 * Invoice state → event of the order. A pure function on plain arrays (unit-tested); what an event
 * does to the order is the store owner's choice (Settings::statusFor).
 *
 * The rules are those of the WooCommerce, OpenCart and PrestaShop modules, with the fixes of their
 * security review: a paid invoice is checked against the order total now, money for an order the
 * store cancelled does not ship it, a test payment completes only orders of the test buyer.
 */
class Decision
{
    /** Nothing to do. */
    public const NONE = 'none';

    /** A comment only, whatever the settings: a second invoice of a paid order was paid. */
    public const COMMENT = 'comment';

    /** Events that pay the order. */
    public const PAID = ['paid', 'paid_late', 'paid_over', 'paid_manual'];

    /** Events that ask the store to decide. */
    public const REVIEW = ['mismatch', 'paid_cancelled', 'test_foreign', 'expired_partial', 'cancelled_partial'];

    /**
     * What the invoice means for the order.
     *
     * @param array $invoice Invoice from the API.
     * @param array $order {is_paid, paid_invoice, is_store_cancelled, expected_amount: the order total now in the
     *                     invoice currency, or null when the currency is not the order's, is_current, test_allowed}.
     * @param Text $text
     * @return array {event, note, final}: final — the invoice will not change any more except by a late payment.
     */
    public static function decide(array $invoice, array $order, Text $text): array
    {
        $status = Util::value($invoice, 'status');
        $paid = Util::positive(Util::value($invoice, 'amount_paid'));
        $pending = Util::positive(Util::value($invoice, 'amount_pending'));
        $number = Util::value($invoice, 'number');

        if ($status === 'paid') {
            if (!empty($order['is_paid'])) {
                if (strtolower(Util::value($invoice, 'id')) !== strtolower((string)$order['paid_invoice'])) {
                    return self::result(self::COMMENT, $text->t('note_second_paid', $number), true);
                }
                return self::result(self::NONE, '', true);
            }

            // Money that came after the store cancelled the order does not ship it: the store decides.
            if (!empty($order['is_store_cancelled'])) {
                $note = $text->t('note_paid_cancelled', $number, self::payments($invoice, $text));
                return self::result('paid_cancelled', $note, true);
            }

            // A test payment moves no money: it completes only orders of the test buyer.
            if (!empty($invoice['test']) && empty($order['test_allowed'])) {
                return self::result('test_foreign', $text->t('note_test_foreign', $number), true);
            }

            $amount = Util::normalize(Util::value($invoice, 'amount'));
            $currency = strtoupper(Util::value($invoice, 'currency'));
            if ($order['expected_amount'] === null || (string)$order['expected_amount'] !== $amount) {
                $expected = $order['expected_amount'] === null ? '—' : $order['expected_amount'] . ' ' . $currency;
                $note = $text->t('note_mismatch', $number, $amount . ' ' . $currency, $expected);
                return self::result('mismatch', $note, true);
            }

            $note = $text->t('note_paid', $number, self::payments($invoice, $text));
            $event = 'paid';
            if (Util::positive(Util::value($invoice, 'amount_overpaid'))) {
                $note .= ' ' . $text->t('note_overpaid', self::money($invoice, 'amount_overpaid'));
                $event = 'paid_over';
            }
            if (!empty($invoice['paid_late'])) {
                $note .= ' ' . $text->t('note_paid_late');
                $event = 'paid_late';
            }
            if (!empty($invoice['paid_manually'])) {
                $note .= ' ' . $text->t('note_paid_manually');
                $event = 'paid_manual';
            }
            return self::result($event, $note, true);
        }

        if (!empty($order['is_paid'])) {
            return self::result(self::NONE, '', $status !== 'open');
        }

        if ($status === 'open') {
            // Part is paid and the rest is on its way: nothing is left to pay.
            if ($paid && $pending && !Util::positive(Util::value($invoice, 'amount_due'))) {
                $note = $text->t(
                    'note_rest_pending',
                    $number,
                    self::money($invoice, 'amount_paid'),
                    self::payments($invoice, $text)
                );
                return self::result('pending', $note, false);
            }
            if ($paid) {
                $note = $text->t(
                    'note_partial',
                    $number,
                    self::money($invoice, 'amount_paid'),
                    self::money($invoice, 'amount_due')
                );
                return self::result('partial', $note, false);
            }
            if ($pending) {
                return self::result('pending', $text->t('note_pending', $number, self::payments($invoice, $text)), false);
            }
            return self::result(self::NONE, '', false);
        }

        if ($status === 'expired' || $status === 'cancelled') {
            if ($paid || $pending) {
                $received = self::money($invoice, $paid ? 'amount_paid' : 'amount_pending');
                $total = self::money($invoice, 'amount');
                if ($status === 'expired') {
                    return self::result('expired_partial', $text->t('note_expired_partial', $number, $received, $total), true);
                }
                return self::result('cancelled_partial', $text->t('note_cancelled_partial', $number, $received, $total), true);
            }
            // An older invoice of the order ran out: the buyer is paying a newer one.
            if (empty($order['is_current'])) {
                return self::result(self::NONE, '', true);
            }
            if ($status === 'cancelled') {
                return self::result('cancelled', $text->t('note_cancelled', $number), true);
            }
            return self::result('expired', $text->t('note_expired', $number), true);
        }

        return self::result(self::NONE, '', false);
    }

    /**
     * Payments of the invoice in one line: "12.5 USDT, TRON, tx …; 0.001 BTC, Bitcoin".
     *
     * @param array $invoice
     * @param Text $text
     * @return string
     */
    public static function payments(array $invoice, Text $text): string
    {
        $lines = [];
        foreach (self::list($invoice, 'payments') as $payment) {
            $line = trim(Util::value($payment, 'amount') . ' ' . Util::value($payment, 'asset'));
            if (Util::value($payment, 'chain') !== '') {
                $line .= ', ' . self::chainName(Util::value($payment, 'chain'));
            }
            if (Util::value($payment, 'tx_hash') !== '') {
                $line .= ', tx ' . Util::value($payment, 'tx_hash');
            }
            $lines[] = $line;
        }
        return $lines ? implode('; ', $lines) : $text->t('note_no_payments');
    }

    /**
     * Name of a network for people.
     *
     * @param string $chain
     * @return string
     */
    public static function chainName(string $chain): string
    {
        $names = [
            'ethereum' => 'Ethereum',
            'bsc' => 'BNB Smart Chain',
            'tron' => 'TRON',
            'bitcoin' => 'Bitcoin',
            'litecoin' => 'Litecoin',
            'solana' => 'Solana',
        ];
        return $names[$chain] ?? $chain;
    }

    /**
     * What of the invoice matters for the order: the same fingerprint means nothing new.
     *
     * @param array $invoice
     * @return string
     */
    public static function fingerprint(array $invoice): string
    {
        $payments = [];
        foreach (self::list($invoice, 'payments') as $payment) {
            $payments[] = Util::value($payment, 'deposit_id') . ':' . Util::value($payment, 'status');
        }
        return substr(hash('sha256', implode('|', [
            Util::value($invoice, 'status'),
            Util::normalize(Util::value($invoice, 'amount_paid')),
            Util::normalize(Util::value($invoice, 'amount_pending')),
            empty($invoice['paid_late']) ? '0' : '1',
            empty($invoice['paid_manually']) ? '0' : '1',
            implode(',', $payments),
        ])), 0, 32);
    }

    /**
     * The part of the invoice kept with the order: shown on the order page in the admin.
     *
     * @param array $invoice
     * @return array
     */
    public static function snapshot(array $invoice): array
    {
        $copy = [];
        $fields = [
            'id', 'number', 'status', 'test', 'currency', 'amount', 'amount_paid', 'amount_pending', 'amount_due',
            'amount_overpaid', 'pay_url', 'expires_at', 'paid_late', 'paid_manually',
        ];
        foreach ($fields as $field) {
            $copy[$field] = $invoice[$field] ?? null;
        }
        $copy['payments'] = [];
        foreach (self::list($invoice, 'payments') as $payment) {
            $copy['payments'][] = [
                'chain' => Util::value($payment, 'chain'),
                'asset' => Util::value($payment, 'asset'),
                'amount' => Util::value($payment, 'amount'),
                'status' => Util::value($payment, 'status'),
                'tx_hash' => Util::value($payment, 'tx_hash'),
            ];
        }
        return $copy;
    }

    /**
     * A list field of the invoice.
     *
     * @param array $invoice
     * @param string $field
     * @return array
     */
    private static function list(array $invoice, string $field): array
    {
        return isset($invoice[$field]) && is_array($invoice[$field]) ? $invoice[$field] : [];
    }

    /**
     * Amount with the currency.
     *
     * @param array $invoice
     * @param string $field
     * @return string
     */
    private static function money(array $invoice, string $field): string
    {
        return Util::normalize(Util::value($invoice, $field)) . ' ' . Util::value($invoice, 'currency');
    }

    /**
     * Result of a decision.
     *
     * @param string $event
     * @param string $note
     * @param bool $final
     * @return array
     */
    private static function result(string $event, string $note, bool $final): array
    {
        return ['event' => $event, 'note' => $note, 'final' => $final];
    }
}
