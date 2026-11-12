<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Controller\Payment;

use CoinsPay\Payment\Model\Config;
use CoinsPay\Payment\Model\Core\ApiError;
use CoinsPay\Payment\Model\Core\Payment;
use CoinsPay\Payment\Model\Core\Text;
use CoinsPay\Payment\Model\Core\Util;
use CoinsPay\Payment\Model\Kits;
use CoinsPay\Payment\Model\Links;
use CoinsPay\Payment\Model\OrderStore;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect as RedirectResult;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Model\Order;

/**
 * The buyer goes to pay: right after "Place Order" (the last order of the checkout session), or
 * later by "Go to payment" (the order and its signed token). The invoice is made or found, and the
 * buyer goes to the CoinsPay payment page.
 */
class Redirect implements HttpGetActionInterface
{
    /** @var RequestInterface */
    private $request;

    /** @var RedirectFactory */
    private $redirectFactory;

    /** @var CheckoutSession */
    private $checkoutSession;

    /** @var ManagerInterface */
    private $messages;

    /** @var Kits */
    private $kits;

    /** @var OrderStore */
    private $store;

    /** @var Links */
    private $links;

    /**
     * @param RequestInterface $request
     * @param RedirectFactory $redirectFactory
     * @param CheckoutSession $checkoutSession
     * @param ManagerInterface $messages
     * @param Kits $kits
     * @param OrderStore $store
     * @param Links $links
     */
    public function __construct(
        RequestInterface $request,
        RedirectFactory $redirectFactory,
        CheckoutSession $checkoutSession,
        ManagerInterface $messages,
        Kits $kits,
        OrderStore $store,
        Links $links
    ) {
        $this->request = $request;
        $this->redirectFactory = $redirectFactory;
        $this->checkoutSession = $checkoutSession;
        $this->messages = $messages;
        $this->kits = $kits;
        $this->store = $store;
        $this->links = $links;
    }

    /**
     * @inheritdoc
     */
    public function execute()
    {
        $orderId = (int)$this->request->getParam('order');
        $placed = $orderId === 0;
        if ($placed) {
            $orderId = (int)$this->checkoutSession->getLastOrderId();
        }
        $order = $orderId > 0 ? $this->store->load($orderId) : null;
        if ($order === null
            || $order->getPayment() === null
            || $order->getPayment()->getMethod() !== Config::METHOD
            || (!$placed && !$this->kits->forStore((int)$order->getStoreId())->tokens()->checkOrder(
                $orderId,
                (string)$this->request->getParam('token')
            ))
        ) {
            return $this->redirect('checkout/cart');
        }

        $storeId = (int)$order->getStoreId();
        // Paid, cancelled or moved by the store: the return page tells the buyer.
        if ($order->getState() !== Order::STATE_PENDING_PAYMENT) {
            return $this->redirectTo($this->links->back($orderId, $storeId));
        }

        $kit = $this->kits->forStore($storeId);
        try {
            $invoice = $kit->payment()->invoiceFor($orderId);
        } catch (ApiError $e) {
            $kit->store()->log(sprintf('order %d: invoice not created: %s', $orderId, $e->summary()), true);
            return $this->fail($order, $placed, Payment::errorMessage($e, $this->buyerText()));
        } catch (\Exception $e) {
            $kit->store()->log(sprintf('order %d: invoice not created: %s', $orderId, $e->getMessage()), true);
            return $this->fail($order, $placed, $this->buyerText()->t('error_failed'));
        }
        if (Util::value($invoice, 'status') !== 'open') {
            return $this->redirectTo($this->links->back($orderId, $storeId));
        }
        return $this->redirectTo($this->links->payPage($invoice, $storeId));
    }

    /**
     * The invoice could not be made.
     *
     * An order the buyer has just placed is cancelled and the cart comes back, so the buyer can pick
     * another payment method; an older one stays for another try.
     *
     * @param Order $order
     * @param bool $placed
     * @param string $message
     * @return RedirectResult
     */
    private function fail(Order $order, bool $placed, string $message): RedirectResult
    {
        $this->messages->addErrorMessage($message);
        if ($placed) {
            $note = (string)__('CoinsPay: the invoice could not be made, the order is cancelled and the cart is restored.');
            $this->store->cancel((int)$order->getId(), Order::STATE_CANCELED, $note);
            $this->checkoutSession->restoreQuote();
            return $this->redirect('checkout/cart');
        }
        return $this->redirectTo($this->links->back((int)$order->getId(), (int)$order->getStoreId()));
    }

    /**
     * Messages to the buyer in the language of the store view.
     *
     * @return Text
     */
    private function buyerText(): Text
    {
        return new Text(static function (string $phrase, array $args): string {
            return (string)__($phrase, ...$args);
        });
    }

    /**
     * Redirect to a route of the store.
     *
     * @param string $path
     * @return RedirectResult
     */
    private function redirect(string $path): RedirectResult
    {
        return $this->redirectFactory->create()->setPath($path);
    }

    /**
     * Redirect to an address.
     *
     * @param string $url
     * @return RedirectResult
     */
    private function redirectTo(string $url): RedirectResult
    {
        return $this->redirectFactory->create()->setUrl($url);
    }
}
