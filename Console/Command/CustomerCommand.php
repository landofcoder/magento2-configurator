<?php
namespace Lof\Configurator\Console\Command;

use Lof\Configurator\Helper\Generated;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Magento\Framework\Exception\LocalizedException;

class CustomerCommand extends Command
{
    const QTY = 'qty';
    const DIRECTORY_PATH = "/etc/configurator/Components/Customers/customers.csv";

    /**
     * @var State
     */
    private $state;

    /**
     * @var Generated
     */
    private $generatedHelper;

    /**
     * @param State $state
     * @param Generated $generatedHelper
     */
    public function __construct(
        State $state,
        Generated $generatedHelper
    ) {
        $this->state = $state;
        $this->generatedHelper = $generatedHelper;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $options = [
            new InputOption(
                self::QTY,
                null,
                InputOption::VALUE_REQUIRED,
                'Number of customer to generate'
            ),
        ];

        $this->setName('configurator:customer:generate')
            ->setDescription('Generate Customer Data')
            ->setDefinition($options);

        parent::configure();
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
        $qty = $input->getOption(self::QTY);

        if ($qty === null || !$qty > 0) {
            return $output->writeln('<error>Please specify number of customers to generate</error>');
        }

        try {
            $output->writeln('<info>Starting to generate customer data</info>');
            $this->generatedHelper->generateCustomers($qty, self::DIRECTORY_PATH);
            $output->writeln('<info>Completed!</info>');
        } catch (LocalizedException | \Exception $e) {
            $output->writeln($e->getMessage());
        }
        return $this;
    }


}
