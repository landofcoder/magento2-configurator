<?php
namespace Lof\Configurator\Component;

use Lof\Configurator\Api\ComponentInterface;
use Lof\Configurator\Api\LoggerInterface;
use Lof\Configurator\Console\Command\ProductReviewCommand;
use Lof\Configurator\Model\Review;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\File\Csv;
use Magento\Framework\Setup\SampleData\Context as SampleDataContext;

class ProductReviews implements ComponentInterface
{
    protected $alias = 'product_reviews';
    protected $name = 'Product reviews';
    protected $description = 'Component to install product reviews.';

    /**
     * @var LoggerInterface
     */
    private $log;

    /**
     * @var Review
     */
    private $review;

    /**
     * @var ProductReviewCommand
     */
    private $reviewCommand;

    /**
     * @var Csv
     */
    private Csv $csvReader;

    /**
     * @var DirectoryList
     */
    private $directoryList;

    /**
     * @param LoggerInterface $log
     * @param Review $review
     * @param ProductReviewCommand $reviewCommand
     * @param SampleDataContext $sampleDataContext
     * @param DirectoryList $directoryList
     */
    public function __construct(
        LoggerInterface $log,
        Review $review,
        ProductReviewCommand $reviewCommand,
        SampleDataContext $sampleDataContext,
        DirectoryList $directoryList,
    ) {
        $this->log = $log;
        $this->review = $review;
        $this->reviewCommand = $reviewCommand;
        $this->csvReader = $sampleDataContext->getCsvReader();
        $this->directoryList = $directoryList;
    }

    /**
     * @param null $data
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function execute($data)
    {
        $this->log->logInfo('Starting to install product reviews...');
        try {
            if ((count($data) == 0) || !isset($data) || ($data[0] == false)) {
                $this->reviewCommand->generateReviews() ;
                $fileName = $this->directoryList->getPath(DirectoryList::APP) . $this->reviewCommand::DIRECTORY_PATH;
                $data = $this->csvReader->getData($fileName);
            }
            $this->log->logInfo('Installing product reviews...');
            $this->review->install($data);
        } catch (\Exception $e){
            $this->log->logError($e->getMessage());
        }
    }
    /**
     * @inheritDoc
     */
    public function getAlias()
    {
        return $this->alias;
    }

    /**
     * @inheritDoc
     */
    public function getDescription()
    {
        return $this->description;
    }
}
