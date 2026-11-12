<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Model\Core;

/**
 * Settings of the module for one store view, and the map "invoice event → order status" that the
 * store owner sets up. Magento ties statuses to order states: each event moves the order to its
 * state (paid → processing with an invoice, expired → canceled, a dispute → on hold), and the setting
 * picks the status of that state.
 */
class Settings
{
    public const MIN_LIFETIME = 15;
    public const MAX_LIFETIME = 1440;
    public const DEFAULT_LIFETIME = 60;

    /** The order is left as it is; a comment is still written. */
    public const NO_CHANGE = 'no_change';

    /** Stored value "same as Paid". */
    public const SAME_AS_PAID = 'same_as_paid';

    /** The API address when the admin leaves the field empty. */
    public const DEFAULT_API_URL = 'https://coinspay.app/api/v1';

    /** The module's own statuses. */
    public const STATUS_AWAITING = 'coinspay_awaiting';
    public const STATUS_REVIEW = 'coinspay_review';

    /** Invoice events in the order of the settings page. */
    public const EVENTS = [
        'created', 'pending', 'partial', 'paid', 'paid_late', 'paid_over', 'paid_manual',
        'mismatch', 'paid_cancelled', 'test_foreign', 'expired', 'expired_partial', 'cancelled', 'cancelled_partial',
    ];

    /** Events that must change the order ("no change" is not offered). */
    public const REQUIRED = ['created', 'paid'];

    /** Events that may be "same as Paid". */
    public const PAID_LIKE = ['paid_late', 'paid_over', 'paid_manual'];

    /** The order state each event moves the order to; the status setting picks a status of it. */
    public const STATES = [
        'created' => 'pending_payment',
        'pending' => 'pending_payment',
        'partial' => 'pending_payment',
        'paid' => 'processing',
        'paid_late' => 'processing',
        'paid_over' => 'processing',
        'paid_manual' => 'processing',
        'mismatch' => 'holded',
        'paid_cancelled' => 'holded',
        'test_foreign' => 'holded',
        'expired' => 'canceled',
        'expired_partial' => 'holded',
        'cancelled' => 'canceled',
        'cancelled_partial' => 'holded',
    ];

    /** Default status of each event (etc/config.xml holds the same). */
    public const DEFAULTS = [
        'created' => self::STATUS_AWAITING,
        'pending' => self::NO_CHANGE,
        'partial' => self::NO_CHANGE,
        'paid' => 'processing',
        'paid_late' => self::SAME_AS_PAID,
        'paid_over' => self::SAME_AS_PAID,
        'paid_manual' => self::SAME_AS_PAID,
        'mismatch' => self::STATUS_REVIEW,
        'paid_cancelled' => self::STATUS_REVIEW,
        'test_foreign' => self::STATUS_REVIEW,
        'expired' => 'canceled',
        'expired_partial' => self::STATUS_REVIEW,
        'cancelled' => 'canceled',
        'cancelled_partial' => self::STATUS_REVIEW,
    ];

    /** @var callable fn(string $key): mixed — a setting of payment/coinspay, secrets decrypted */
    private $value;

    /** @var callable fn(string $status, string $state): bool */
    private $statusExists;

    /** @var HttpInterface */
    private $http;

    /** @var string */
    private $userAgent;

    /**
     * @param callable $value
     * @param callable $statusExists
     * @param HttpInterface $http
     * @param string $userAgent
     */
    public function __construct(callable $value, callable $statusExists, HttpInterface $http, string $userAgent)
    {
        $this->value = $value;
        $this->statusExists = $statusExists;
        $this->http = $http;
        $this->userAgent = $userAgent;
    }

    /**
     * A setting, or the default when it is empty.
     *
     * @param string $key
     * @param string $default
     * @return string
     */
    public function get(string $key, string $default = ''): string
    {
        $value = ($this->value)($key);
        return $value === null || $value === '' ? $default : (string)$value;
    }

    /**
     * A yes/no setting.
     *
     * @param string $key
     * @return bool
     */
    public function flag(string $key): bool
    {
        $value = ($this->value)($key);
        return !empty($value) && $value !== '0';
    }

    /**
     * API address without the trailing slash.
     *
     * @return string
     */
    public function apiUrl(): string
    {
        $url = rtrim(trim($this->get('api_url')), '/');
        return $url === '' ? self::DEFAULT_API_URL : $url;
    }

    /**
     * The API key.
     *
     * @return string
     */
    public function apiKey(): string
    {
        return trim($this->get('api_key'));
    }

    /**
     * The whsec_ secret of the webhook.
     *
     * @return string
     */
    public function webhookSecret(): string
    {
        return trim($this->get('webhook_secret'));
    }

    /**
     * A cpk_test_ key: invoices are paid with a button on the payment page, no coins move.
     *
     * @return bool
     */
    public function isTestMode(): bool
    {
        return strpos($this->apiKey(), 'cpk_test_') === 0;
    }

    /**
     * Whether invoices can be made.
     *
     * @return bool
     */
    public function configured(): bool
    {
        return $this->apiKey() !== '';
    }

    /**
     * Minutes to pay an invoice.
     *
     * @return int
     */
    public function lifetime(): int
    {
        return self::clampLifetime((int)$this->get('lifetime', (string)self::DEFAULT_LIFETIME));
    }

    /**
     * Minutes to pay within the limits of the API.
     *
     * @param int $minutes
     * @return int
     */
    public static function clampLifetime(int $minutes): int
    {
        if ($minutes <= 0) {
            return self::DEFAULT_LIFETIME;
        }
        return max(self::MIN_LIFETIME, min(self::MAX_LIFETIME, $minutes));
    }

    /**
     * Order status of an invoice event.
     *
     * @param string $event
     * @return string|null A status of the event's state, NO_CHANGE, or null when it is not set or no longer
     *                     belongs to that state.
     */
    public function statusFor(string $event): ?string
    {
        $value = $this->get('status_' . $event);
        if ($value === self::SAME_AS_PAID && in_array($event, self::PAID_LIKE, true)) {
            return $this->statusFor('paid');
        }
        if ($value === '' || $value === self::SAME_AS_PAID) {
            return null;
        }
        if ($value === self::NO_CHANGE) {
            return in_array($event, self::REQUIRED, true) ? null : self::NO_CHANGE;
        }
        $state = self::STATES[$event] ?? '';
        return $state !== '' && ($this->statusExists)($value, $state) ? $value : null;
    }

    /**
     * Events whose status is not set or was deleted: shown on the settings page.
     *
     * @return array
     */
    public function brokenEvents(): array
    {
        $broken = [];
        foreach (self::EVENTS as $event) {
            if ($this->statusFor($event) === null) {
                $broken[] = $event;
            }
        }
        return $broken;
    }

    /**
     * API client with these settings.
     *
     * @return ApiClient
     */
    public function client(): ApiClient
    {
        return new ApiClient($this->http, $this->apiUrl(), $this->apiKey(), $this->userAgent);
    }
}
