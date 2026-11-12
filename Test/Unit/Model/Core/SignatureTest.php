<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Test\Unit\Model\Core;

use CoinsPay\Payment\Model\Core\Signature;
use CoinsPay\Payment\Model\Core\Tokens;
use PHPUnit\Framework\TestCase;

/**
 * Webhook signature (the reference vector is computed by the CoinsPay server code, signPayload)
 * and the signed values of the module's own links.
 */
class SignatureTest extends TestCase
{
    private const BODY = '{"id":"0b0e1c1e-6f0c-4c56-9a8e-2d6c1e1f0a01","type":"invoice.paid",'
        . '"created_at":"2026-10-28T08:00:00.000Z","shop_id":"5a1f6b9e-1c2d-4e3f-8a9b-0c1d2e3f4a5b","test":true,'
        . '"data":{"invoice":{"id":"7c9e6679-7425-40de-944b-e07fc1f90ae7","status":"paid"}}}';
    private const SECRET = 'whsec_plugin_test_secret';
    private const TIME = 1761638400;
    private const SIGNATURE = '34c05e780e334839328753e5d1b2fa20f7705b67b4489d2f29abda8695af92d9';
    private const HEADER = 't=1761638400,v1=' . self::SIGNATURE;

    public function testAcceptsTheSignatureOfCoinsPay(): void
    {
        $this->assertTrue(Signature::verify(self::BODY, self::HEADER, self::SECRET, self::TIME));
        // Clocks may differ up to five minutes either way.
        $this->assertTrue(Signature::verify(self::BODY, self::HEADER, self::SECRET, self::TIME + 300));
        $this->assertTrue(Signature::verify(self::BODY, self::HEADER, self::SECRET, self::TIME - 300));
        $spaced = ' t=1761638400 , v1=' . self::SIGNATURE . ' ';
        $this->assertTrue(Signature::verify(self::BODY, $spaced, self::SECRET, self::TIME));
    }

    public function testRejectsAStaleTimestamp(): void
    {
        $this->assertFalse(Signature::verify(self::BODY, self::HEADER, self::SECRET, self::TIME + 301));
        $this->assertFalse(Signature::verify(self::BODY, self::HEADER, self::SECRET, self::TIME - 301));
    }

    public function testRejectsAnotherSecretOrBody(): void
    {
        $this->assertFalse(Signature::verify(self::BODY, self::HEADER, 'whsec_other', self::TIME));
        $this->assertFalse(Signature::verify(str_replace('"paid"', '"open"', self::BODY), self::HEADER, self::SECRET, self::TIME));
        // Re-encoded JSON is other bytes: only the raw body verifies.
        $pretty = (string)json_encode(json_decode(self::BODY, true), JSON_PRETTY_PRINT);
        $this->assertFalse(Signature::verify($pretty, self::HEADER, self::SECRET, self::TIME));
    }

    public function testRejectsMalformedHeaders(): void
    {
        $headers = [
            '',
            'v1=34c05e78',
            't=abc,v1=' . self::SIGNATURE,
            't=1761638400',
            't=1761638400,v1=XYZ',
            '=1,t=1761638400,v1=' . self::SIGNATURE,
            'garbage',
        ];
        foreach ($headers as $header) {
            $this->assertFalse(Signature::verify(self::BODY, $header, self::SECRET, self::TIME), $header);
        }
        $this->assertFalse(Signature::verify(self::BODY, self::HEADER, '', self::TIME));
    }

    public function testOrderTokensAndTestBuyerLinks(): void
    {
        $tokens = new Tokens('link-secret');
        $this->assertTrue($tokens->checkOrder(42, $tokens->orderToken(42)));
        $this->assertFalse($tokens->checkOrder(43, $tokens->orderToken(42)));
        $this->assertFalse((new Tokens('other'))->checkOrder(42, $tokens->orderToken(42)));
        $this->assertFalse((new Tokens(''))->checkOrder(42, (new Tokens(''))->orderToken(42)));

        $value = $tokens->testBuyer(1000);
        $this->assertTrue($tokens->checkTestBuyer($value, 1000));
        $this->assertTrue($tokens->checkTestBuyer($value, 1000 + Tokens::TEST_BUYER_TTL));
        $this->assertFalse($tokens->checkTestBuyer($value, 1001 + Tokens::TEST_BUYER_TTL));
        $this->assertFalse($tokens->checkTestBuyer('99999999999.' . substr($value, -32), 1000));
        $this->assertFalse($tokens->checkTestBuyer('garbage', 1000));
    }
}
