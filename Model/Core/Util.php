<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Model\Core;

/**
 * Small helpers of the CoinsPay core. The core makes no Magento calls: it runs in unit tests.
 */
class Util
{
    /**
     * Decimal string without trailing zeros: 49.90 → 49.9, 10.00 → 10.
     *
     * @param mixed $amount
     * @return string
     */
    public static function normalize($amount): string
    {
        $amount = trim((string)$amount);
        if (strpos($amount, '.') !== false) {
            $amount = rtrim(rtrim($amount, '0'), '.');
        }
        return $amount === '' ? '0' : $amount;
    }

    /**
     * Amount as the API takes it: at most $scale decimals, no trailing zeros.
     *
     * @param float|string $total
     * @param int $scale
     * @return string
     */
    public static function formatAmount($total, int $scale): string
    {
        return self::normalize(number_format((float)$total, max(0, $scale), '.', ''));
    }

    /**
     * Whether a decimal string is above zero.
     *
     * @param mixed $amount
     * @return bool
     */
    public static function positive($amount): bool
    {
        return (string)$amount !== '' && (bool)preg_match('/[1-9]/', (string)$amount);
    }

    /**
     * A scalar field of an array as a string.
     *
     * @param mixed $data
     * @param string $field
     * @return string
     */
    public static function value($data, string $field): string
    {
        return is_array($data) && isset($data[$field]) && is_scalar($data[$field]) ? (string)$data[$field] : '';
    }

    /**
     * Cuts UTF-8 text to a number of characters, with an ellipsis.
     *
     * @param string $text
     * @param int $max
     * @return string
     */
    public static function truncate(string $text, int $max): string
    {
        if (mb_strlen($text, 'UTF-8') <= $max) {
            return $text;
        }
        return rtrim(mb_substr($text, 0, $max - 1, 'UTF-8')) . "\u{2026}";
    }

    /**
     * Random hex string.
     *
     * @param int $bytes
     * @return string
     */
    public static function randomHex(int $bytes): string
    {
        return bin2hex(random_bytes($bytes));
    }

    /**
     * JSON for the API and the invoice table.
     *
     * @param mixed $data
     * @return string
     */
    public static function json($data): string
    {
        return (string)json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * An array from JSON; an empty one when it is not an object or a list.
     *
     * @param mixed $json
     * @return array
     */
    public static function decode($json): array
    {
        $data = json_decode((string)$json, true);
        return is_array($data) ? $data : [];
    }
}
