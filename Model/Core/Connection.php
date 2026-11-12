<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Model\Core;

/**
 * "Check connection" on the settings page: the shop of the key, its mode and access, and whether
 * CoinsPay takes prices in the base currency.
 */
class Connection
{
    /**
     * Checks a key against the API.
     *
     * @param ApiClient $client
     * @param string $apiKey
     * @param string $baseCurrency
     * @param Text $text
     * @return array {ok: bool, lines: [{text, ok}]}
     */
    public static function check(ApiClient $client, string $apiKey, string $baseCurrency, Text $text): array
    {
        if (trim($apiKey) === '') {
            return ['ok' => false, 'lines' => [self::line($text->t('check_enter'), false)]];
        }
        try {
            $answer = $client->shop();
        } catch (ApiError $e) {
            return ['ok' => false, 'lines' => [self::line(self::error($e, $text), false)]];
        }
        $mode = $text->t(strpos(trim($apiKey), 'cpk_test_') === 0 ? 'check_test_mode' : 'check_live_mode');
        $lines = [self::line($text->t('check_connected', Util::value($answer['shop'], 'name'), $mode), true)];

        // A full-access key can withdraw the shop's money, and the settings page is open to every
        // admin user with access to it.
        $fullAccess = isset($answer['key']['access']) && $answer['key']['access'] === 'full';
        if ($fullAccess) {
            $lines[] = self::line($text->t('check_full_access'), false);
        }

        try {
            $data = $client->currencies();
            $codes = [];
            foreach (isset($data['fiat']) && is_array($data['fiat']) ? $data['fiat'] : [] as $currency) {
                if (isset($currency['code'])) {
                    $codes[] = strtoupper((string)$currency['code']);
                }
            }
            $base = strtoupper($baseCurrency);
            $accepted = in_array($base, $codes, true);
            $lines[] = self::line($text->t($accepted ? 'check_currency_ok' : 'check_currency_no', $base), $accepted);
        } catch (ApiError $e) {
            $lines[] = self::line(self::error($e, $text), false);
        }
        return ['ok' => !$fullAccess, 'lines' => $lines];
    }

    /**
     * What an API error means for the store owner.
     *
     * @param ApiError $e
     * @param Text $text
     * @return string
     */
    public static function error(ApiError $e, Text $text): string
    {
        switch ($e->errorCode()) {
            case 'UNAUTHORIZED':
                return $text->t('check_bad_key');
            case 'IP_NOT_ALLOWED':
                $details = $e->details();
                return $text->t('check_ip', isset($details['ip']) ? (string)$details['ip'] : '?');
            case 'SHOP_DISABLED':
                return $text->t('check_blocked');
            case 'NETWORK':
                return $text->t('check_network', $e->getMessage());
            default:
                return $text->t('check_http', $e->status(), $e->errorCode());
        }
    }

    /**
     * A line of the answer.
     *
     * @param string $text
     * @param bool $ok
     * @return array
     */
    private static function line(string $text, bool $ok): array
    {
        return ['text' => $text, 'ok' => $ok];
    }
}
