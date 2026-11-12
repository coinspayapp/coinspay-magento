<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Controller\Payment;

use CoinsPay\Payment\Model\Config;
use CoinsPay\Payment\Model\Core\Util;
use CoinsPay\Payment\Model\Kits;
use CoinsPay\Payment\Model\Links;
use CoinsPay\Payment\Model\OrderStore;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Sales\Model\Order;

/**
 * The buyer comes back from the payment page (return_url of the invoice). The invoice is applied to
 * the order at once; a paid order (or money on its way) goes to the order success page of the
 * checkout, an unpaid one gets a page with "Go to payment" and "Put the items back in the cart".
 */
class Back implements HttpGetActionInterface
{
    /** @var RequestInterface */
    private $request;

    /** @var RedirectFactory */
    private $redirectFactory;

    /** @var PageFactory */
    private $pageFactory;

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
     * @param PageFactory $pageFactory
     * @param CheckoutSession $checkoutSession
     * @param ManagerInterface $messages
     * @param Kits $kits
     * @param OrderStore $store
     * @param Links $links
     */
    public function __construct(
        RequestInterface $request,
        RedirectFactory $redirectFactory,
        PageFactory $pageFactory,
        CheckoutSession $checkoutSession,
        ManagerInterface $messages,
        Kits $kits,
        OrderStore $store,
        Links $links
    ) {
        $this->request = $request;
        $this->redirectFactory = $redirectFactory;
        $this->pageFactory = $pageFactory;
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
        $order = $orderId > 0 ? $this->store->load($orderId) : null;
        if ($order === null
            || $order->getPayment() === null
            || $order->getPayment()->getMethod() !== Config::METHOD
            || !$this->kits->forStore((int)$order->getStoreId())->tokens()->checkOrder(
                $orderId,
                (string)$this->request->getParam('token')
            )
        ) {
            return $this->redirectFactory->create()->setPath('/');
        }
        $storeId = (int)$order->getStoreId();
        $ownSession = (int)$this->checkoutSession->getLastOrderId() === $orderId;

        if ($this->request->getParam('restore')) {
            if ($ownSession && $order->getState() !== Order::STATE_PROCESSING && $this->checkoutSession->restoreQuote()) {
                $this->messages->addSuccessMessage(__('The items are back in your cart.'));
                return $this->redirectFactory->create()->setPath('checkout/cart');
            }
            $this->messages->addErrorMessage(
                __('The cart could not be restored here: open this page in the browser the order was placed from.')
            );
        }

        $invoice = $this->refresh($orderId, $storeId);
        $kit = $this->kits->forStore($storeId);
        $paid = $kit->repo()->paidInvoice($orderId) !== '';
        $pending = $invoice !== null && Util::positive(Util::value($invoice, 'amount_pending'));
        if (($paid || $pending) && $ownSession) {
            return $this->redirectFactory->create()->setPath('checkout/onepage/success');
        }

        $order = $this->store->load($orderId);
        $open = !$paid && $invoice !== null && Util::value($invoice, 'status') === 'open'
            && $order->getState() === Order::STATE_PENDING_PAYMENT;
        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->set(
            $paid || $pending ? __('Thank you for your order') : __('The payment is not completed')
        );
        $block = $page->getLayout()->getBlock('coinspay.back');
        if ($block) {
            $block->setData('view', [
                'number' => (string)$order->getIncrementId(),
                'paid' => $paid,
                'pending' => $pending && !$paid,
                'open' => $open,
                'restorable' => !$paid && !$pending && $order->getState() !== Order::STATE_PROCESSING,
                'pay_url' => $open ? $this->links->payAgain($orderId, $storeId) : '',
                'restore_url' => $this->links->back($orderId, $storeId, true),
            ]);
        }
        return $page;
    }

    /**
     * Applies the current invoice of the order now; null when there is none or CoinsPay does not answer.
     *
     * @param int $orderId
     * @param int $storeId
     * @return array|null
     */
    private function refresh(int $orderId, int $storeId): ?array
    {
        $kit = $this->kits->forStore($storeId);
        $row = $kit->repo()->current($orderId);
        if (!$row || !$kit->settings()->configured()) {
            return null;
        }
        try {
            $invoice = $kit->settings()->client()->invoice((string)$row['invoice_id']);
            $kit->sync()->apply($invoice);
            return $invoice;
        } catch (\Exception $e) {
            $kit->store()->log(sprintf('order %d: return without the invoice state: %s', $orderId, $e->getMessage()), true);
            return Util::decode($row['snapshot'] ?? '') ?: null;
        }
    }
}
