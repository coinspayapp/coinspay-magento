<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Model\Core;

/**
 * Texts of the core (order notes, buyer messages, the connection check) by key. The phrases are
 * English with Magento placeholders (%1, %2); the translator turns them into the language of the
 * store through the module dictionaries (i18n/*.csv). Without one, the placeholders are filled in.
 */
class Text
{
    /** @var array key → English phrase */
    public const PHRASES = [
        // Order notes. Each starts with the prefix, so the staff tell them from other comments.
        'comment_prefix' => 'CoinsPay:',
        'comment_prefix_test' => 'CoinsPay (test):',
        'note_created' => 'invoice No. %1 created for %2. Waiting for the payment.',
        'note_paid' => 'invoice No. %1 is paid: %2.',
        'note_overpaid' => 'Overpaid by %1.',
        'note_paid_late' => 'The payment came after the invoice expired.',
        'note_paid_manually' => 'The invoice was marked as paid manually in the CoinsPay dashboard.',
        'note_second_paid' => 'invoice No. %1 of this order is paid as well, although the order was already paid.'
            . ' Refund the buyer from the CoinsPay dashboard if needed.',
        'note_paid_cancelled' => 'invoice No. %1 is paid (%2), but the order was cancelled in the store.'
            . ' Check the price and the stock, then ship the order or refund the buyer from the CoinsPay dashboard.',
        'note_test_foreign' => 'invoice No. %1 got a test payment: no money was received.'
            . ' The order was not placed by the test buyer.'
            . ' If the store takes real orders, replace the test API key with a live one.',
        'note_mismatch' => 'invoice No. %1 is paid, but its amount %2 differs from the order (%3).'
            . ' Check the order before shipping.',
        'note_rest_pending' => 'invoice No. %1: %2 received, the rest is on its way and waits for network'
            . ' confirmations (%3).',
        'note_partial' => 'invoice No. %1 is partially paid: %2 received, %3 left.'
            . ' The buyer can pay the rest on the same payment page.',
        'note_pending' => 'invoice No. %1: the payment is on its way and waits for network confirmations (%2).',
        'note_expired_partial' => 'invoice No. %1 expired partially paid: %2 of %3.'
            . ' Decide whether to ship or refund the buyer from the CoinsPay dashboard.',
        'note_cancelled_partial' => 'invoice No. %1 was cancelled partially paid: %2 of %3.'
            . ' Decide whether to ship or refund the buyer from the CoinsPay dashboard.',
        'note_cancelled' => 'invoice No. %1 was cancelled.',
        'note_expired' => 'invoice No. %1 expired unpaid.',
        'note_no_payments' => 'no payment details',
        'note_other_method' => 'The order uses another payment method now, so its status is left as it is.',
        'note_status_missing' => 'The order status for this event is not set in the CoinsPay settings,'
            . ' so the status is left as it is.',
        'note_not_invoiced' => 'Magento cannot invoice the order in its current state (a cancelled order is not'
            . ' reopened), so the payment is recorded in this comment only. Check the stock, then ship the order'
            . ' or refund the buyer from the CoinsPay dashboard.',
        'note_not_moved' => 'Magento cannot move the order to the status of this event in its current state,'
            . ' so the status is left as it is.',

        // Messages to the buyer.
        'error_currency' => 'Crypto payment is not available for this currency. Please choose another payment method.',
        'error_unavailable' => 'The crypto payment service is not answering right now. Please try again in a minute.',
        'error_failed' => 'The crypto payment could not be started. Please try again or choose another payment method.',

        // "Check connection" on the settings page.
        'check_enter' => 'Enter the API key first.',
        'check_connected' => 'Connected to the shop "%1", %2.',
        'check_test_mode' => 'test mode',
        'check_live_mode' => 'live mode',
        'check_full_access' => 'This key has full access, payouts included, while the module needs invoices only.'
            . ' Issue an "Invoices only" key in the CoinsPay dashboard, put it here and revoke this one.',
        'check_currency_ok' => 'Prices in %1 are accepted.',
        'check_currency_no' => 'CoinsPay does not accept prices in %1, the base currency of the website:'
            . ' the payment method shows only for currencies it accepts.',
        'check_bad_key' => 'CoinsPay does not know this key. Copy it from the dashboard again:'
            . ' the full key is shown only once, when it is issued.',
        'check_ip' => 'The key has an IP allowlist without this server. Add %1 to the key in the dashboard.',
        'check_blocked' => 'The shop is blocked in CoinsPay.',
        'check_network' => 'No answer from the API address: %1',
        'check_http' => 'CoinsPay answered with an error: HTTP %1, %2. Check the API address: it ends with /api/v1.',
    ];

    /** @var callable|null fn(string $phrase, array $args): string */
    private $translator;

    /**
     * @param callable|null $translator Magento's __() in the shop; none in tests.
     */
    public function __construct(?callable $translator = null)
    {
        $this->translator = $translator;
    }

    /**
     * Text by key with arguments for %1, %2…
     *
     * @param string $key
     * @param mixed $args Values for %1, %2…
     * @return string
     */
    public function t(string $key, ...$args): string
    {
        $phrase = self::PHRASES[$key] ?? $key;
        if ($this->translator !== null) {
            return (string)($this->translator)($phrase, $args);
        }
        return self::fill($phrase, $args);
    }

    /**
     * Puts the arguments in place of %1, %2…, from the last one so %1 does not eat %10.
     *
     * @param string $phrase
     * @param array $args
     * @return string
     */
    public static function fill(string $phrase, array $args): string
    {
        $pairs = [];
        foreach (array_values($args) as $i => $arg) {
            $pairs['%' . ($i + 1)] = (string)$arg;
        }
        krsort($pairs, SORT_NATURAL);
        return strtr($phrase, $pairs);
    }
}
