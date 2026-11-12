<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Test\Unit;

use CoinsPay\Payment\Model\Core\Text;
use PHPUnit\Framework\TestCase;

/**
 * The Russian dictionary covers every phrase of the core, of the module's code and templates and of
 * its settings page, keeps their placeholders, and holds no generic Magento words it would
 * retranslate store-wide.
 */
class DictionaryTest extends TestCase
{
    /** Words of Magento itself: a module dictionary would change them in every screen. */
    private const GENERIC = ['Invoice', 'Amount', 'Paid', 'Status', 'Created', 'Refresh', 'test', 'Enabled', 'Title', 'Checkout', 'Advanced'];

    private function dictionary(): array
    {
        $root = dirname(__DIR__, 2);
        $handle = fopen($root . '/i18n/ru_RU.csv', 'rb');
        $pairs = [];
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $this->assertCount(2, $row, implode(' | ', $row));
            $this->assertArrayNotHasKey($row[0], $pairs, 'twice: ' . $row[0]);
            $pairs[$row[0]] = $row[1];
        }
        fclose($handle);
        return $pairs;
    }

    /**
     * Phrases of __() with a literal first argument in the module's PHP and templates, and of $t() in JS.
     *
     * @return array
     */
    private function phrasesOfCode(): array
    {
        $root = dirname(__DIR__, 2);
        $phrases = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            if (preg_match('#/(bin|Test|vendor|dist)/#', $path) || !preg_match('/\.(php|phtml|js)$/', $path)) {
                continue;
            }
            $source = (string)file_get_contents($path);
            preg_match_all("/(?:__|\\\$t)\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", $source, $matches);
            foreach ($matches[1] as $phrase) {
                $phrases[] = stripslashes($phrase);
            }
        }
        return array_unique($phrases);
    }

    /**
     * Labels, comments and tooltips of the settings page (etc/adminhtml/system.xml).
     *
     * @return array
     */
    private function phrasesOfSettings(): array
    {
        $xml = simplexml_load_file(dirname(__DIR__, 2) . '/etc/adminhtml/system.xml');
        $phrases = [];
        foreach ($xml->xpath('//label | //comment | //tooltip') as $node) {
            $text = trim((string)$node);
            if ($text !== '') {
                $phrases[] = $text;
            }
        }
        return array_unique($phrases);
    }

    public function testEveryPhraseIsTranslatedWithItsPlaceholders(): void
    {
        $dictionary = $this->dictionary();
        $phrases = array_merge(array_values(Text::PHRASES), $this->phrasesOfCode(), $this->phrasesOfSettings());
        foreach (array_unique($phrases) as $phrase) {
            if (in_array($phrase, self::GENERIC, true)) {
                continue;
            }
            $this->assertArrayHasKey($phrase, $dictionary, 'not translated: ' . $phrase);
            preg_match_all('/%\d+/', $phrase, $source);
            preg_match_all('/%\d+/', $dictionary[$phrase], $translated);
            sort($source[0]);
            sort($translated[0]);
            $this->assertSame($source[0], $translated[0], 'placeholders of: ' . $phrase);
        }
    }

    public function testNoGenericMagentoWords(): void
    {
        foreach (self::GENERIC as $word) {
            $this->assertArrayNotHasKey($word, $this->dictionary(), $word);
        }
    }
}
