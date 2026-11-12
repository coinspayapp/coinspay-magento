<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Test\Unit\Model\Core;

use CoinsPay\Payment\Model\Core\InvoiceRequest;
use CoinsPay\Payment\Model\Core\Util;
use PHPUnit\Framework\TestCase;

/**
 * Body of the invoice request and its idempotency key.
 */
class InvoiceRequestTest extends TestCase
{
    private const RETURN_URL = 'https://shop.example/coinspay/payment/back/order/1042/token/abc/';

    private function order(array $overrides = []): array
    {
        return $overrides + [
            'currency' => 'usd',
            'amount' => '49.9',
            'number' => '000000042',
            'items' => [
                ['name' => 'Espresso beans &amp; grinder', 'quantity' => 2],
                ['name' => '<b>Delivery</b>', 'quantity' => '1.0000'],
            ],
            'return_url' => self::RETURN_URL,
        ];
    }

    private function options(array $overrides = []): array
    {
        return $overrides + [
            'lifetime' => 60,
            'stablecoins_only' => false,
            'buyer_pays_fee' => false,
            'buyer_pays_network' => false,
            'auto_return' => true,
        ];
    }

    public function testAmountFollowsTheCurrencyPrecision(): void
    {
        $this->assertSame('49.9', Util::formatAmount('49.90', 2));
        $this->assertSame('10', Util::formatAmount('10.00', 2));
        $this->assertSame('1235', Util::formatAmount('1234.5', 0));
        $this->assertSame('19.99', Util::formatAmount('19.989', 2));
        $this->assertSame('0.00012345', Util::formatAmount('0.00012345', 8));
        $this->assertSame('0', Util::normalize('0.000'));
    }

    public function testBodyOfAnOrder(): void
    {
        $this->assertSame(
            [
                'currency' => 'USD',
                'amount' => '49.9',
                'reference' => '000000042',
                'lifetime_minutes' => 60,
                'description' => "Espresso beans & grinder \u{00D7} 2, Delivery \u{00D7} 1",
                'return_url' => self::RETURN_URL,
                'auto_return' => true,
            ],
            InvoiceRequest::body($this->order(), $this->options())
        );
    }

    public function testFlagsAndLimits(): void
    {
        $body = InvoiceRequest::body(
            $this->order(['items' => []]),
            $this->options([
                'lifetime' => 5,
                'stablecoins_only' => true,
                'buyer_pays_fee' => true,
                'buyer_pays_network' => true,
                'auto_return' => false,
            ])
        );
        $this->assertSame(15, $body['lifetime_minutes']);
        $this->assertTrue($body['stablecoins_only']);
        $this->assertTrue($body['buyer_pays_fee']);
        $this->assertTrue($body['buyer_pays_network']);
        $this->assertArrayNotHasKey('auto_return', $body);
        $this->assertArrayNotHasKey('description', $body);
        $long = InvoiceRequest::body($this->order(), $this->options(['lifetime' => 100000]));
        $this->assertSame(1440, $long['lifetime_minutes']);
    }

    public function testATooLongReturnLinkIsLeftOutWithAutoReturn(): void
    {
        $order = $this->order(['return_url' => 'https://shop.example/?token=' . str_repeat('a', 500)]);
        $body = InvoiceRequest::body($order, $this->options());
        $this->assertArrayNotHasKey('return_url', $body);
        $this->assertArrayNotHasKey('auto_return', $body);
    }

    public function testLongTextsAreCutByCharacters(): void
    {
        $items = [];
        for ($i = 0; $i < 80; $i++) {
            $items[] = ['name' => 'Кофейные зёрна сорта №' . $i, 'quantity' => 1];
        }
        $body = InvoiceRequest::body($this->order(['items' => $items, 'number' => str_repeat('9', 300)]), $this->options());
        $this->assertSame(1000, mb_strlen($body['description'], 'UTF-8'));
        $this->assertSame("\u{2026}", mb_substr($body['description'], -1, 1, 'UTF-8'));
        $this->assertSame(190, mb_strlen($body['reference'], 'UTF-8'));
        $this->assertTrue(mb_check_encoding($body['description'], 'UTF-8'));
    }

    public function testIdempotencyKeyIsStablePerBodyAndAttempt(): void
    {
        $body = InvoiceRequest::body($this->order(), $this->options());
        $key = InvoiceRequest::idempotencyKey('https://shop.example/', 42, 1, $body);
        $this->assertMatchesRegularExpression('/^mg-[\x21-\x7e]{8,125}$/', $key);
        $this->assertSame($key, InvoiceRequest::idempotencyKey('https://shop.example/', 42, 1, $body));
        $this->assertNotSame($key, InvoiceRequest::idempotencyKey('https://shop.example/', 42, 2, $body));
        $this->assertNotSame($key, InvoiceRequest::idempotencyKey('https://staging.shop.example/', 42, 1, $body));
        $body['amount'] = '59.9';
        $this->assertNotSame($key, InvoiceRequest::idempotencyKey('https://shop.example/', 42, 1, $body));
    }
}
