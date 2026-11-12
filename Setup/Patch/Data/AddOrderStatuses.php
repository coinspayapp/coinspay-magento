<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Setup\Patch\Data;

use CoinsPay\Payment\Model\Core\Settings;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Sales\Model\Order;

/**
 * The module's own order statuses: "Awaiting crypto payment" for a placed order (pending_payment),
 * "Crypto payment review" for a dispute (on hold, or cancelled when the money came after the cancel).
 */
class AddOrderStatuses implements DataPatchInterface
{
    /** @var ModuleDataSetupInterface */
    private $setup;

    /**
     * @param ModuleDataSetupInterface $setup
     */
    public function __construct(ModuleDataSetupInterface $setup)
    {
        $this->setup = $setup;
    }

    /**
     * @inheritdoc
     */
    public function apply()
    {
        $statuses = [
            Settings::STATUS_AWAITING => ['Awaiting crypto payment', [Order::STATE_PENDING_PAYMENT]],
            Settings::STATUS_REVIEW => ['Crypto payment review', [Order::STATE_HOLDED, Order::STATE_CANCELED]],
        ];
        $connection = $this->setup->getConnection();
        $statusTable = $this->setup->getTable('sales_order_status');
        $stateTable = $this->setup->getTable('sales_order_status_state');
        foreach ($statuses as $code => [$label, $states]) {
            $connection->insertOnDuplicate($statusTable, ['status' => $code, 'label' => $label], ['label']);
            foreach ($states as $state) {
                $connection->insertOnDuplicate(
                    $stateTable,
                    ['status' => $code, 'state' => $state, 'is_default' => 0, 'visible_on_front' => 1],
                    ['visible_on_front']
                );
            }
        }
        return $this;
    }

    /**
     * @inheritdoc
     */
    public static function getDependencies()
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function getAliases()
    {
        return [];
    }
}
