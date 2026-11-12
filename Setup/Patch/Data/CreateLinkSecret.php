<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Setup\Patch\Data;

use CoinsPay\Payment\Model\Core\Util;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * The secret of the module's own links (return, pay again, test buyer), encrypted like a password.
 */
class CreateLinkSecret implements DataPatchInterface
{
    private const PATH = 'payment/coinspay/link_secret';

    /** @var ModuleDataSetupInterface */
    private $setup;

    /** @var WriterInterface */
    private $writer;

    /** @var EncryptorInterface */
    private $encryptor;

    /**
     * @param ModuleDataSetupInterface $setup
     * @param WriterInterface $writer
     * @param EncryptorInterface $encryptor
     */
    public function __construct(ModuleDataSetupInterface $setup, WriterInterface $writer, EncryptorInterface $encryptor)
    {
        $this->setup = $setup;
        $this->writer = $writer;
        $this->encryptor = $encryptor;
    }

    /**
     * @inheritdoc
     */
    public function apply()
    {
        $connection = $this->setup->getConnection();
        $select = $connection->select()
            ->from($this->setup->getTable('core_config_data'), ['value'])
            ->where('path = ?', self::PATH)
            ->where('scope = ?', 'default');
        if ((string)$connection->fetchOne($select) === '') {
            $this->writer->save(self::PATH, $this->encryptor->encrypt(Util::randomHex(32)));
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
