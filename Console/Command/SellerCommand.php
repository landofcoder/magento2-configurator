<?php
namespace Lof\Configurator\Console\Command;

use Lof\Configurator\Component\Sellers;
use Lof\Configurator\Helper\Generated;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\Framework\Exception\LocalizedException;

class SellerCommand extends Command
{
    const DIRECTORY_PATH = "/etc/configurator/Components/Sellers/sellers.csv";

    /**
     * @var State
     */
    private $state;

    /**
     * @var Generated
     */
    private $generatedHelper;

    /**
     * @var Sellers
     */
    private $sellersComponent;

    /**
     * @param State $state
     * @param Generated $generatedHelper
     * @param Sellers $sellersComponent
     */
    public function __construct(
        State $state,
        Generated $generatedHelper,
        Sellers $sellersComponent
    ) {
        $this->state = $state;
        $this->generatedHelper = $generatedHelper;
        $this->sellersComponent = $sellersComponent;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('configurator:seller:generate');
        $this->setDescription('Generate seller Data');
    }

    /**
     * @inheritdoc
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return $this
     * @throws LocalizedException
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->state->setAreaCode(Area::AREA_ADMINHTML);
        $qty = $this->sellersComponent::SELLER_GENERATE;

        if ($qty === null || !$qty > 0) {
            return $output->writeln('<error>Please specify number of sellers to generate</error>');
        }

        try {
            $output->writeln('<info>Starting to generate seller data</info>');
            $this->generatedHelper->generateCustomers($qty, self::DIRECTORY_PATH);
            $output->writeln('<info>Completed!</info>');
        } catch (LocalizedException | \Exception $e) {
            $output->writeln($e->getMessage());
        }
        return $this;
    }


}
