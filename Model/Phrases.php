<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Model;

use CoinsPay\Payment\Model\Core\Text;
use Magento\Framework\File\Csv;
use Magento\Framework\Module\Dir;
use Magento\Framework\Module\Dir\Reader;

/**
 * Translations of the module's own dictionaries (i18n/<locale>.csv) for a given locale. Order
 * comments are written by webhooks, the cron and the admin alike: they take the locale of the
 * installation, not of whichever area writes them.
 */
class Phrases
{
    /** @var Reader */
    private $moduleReader;

    /** @var Csv */
    private $csv;

    /** @var array locale → phrase → translation */
    private $dictionaries = [];

    /**
     * @param Reader $moduleReader
     * @param Csv $csv
     */
    public function __construct(Reader $moduleReader, Csv $csv)
    {
        $this->moduleReader = $moduleReader;
        $this->csv = $csv;
    }

    /**
     * A phrase in the locale with the arguments in place of %1, %2…
     *
     * @param string $phrase
     * @param array $args
     * @param string $locale
     * @return string
     */
    public function translate(string $phrase, array $args, string $locale): string
    {
        $dictionary = $this->dictionary($locale);
        return Text::fill($dictionary[$phrase] ?? $phrase, $args);
    }

    /**
     * The dictionary of a locale; empty for English and unknown locales.
     *
     * @param string $locale
     * @return array
     */
    private function dictionary(string $locale): array
    {
        if (!preg_match('/^[a-z]{2,3}_[A-Za-z]{2,4}$/', $locale)) {
            return [];
        }
        if (!isset($this->dictionaries[$locale])) {
            $this->dictionaries[$locale] = [];
            $file = $this->moduleReader->getModuleDir(Dir::MODULE_I18N_DIR, 'CoinsPay_Payment') . '/' . $locale . '.csv';
            try {
                $this->dictionaries[$locale] = $this->csv->getDataPairs($file);
            } catch (\Exception $e) {
                $this->dictionaries[$locale] = [];
            }
        }
        return $this->dictionaries[$locale];
    }
}
