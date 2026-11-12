<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Test\Unit\Model\Core;

use CoinsPay\Payment\Model\Core\Decision;
use CoinsPay\Payment\Model\Core\Text;
use PHPUnit\Framework\TestCase;

/**
 * Invoice state → event of the order.
 */
class DecisionTest extends TestCase
{
    private const INVOICE_ID = '7c9e6679-7425-40de-944b-e07fc1f90ae7';

    private function invoice(array $overrides = []): array
    {
        return $overrides + [
            'id' => self::INVOICE_ID,
            'number' => 12,
            'status' => 'open',
            'test' => false,
            'currency' => 'USD',
            'amount' => '49.9',
            'amount_paid' => '0',
            'amount_pending' => '0',
            'amount_due' => '49.9',
            'amount_overpaid' => null,
            'paid_late' => false,
            'paid_manually' => false,
            'payments' => [],
        ];
    }

    private function order(array $overrides = []): array
    {
        return $overrides + [
            'is_paid' => false,
            'paid_invoice' => '',
            'is_store_cancelled' => false,
            'expected_amount' => '49.9',
            'is_current' => true,
            'test_allowed' => false,
        ];
    }

    private function payment(string $status = 'confirmed'): array
    {
        return [
            'deposit_id' => 'd1',
            'status' => $status,
            'chain' => 'tron',
            'asset' => 'USDT',
            'amount' => '49.9',
            'tx_hash' => 'abc123',
        ];
    }

    private function decide(array $invoice, array $order, ?callable $translator = null): array
    {
        return Decision::decide($invoice, $order, new Text($translator));
    }

    private function paid(array $overrides = []): array
    {
        return $this->invoice(
            $overrides + ['status' => 'paid', 'amount_paid' => '49.9', 'amount_due' => '0', 'payments' => [$this->payment()]]
        );
    }

    public function testOpenUnpaidInvoiceChangesNothing(): void
    {
        $decision = $this->decide($this->invoice(), $this->order());
        $this->assertSame(Decision::NONE, $decision['event']);
        $this->assertFalse($decision['final']);
    }

    public function testPaymentOnItsWay(): void
    {
        $invoice = $this->invoice(['amount_pending' => '49.9', 'amount_due' => '0', 'payments' => [$this->payment('pending')]]);
        $decision = $this->decide($invoice, $this->order());
        $this->assertSame('pending', $decision['event']);
        $this->assertStringContainsString('on its way', $decision['note']);
        $this->assertStringContainsString('49.9 USDT, TRON, tx abc123', $decision['note']);
        $this->assertFalse($decision['final']);
    }

    public function testPartialPaymentWithTheRest(): void
    {
        $decision = $this->decide($this->invoice(['amount_paid' => '20', 'amount_due' => '29.9']), $this->order());
        $this->assertSame('partial', $decision['event']);
        $this->assertStringContainsString('20 USD received, 29.9 USD left', $decision['note']);
    }

    public function testRestOnItsWayAfterAPartialPaymentIsNotCalledPartial(): void
    {
        $decision = $this->decide(
            $this->invoice([
                'amount_paid' => '20',
                'amount_pending' => '29.9',
                'amount_due' => '0',
                'payments' => [$this->payment('confirmed'), $this->payment('pending')],
            ]),
            $this->order()
        );
        $this->assertSame('pending', $decision['event']);
        $this->assertStringContainsString('20 USD received, the rest is on its way', $decision['note']);
        $this->assertStringNotContainsString('left', $decision['note']);

        // Part paid, part on its way, still something to pay: partially paid.
        $invoice = $this->invoice(['amount_paid' => '10', 'amount_pending' => '10', 'amount_due' => '29.9']);
        $this->assertSame('partial', $this->decide($invoice, $this->order())['event']);
    }

    public function testPaidInvoicePaysTheOrder(): void
    {
        $decision = $this->decide($this->paid(), $this->order());
        $this->assertSame('paid', $decision['event']);
        $this->assertTrue($decision['final']);
        $this->assertStringContainsString('invoice No. 12 is paid: 49.9 USDT, TRON', $decision['note']);
    }

    public function testLateOverpaidAndManualPaymentsHaveTheirOwnEvents(): void
    {
        $this->assertSame('paid_over', $this->decide($this->paid(['amount_overpaid' => '5.1']), $this->order())['event']);
        $late = $this->paid(['amount_overpaid' => '5.1', 'paid_late' => true]);
        $this->assertSame('paid_late', $this->decide($late, $this->order())['event']);

        $manual = $this->paid(['amount_overpaid' => '5.1', 'paid_late' => true, 'paid_manually' => true]);
        $decision = $this->decide($manual, $this->order());
        $this->assertSame('paid_manual', $decision['event']);
        $this->assertStringContainsString('after the invoice expired', $decision['note']);
        $this->assertStringContainsString('Overpaid by 5.1 USD', $decision['note']);
        $this->assertStringContainsString('marked as paid manually', $decision['note']);
    }

    public function testPaidInvoiceIsCheckedAgainstTheOrderTotalNow(): void
    {
        $paid = $this->paid(['amount' => '12.5', 'amount_paid' => '12.5']);
        // The store raised the order total after the invoice was made.
        $decision = $this->decide($paid, $this->order(['expected_amount' => '100']));
        $this->assertSame('mismatch', $decision['event']);
        $this->assertStringContainsString('12.5 USD differs from the order (100 USD)', $decision['note']);
        // The invoice is in a currency that is neither the order's nor the base one.
        $this->assertSame('mismatch', $this->decide($paid, $this->order(['expected_amount' => null]))['event']);
    }

    public function testPaymentOfAnOrderCancelledByTheStoreNeedsADecision(): void
    {
        $decision = $this->decide($this->paid(), $this->order(['is_store_cancelled' => true]));
        $this->assertSame('paid_cancelled', $decision['event']);
        $this->assertStringContainsString('cancelled in the store', $decision['note']);
        $this->assertTrue($decision['final']);
    }

    public function testTestPaymentPaysOnlyAnOrderOfTheTestBuyer(): void
    {
        $paid = $this->paid(['test' => true]);
        $decision = $this->decide($paid, $this->order());
        $this->assertSame('test_foreign', $decision['event']);
        $this->assertStringContainsString('no money was received', $decision['note']);

        $this->assertSame('paid', $this->decide($paid, $this->order(['test_allowed' => true]))['event']);
        $this->assertSame('paid', $this->decide(['test' => false] + $paid, $this->order())['event']);
    }

    public function testSecondPaidInvoiceOfAPaidOrderIsOnlyAComment(): void
    {
        $same = $this->decide($this->paid(), $this->order(['is_paid' => true, 'paid_invoice' => self::INVOICE_ID]));
        $this->assertSame(Decision::NONE, $same['event']);

        $other = $this->decide($this->paid(), $this->order(['is_paid' => true, 'paid_invoice' => 'another-invoice']));
        $this->assertSame(Decision::COMMENT, $other['event']);
        $this->assertStringContainsString('Refund the buyer', $other['note']);
    }

    public function testExpiredUnpaidInvoice(): void
    {
        $expired = $this->invoice(['status' => 'expired']);
        $this->assertSame('expired', $this->decide($expired, $this->order())['event']);
        // An older invoice of the order ran out while the buyer pays a newer one.
        $this->assertSame(Decision::NONE, $this->decide($expired, $this->order(['is_current' => false]))['event']);
    }

    public function testCancelledUnpaidInvoice(): void
    {
        $decision = $this->decide($this->invoice(['status' => 'cancelled']), $this->order());
        $this->assertSame('cancelled', $decision['event']);
        $this->assertStringContainsString('was cancelled', $decision['note']);
    }

    public function testExpiredOrCancelledPartiallyPaidInvoiceNeedsADecision(): void
    {
        foreach (['expired', 'cancelled'] as $status) {
            $invoice = $this->invoice(['status' => $status, 'amount_paid' => '20']);
            $decision = $this->decide($invoice, $this->order(['is_current' => false]));
            $this->assertSame($status . '_partial', $decision['event'], $status);
            $this->assertStringContainsString('20 USD of 49.9 USD', $decision['note']);
            $this->assertTrue($decision['final']);
        }
    }

    public function testPaidOrderIsNotTouchedByOtherStates(): void
    {
        foreach (['open', 'expired', 'cancelled'] as $status) {
            $invoice = $this->invoice(['status' => $status, 'amount_paid' => '20']);
            $this->assertSame(Decision::NONE, $this->decide($invoice, $this->order(['is_paid' => true]))['event']);
        }
    }

    public function testNotesGoThroughTheTranslator(): void
    {
        $dictionary = ['invoice No. %1 is paid: %2.' => 'счёт № %1 оплачен: %2.'];
        $translator = static function (string $phrase, array $args) use ($dictionary): string {
            return Text::fill($dictionary[$phrase] ?? $phrase, $args);
        };
        $decision = $this->decide($this->paid(), $this->order(), $translator);
        $this->assertStringContainsString('счёт № 12 оплачен: 49.9 USDT, TRON', $decision['note']);
    }

    public function testFingerprintChangesWithWhatMattersOnly(): void
    {
        $base = Decision::fingerprint($this->invoice());
        $this->assertSame($base, Decision::fingerprint($this->invoice(['amount_paid' => '0.00'])));
        $this->assertSame($base, Decision::fingerprint($this->invoice(['expires_at' => '2026-10-29T00:00:00Z'])));
        $this->assertNotSame($base, Decision::fingerprint($this->invoice(['amount_pending' => '49.9'])));
        $this->assertNotSame($base, Decision::fingerprint($this->invoice(['status' => 'expired'])));
        $this->assertNotSame(
            Decision::fingerprint($this->invoice(['payments' => [$this->payment('pending')]])),
            Decision::fingerprint($this->invoice(['payments' => [$this->payment('confirmed')]]))
        );
    }

    public function testPlaceholdersAreFilledFromTheLastOne(): void
    {
        $this->assertSame('a b', Text::fill('%1 %2', ['a', 'b']));
        $args = range(1, 11);
        $this->assertSame('11 1', Text::fill('%11 %1', $args));
    }
}
