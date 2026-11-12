<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Test\Unit\Model\Core;

use CoinsPay\Payment\Model\Core\Currencies;
use CoinsPay\Payment\Model\Core\Settings;
use CoinsPay\Payment\Model\Core\Sync;
use CoinsPay\Payment\Model\Core\Text;
use CoinsPay\Payment\Test\Unit\Fake\FakeRepo;
use CoinsPay\Payment\Test\Unit\Fake\FakeStore;
use PHPUnit\Framework\TestCase;

/**
 * Applying an invoice to its order in Magento's terms: an invoice for a payment, a cancel for an
 * expiry, a hold for a dispute; and only once, even after a failed write.
 */
class SyncTest extends TestCase
{
    private const INVOICE_ID = '7c9e6679-7425-40de-944b-e07fc1f90ae7';
    private const ORDER_ID = 7;

    /** @var FakeStore */
    private $store;

    /** @var FakeRepo */
    private $repo;

    protected function setUp(): void
    {
        $this->store = new FakeStore();
        $this->store->place(self::ORDER_ID);
        $this->repo = new FakeRepo();
        // Currencies come from the cache: no API call.
        $this->repo->kv = ['currencies_' . substr(hash('sha256', Settings::DEFAULT_API_URL . '|live'), 0, 12) => '{"USD":2}'];
        $this->repo->add(self::ORDER_ID, 1, $this->invoice(), 1, false, time() + 3600);
        $this->repo->setPluginStatus(self::ORDER_ID, 'coinspay_awaiting', 'created');
    }

    private function sync(array $settings = []): Sync
    {
        $values = SettingsTest::settings($settings);
        $currencies = new Currencies($values, $this->repo, $this->store);
        return new Sync($this->store, $values, $this->repo, $currencies, new Text());
    }

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

    private function paid(array $overrides = []): array
    {
        return $this->invoice($overrides + ['status' => 'paid', 'amount_paid' => '49.9', 'amount_due' => '0']);
    }

    private function actions(): array
    {
        return array_map(static function (array $write): string {
            return $write[0] . ':' . $write[2];
        }, $this->store->writes);
    }

    public function testAFailedWriteDoesNotLoseThePayment(): void
    {
        $sync = $this->sync();
        $this->store->failures = 1;
        try {
            $sync->apply($this->paid());
            $this->fail('the write did not fail');
        } catch (\RuntimeException $e) {
            $this->assertSame('the order is not writable', $e->getMessage());
        }
        $this->assertSame('', $this->repo->rows[self::INVOICE_ID]['fingerprint'], 'no fingerprint after a failed write');
        $this->assertSame(0, $this->repo->rows[self::INVOICE_ID]['paid_applied'], 'not marked paid after a failed write');

        $this->assertSame('paid: invoiced, status processing', $sync->apply($this->paid()));
        $this->assertSame('processing', $this->store->orders[self::ORDER_ID]['status']);
        $this->assertSame(1, $this->repo->rows[self::INVOICE_ID]['paid_applied']);
        $this->assertSame('no change', $sync->apply($this->paid()));
        $this->assertSame(['pay:processing'], $this->actions());
        $this->assertNull($this->repo->rows[self::INVOICE_ID]['next_poll'], 'a paid invoice is not polled');
    }

    public function testThePaymentCommentNamesTheInvoiceAndTheCoins(): void
    {
        $payment = ['deposit_id' => 'd1', 'status' => 'confirmed', 'chain' => 'tron', 'asset' => 'USDT', 'amount' => '49.9', 'tx_hash' => 'abc'];
        $this->sync()->apply($this->paid(['payments' => [$payment]]));
        $this->assertSame('CoinsPay: invoice No. 12 is paid: 49.9 USDT, TRON, tx abc.', $this->store->writes[0][3]);
    }

    public function testMoneyOnItsWayIsACommentWithTheDefaults(): void
    {
        $result = $this->sync()->apply($this->invoice(['amount_pending' => '49.9', 'amount_due' => '0']));
        $this->assertSame('pending: comment', $result);
        $this->assertSame(['comment:coinspay_awaiting'], $this->actions());
        $this->assertNotNull($this->repo->rows[self::INVOICE_ID]['next_poll'], 'an open invoice is polled');
    }

    public function testAnExpiredInvoiceCancelsTheOrderAndALatePaymentAsksTheStore(): void
    {
        $sync = $this->sync();
        $this->assertSame('expired: status canceled', $sync->apply($this->invoice(['status' => 'expired'])));
        $this->assertSame('canceled', $this->store->orders[self::ORDER_ID]['state']);

        // Magento does not invoice a cancelled order: the late payment is recorded and the store decides.
        $result = $sync->apply($this->paid(['paid_late' => true]));
        $this->assertSame('paid_late: not invoiced: the order cannot be invoiced', $result);
        $this->assertSame('coinspay_review', $this->store->orders[self::ORDER_ID]['status']);
        $this->assertSame(['cancel:canceled', 'hold:coinspay_review'], $this->actions());
        $this->assertStringContainsString('a cancelled order is not reopened', $this->store->writes[1][3]);
        $this->assertSame(1, $this->repo->rows[self::INVOICE_ID]['paid_applied']);
    }

    public function testPaymentOfAnOrderTheStoreCancelledIsHeldForADecision(): void
    {
        $this->store->orders[self::ORDER_ID]['state'] = 'canceled';
        $this->store->orders[self::ORDER_ID]['status'] = 'canceled';
        $this->assertSame('paid_cancelled: status coinspay_review', $this->sync()->apply($this->paid()));
        $this->assertStringContainsString('cancelled in the store', $this->store->writes[0][3]);
    }

    public function testAnOrderTheStoreMovedIsNotCancelledByAnExpiry(): void
    {
        $this->store->orders[self::ORDER_ID]['status'] = 'pending_payment';
        $this->assertSame('expired: comment', $this->sync()->apply($this->invoice(['status' => 'expired'])));
        $this->assertSame('pending_payment', $this->store->orders[self::ORDER_ID]['state']);
    }

    public function testAWrongAmountPutsTheOrderOnHold(): void
    {
        $this->store->orders[self::ORDER_ID]['total'] = '59.9';
        $this->store->orders[self::ORDER_ID]['base_total'] = '59.9';
        $this->assertSame('mismatch: status coinspay_review', $this->sync()->apply($this->paid()));
        $this->assertSame('holded', $this->store->orders[self::ORDER_ID]['state']);
        $this->assertSame(0, $this->repo->rows[self::INVOICE_ID]['paid_applied']);
    }

    public function testAnInvoiceInTheBaseCurrencyIsCheckedAgainstTheBaseTotal(): void
    {
        $this->store->orders[self::ORDER_ID] = ['currency' => 'EUR', 'total' => '45'] + $this->store->orders[self::ORDER_ID];
        $this->assertSame('49.9', $this->sync()->expectedAmount($this->store->orders[self::ORDER_ID], 'usd'));
        $this->assertSame('45', $this->sync()->expectedAmount($this->store->orders[self::ORDER_ID], 'EUR'));
        $this->assertNull($this->sync()->expectedAmount($this->store->orders[self::ORDER_ID], 'GBP'));
        $this->assertSame('paid: invoiced, status processing', $this->sync()->apply($this->paid()));
    }

    public function testAMissingStatusLeavesACommentAndAWarning(): void
    {
        $result = $this->sync(['status_expired' => 'deleted_status'])->apply($this->invoice(['status' => 'expired']));
        $this->assertSame('expired: comment (status not set)', $result);
        $this->assertSame('expired', $this->repo->kv['status_problem']);
        $this->assertStringContainsString('not set in the CoinsPay settings', $this->store->writes[0][3]);

        // A payment is recorded anyway, with the default status of processing.
        $this->assertSame('paid: invoiced, status processing', $this->sync(['status_paid' => ''])->apply($this->paid()));
    }

    public function testAnOrderPaidByAnotherMethodGetsOnlyNotesOfMoney(): void
    {
        $this->store->orders[self::ORDER_ID]['ours'] = false;
        $this->assertSame('expired: other payment method', $this->sync()->apply($this->invoice(['status' => 'expired'])));
        $result = $this->sync()->apply($this->paid());
        $this->assertSame('paid: comment (other payment method)', $result);
        $this->assertSame(['comment:coinspay_awaiting'], $this->actions());
    }

    public function testATestPaymentOfARealBuyerIsHeld(): void
    {
        $this->assertSame('test_foreign: status coinspay_review', $this->sync()->apply($this->paid(['test' => true])));
        $this->assertStringStartsWith('CoinsPay (test):', $this->store->writes[0][3]);
        $this->assertSame(0, $this->repo->rows[self::INVOICE_ID]['paid_applied']);
    }
}
