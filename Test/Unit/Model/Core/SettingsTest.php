<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Test\Unit\Model\Core;

use CoinsPay\Payment\Model\Core\Settings;
use CoinsPay\Payment\Test\Unit\Fake\FakeHttp;
use CoinsPay\Payment\Test\Unit\Fake\FakeStore;
use PHPUnit\Framework\TestCase;

/**
 * Settings of a store view and the map "invoice event → order status" within Magento's states.
 */
class SettingsTest extends TestCase
{
    /**
     * Settings over the defaults of etc/config.xml with some values changed.
     *
     * @param array $values
     * @return Settings
     */
    public static function settings(array $values = []): Settings
    {
        foreach (Settings::DEFAULTS as $event => $status) {
            $values += ['status_' . $event => $status];
        }
        $values += ['lifetime' => '60', 'auto_return' => '1', 'test_buyer_only' => '1'];
        return new Settings(
            static function (string $key) use ($values) {
                return $values[$key] ?? null;
            },
            [FakeStore::class, 'statusExists'],
            new FakeHttp(),
            'CoinsPay-Magento/test'
        );
    }

    public function testDefaultStatusesBelongToTheirStates(): void
    {
        $settings = self::settings();
        $this->assertSame('coinspay_awaiting', $settings->statusFor('created'));
        $this->assertSame(Settings::NO_CHANGE, $settings->statusFor('pending'));
        $this->assertSame('processing', $settings->statusFor('paid'));
        $this->assertSame('processing', $settings->statusFor('paid_late'), 'same as Paid');
        $this->assertSame('coinspay_review', $settings->statusFor('mismatch'));
        $this->assertSame('canceled', $settings->statusFor('expired'));
        $this->assertSame([], $settings->brokenEvents());
        foreach (Settings::EVENTS as $event) {
            $this->assertArrayHasKey($event, Settings::STATES, $event);
            $this->assertArrayHasKey($event, Settings::DEFAULTS, $event);
        }
    }

    public function testAStatusOfAnotherStateOrAMissingOneIsNotUsed(): void
    {
        $settings = self::settings([
            'status_paid' => 'canceled',
            'status_expired' => 'deleted_status',
            'status_mismatch' => '',
            'status_created' => Settings::NO_CHANGE,
            'status_partial' => Settings::SAME_AS_PAID,
        ]);
        $this->assertNull($settings->statusFor('paid'));
        $this->assertNull($settings->statusFor('paid_over'), 'same as a broken Paid');
        $this->assertNull($settings->statusFor('expired'));
        $this->assertNull($settings->statusFor('mismatch'));
        $this->assertNull($settings->statusFor('created'), 'the order must get a status when placed');
        $this->assertNull($settings->statusFor('partial'), 'only paid-like events may be "same as Paid"');
        $this->assertSame(
            ['created', 'partial', 'paid', 'paid_late', 'paid_over', 'paid_manual', 'mismatch', 'expired'],
            $settings->brokenEvents()
        );
    }

    public function testConnectionSettings(): void
    {
        $settings = self::settings();
        $this->assertSame(Settings::DEFAULT_API_URL, $settings->apiUrl());
        $this->assertFalse($settings->configured());

        $settings = self::settings(['api_url' => ' http://host.docker.internal:3430/api/v1/ ', 'api_key' => ' cpk_test_abc ']);
        $this->assertSame('http://host.docker.internal:3430/api/v1', $settings->apiUrl());
        $this->assertSame('cpk_test_abc', $settings->apiKey());
        $this->assertTrue($settings->isTestMode());
        $this->assertTrue($settings->configured());
        $this->assertFalse(self::settings(['api_key' => 'cpk_live'])->isTestMode());
    }

    public function testTimeToPayStaysWithinTheLimits(): void
    {
        $this->assertSame(60, self::settings(['lifetime' => ''])->lifetime());
        $this->assertSame(15, self::settings(['lifetime' => '5'])->lifetime());
        $this->assertSame(1440, self::settings(['lifetime' => '100000'])->lifetime());
        $this->assertSame(60, Settings::clampLifetime(0));
        $this->assertTrue(self::settings()->flag('auto_return'));
        $this->assertFalse(self::settings()->flag('debug'));
    }
}
