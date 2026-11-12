<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Model;

use CoinsPay\Payment\Model\Core\StoreInterface;
use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Url;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Orders for the core. Every order is read fresh from the database: a webhook, the cron and the
 * buyer's return may change it in other requests between two reads.
 */
class OrderStore implements StoreInterface
{
    /** @var OrderFactory */
    private $orderFactory;

    /** @var OrderResource */
    private $orderResource;

    /** @var OrderRepositoryInterface */
    private $orderRepository;

    /** @var InvoiceService */
    private $invoiceService;

    /** @var TransactionFactory */
    private $transactionFactory;

    /** @var OrderSender */
    private $orderSender;

    /** @var InvoiceSender */
    private $invoiceSender;

    /** @var Url */
    private $url;

    /** @var StoreManagerInterface */
    private $storeManager;

    /** @var Config */
    private $config;

    /** @var Writing */
    private $writing;

    /** @var LoggerInterface */
    private $logger;

    /**
     * @param OrderFactory $orderFactory
     * @param OrderResource $orderResource
     * @param OrderRepositoryInterface $orderRepository
     * @param InvoiceService $invoiceService
     * @param TransactionFactory $transactionFactory
     * @param OrderSender $orderSender
     * @param InvoiceSender $invoiceSender
     * @param Url $url Storefront links, also from the admin and the cron.
     * @param StoreManagerInterface $storeManager
     * @param Config $config
     * @param Writing $writing
     * @param LoggerInterface $logger
     */
    public function __construct(
        OrderFactory $orderFactory,
        OrderResource $orderResource,
        OrderRepositoryInterface $orderRepository,
        InvoiceService $invoiceService,
        TransactionFactory $transactionFactory,
        OrderSender $orderSender,
        InvoiceSender $invoiceSender,
        Url $url,
        StoreManagerInterface $storeManager,
        Config $config,
        Writing $writing,
        LoggerInterface $logger
    ) {
        $this->orderFactory = $orderFactory;
        $this->orderResource = $orderResource;
        $this->orderRepository = $orderRepository;
        $this->invoiceService = $invoiceService;
        $this->transactionFactory = $transactionFactory;
        $this->orderSender = $orderSender;
        $this->invoiceSender = $invoiceSender;
        $this->url = $url;
        $this->storeManager = $storeManager;
        $this->config = $config;
        $this->writing = $writing;
        $this->logger = $logger;
    }

    /**
     * The Magento order, read fresh, or null.
     *
     * @param int $orderId
     * @return Order|null
     */
    public function load(int $orderId): ?Order
    {
        $order = $this->orderFactory->create();
        $this->orderResource->load($order, $orderId);
        return $order->getId() ? $order : null;
    }

    /**
     * @inheritdoc
     */
    public function order(int $orderId): ?array
    {
        $order = $this->load($orderId);
        if ($order === null) {
            return null;
        }
        $payment = $order->getPayment();
        $items = [];
        foreach ($order->getAllVisibleItems() as $item) {
            $items[] = ['name' => (string)$item->getName(), 'quantity' => (string)(float)$item->getQtyOrdered()];
        }
        return [
            'id' => (int)$order->getId(),
            'number' => (string)$order->getIncrementId(),
            'store_id' => (int)$order->getStoreId(),
            'state' => (string)$order->getState(),
            'status' => (string)$order->getStatus(),
            'total' => (string)$order->getGrandTotal(),
            'base_total' => (string)$order->getBaseGrandTotal(),
            'currency' => (string)$order->getOrderCurrencyCode(),
            'base_currency' => (string)$order->getBaseCurrencyCode(),
            'ours' => $payment !== null && $payment->getMethod() === Config::METHOD,
            'test_buyer' => $payment !== null && (bool)$payment->getAdditionalInformation('coinspay_test_buyer'),
            'items' => $items,
        ];
    }

    /**
     * @inheritdoc
     */
    public function comment(int $orderId, string $comment): string
    {
        return $this->writing->run(function () use ($orderId, $comment): string {
            $order = $this->load($orderId);
            $this->note($order, $comment, false);
            $this->orderRepository->save($order);
            return (string)$order->getStatus();
        });
    }

    /**
     * @inheritdoc
     */
    public function setStatus(int $orderId, string $status, string $comment): ?string
    {
        return $this->writing->run(function () use ($orderId, $status, $comment): ?string {
            $order = $this->load($orderId);
            if (!$this->config->statusExists($status, (string)$order->getState())) {
                return null;
            }
            $order->setStatus($status);
            $this->note($order, $comment, $status);
            $this->orderRepository->save($order);
            return (string)$order->getStatus();
        });
    }

    /**
     * @inheritdoc
     */
    public function pay(int $orderId, string $invoiceId, string $status, string $comment): ?string
    {
        return $this->writing->run(function () use ($orderId, $invoiceId, $status, $comment): ?string {
            $order = $this->load($orderId);
            // A dispute put the order on hold, and now an invoice of it is paid in full.
            if ($order->getState() === Order::STATE_HOLDED && $order->canUnhold()) {
                $order->unhold();
            }
            if (!$order->canInvoice()) {
                return null;
            }

            $payment = $order->getPayment();
            $payment->setTransactionId($invoiceId);
            $payment->setLastTransId($invoiceId);
            $payment->setIsTransactionClosed(true);
            $invoice = $this->invoiceService->prepareInvoice($order);
            $invoice->setRequestedCaptureCase(Invoice::CAPTURE_OFFLINE);
            $invoice->setTransactionId($invoiceId);
            $invoice->register();

            $order->setState(Order::STATE_PROCESSING);
            $status = $status !== '' && $this->config->statusExists($status, Order::STATE_PROCESSING)
                ? $status
                : $this->config->defaultStatus(Order::STATE_PROCESSING);
            $order->setStatus($status);
            $this->note($order, $comment, $status);
            $this->transactionFactory->create()->addObject($invoice)->addObject($order)->save();

            $this->sendEmails($order, $invoice);
            // Saving may move a paid order of virtual goods to "complete": the status it got.
            $saved = $this->load($orderId);
            return (string)($saved ? $saved->getStatus() : $order->getStatus());
        });
    }

    /**
     * @inheritdoc
     */
    public function cancel(int $orderId, string $status, string $comment): ?string
    {
        return $this->writing->run(function () use ($orderId, $status, $comment): ?string {
            $order = $this->load($orderId);
            if (!$order->canCancel()) {
                return null;
            }
            $order->cancel();
            if ($this->config->statusExists($status, Order::STATE_CANCELED)) {
                $order->setStatus($status);
            }
            $this->note($order, $comment, (string)$order->getStatus());
            $this->orderRepository->save($order);
            return (string)$order->getStatus();
        });
    }

    /**
     * @inheritdoc
     */
    public function hold(int $orderId, string $status, string $comment): ?string
    {
        return $this->writing->run(function () use ($orderId, $status, $comment): ?string {
            $order = $this->load($orderId);
            if ($order->getState() === Order::STATE_CANCELED) {
                if (!$this->config->statusExists($status, Order::STATE_CANCELED)) {
                    return null;
                }
            } elseif ($order->canHold()) {
                $order->hold();
                if (!$this->config->statusExists($status, Order::STATE_HOLDED)) {
                    $status = (string)$order->getStatus();
                }
            } else {
                return null;
            }
            $order->setStatus($status);
            $this->note($order, $comment, $status);
            $this->orderRepository->save($order);
            return (string)$order->getStatus();
        });
    }

    /**
     * @inheritdoc
     */
    public function returnUrl(int $orderId, string $token): string
    {
        $order = $this->load($orderId);
        return $this->storefrontUrl('coinspay/payment/back', $order ? (int)$order->getStoreId() : null, [
            'order' => $orderId,
            'token' => $token,
        ]);
    }

    /**
     * A storefront link of a store view.
     *
     * @param string $route
     * @param int|null $storeId
     * @param array $query
     * @return string
     */
    public function storefrontUrl(string $route, ?int $storeId, array $query = []): string
    {
        $params = ['_nosid' => true, '_secure' => true];
        if ($storeId !== null) {
            $params['_scope'] = $storeId;
        }
        if ($query) {
            $params['_query'] = $query;
        }
        return $this->url->getUrl($route, $params);
    }

    /**
     * @inheritdoc
     */
    public function siteUrl(): string
    {
        return (string)$this->storeManager->getDefaultStoreView()->getBaseUrl();
    }

    /**
     * @inheritdoc
     */
    public function log(string $message, bool $error): void
    {
        if ($error) {
            $this->logger->error($message);
        } elseif ($this->config->flag('debug', null)) {
            $this->logger->info($message);
        }
    }

    /**
     * Adds a comment for the staff to the order history.
     *
     * @param Order $order
     * @param string $comment
     * @param string|false $status
     * @return void
     */
    private function note(Order $order, string $comment, $status): void
    {
        $history = $order->addCommentToStatusHistory($comment, $status, false);
        $history->setIsCustomerNotified(false);
    }

    /**
     * The order e-mail, if it waited for the payment, and the invoice e-mail, if the store sends them.
     *
     * @param Order $order
     * @param Invoice $invoice
     * @return void
     */
    private function sendEmails(Order $order, Invoice $invoice): void
    {
        try {
            if (!$order->getEmailSent()) {
                $this->orderSender->send($order);
            }
            $this->invoiceSender->send($invoice);
        } catch (\Exception $e) {
            $this->logger->error(sprintf('order %d: e-mails not sent: %s', $order->getId(), $e->getMessage()));
        }
    }
}
