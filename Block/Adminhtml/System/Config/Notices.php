<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Block\Adminhtml\System\Config;

use CoinsPay\Payment\Model\Config;
use CoinsPay\Payment\Model\Kits;
use CoinsPay\Payment\Model\Repo;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * The top of the settings: what keeps the method from working right in the scope of the page.
 */
class Notices extends Scoped
{
    /** A due poll late by this many seconds means the Magento cron does not run. */
    private const CRON_LATE = 1800;

    /** @var Kits */
    private $kits;

    /** @var Config */
    private $config;

    /** @var Repo */
    private $repo;

    /** @var ProductMetadataInterface */
    private $productMetadata;

    /**
     * @param Context $context
     * @param Kits $kits
     * @param Config $config
     * @param Repo $repo
     * @param ProductMetadataInterface $productMetadata
     * @param array $data
     */
    public function __construct(
        Context $context,
        Kits $kits,
        Config $config,
        Repo $repo,
        ProductMetadataInterface $productMetadata,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->kits = $kits;
        $this->config = $config;
        $this->repo = $repo;
        $this->productMetadata = $productMetadata;
    }

    /**
     * @inheritdoc
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        $storeId = $this->scopeStoreId();
        $settings = $this->kits->forStore($storeId)->settings();
        $notices = [];
        if (!$settings->configured() || $settings->webhookSecret() === '') {
            $notices[] = ['warning', __('Enter the API key and the webhook secret to start taking payments.')];
        }
        if ($settings->isTestMode()) {
            $notices[] = ['notice', __('Test mode: the key is a test one (cpk_test_). Invoices are paid with a button on the payment page, no coins move.')];
        }
        $broken = [];
        foreach ($settings->brokenEvents() as $event) {
            $broken[] = $event;
        }
        if ($broken) {
            $notices[] = ['warning', __('The order status is not set or no longer belongs to its order state for: %1. Until you choose a status, such events only add a comment (a payment still invoices the order).', implode(', ', $broken))];
        }
        $pendingLifetime = $this->config->pendingPaymentLifetime($storeId);
        if ($settings->configured() && $pendingLifetime > 0 && $pendingLifetime < $settings->lifetime()) {
            $notices[] = ['warning', __('Magento cancels pending payment orders after %1 minutes (Sales → Sales → Orders Cron Settings), sooner than the time to pay (%2 minutes). Make the order lifetime longer or the time to pay shorter.', $pendingLifetime, $settings->lifetime())];
        }
        if ($this->repo->overdue(self::CRON_LATE) > 0) {
            $notices[] = ['warning', __('The Magento cron does not seem to run: open invoices wait for their check. Webhooks still work; set up the cron of Magento so payments are not missed when a webhook is lost.')];
        }
        if (!$notices) {
            $notices[] = ['success', __('Ready to take payments.')];
        }

        $html = '';
        foreach ($notices as [$kind, $text]) {
            $html .= '<div class="message message-' . $kind . ' ' . $kind . '"><div>' . $this->escapeHtml($text) . '</div></div>';
        }
        $version = __('CoinsPay %1 for Magento %2', Config::VERSION, $this->productMetadata->getVersion());
        return $html . '<p class="note"><span>' . $this->escapeHtml($version) . '</span></p>';
    }
}
