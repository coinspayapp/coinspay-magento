<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Console\Command;

use CoinsPay\Payment\Model\Poller;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento coinspay:sync — asks CoinsPay about the invoices due now, as the cron does.
 */
class Sync extends Command
{
    /** @var Poller */
    private $poller;

    /** @var State */
    private $appState;

    /**
     * @param Poller $poller
     * @param State $appState
     */
    public function __construct(Poller $poller, State $appState)
    {
        $this->poller = $poller;
        $this->appState = $appState;
        parent::__construct();
    }

    /**
     * @inheritdoc
     */
    protected function configure(): void
    {
        $this->setName('coinspay:sync');
        $this->setDescription('Asks CoinsPay about open invoices whose webhook did not arrive');
        parent::configure();
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Invoices and e-mails of a paid order need an area, as in the cron.
        $checked = $this->appState->emulateAreaCode(Area::AREA_CRONTAB, [$this->poller, 'run']);
        $output->writeln(sprintf('CoinsPay: %d invoice(s) checked.', $checked));
        return 0;
    }
}
