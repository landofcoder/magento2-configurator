<?php
namespace Lof\Configurator\Console\Command;

use Lof\Configurator\Api\LoggerInterface;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Customer\Model\CustomerFactory;
use Magento\Framework\App\Area;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\State;
use Magento\Framework\File\Csv;
use Magento\Framework\HTTP\ZendClientFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\Framework\Exception\LocalizedException;

class ProductReviewCommand extends Command
{
    const MAX_QTY_PER_PRODUCTS = 7;
    const MIN_QTY_PER_PRODUCTS = 2;
    const DIRECTORY_PATH = "/etc/configurator/Components/ProductReviews/reviews.csv";
    const FAKE_PRODUCT_API = 'https://api.mockaroo.com/api/4b78e8f0';
    const FAKE_PRODUCT_API_KEY = 'bbf6a440';
    const DEFAULT_IMAGE = 'https://via.placeholder.com/1000x700.png';
    const API_MAX_COUNT = 5000;

    public array $productReviewData = [];
    public array $customerData = [];
    public int $totalReviews = 0;
    public int $totalProductReviews = 0;
    public array $header = [
        'sku',
        'rating_code',
        'rating_value',
        'title',
        'review',
        'reviewer',
        'email'
    ];


    /**
     * @var State
     */
    private $state;

    /**
     * @var CollectionFactory
     */
    private $productCollectionFactory;

    /**
     * @var Visibility
     */
    private $productVisibility;

    /**
     * @var DirectoryList
     */
    private $directoryList;

    /**
     * @var Csv
     */
    private $csvProcessor;

    /**
     * @var ZendClientFactory
     */
    private $httpClientFactory;

    /**
     * @var Json
     */
    private $jsonSerializer;

    /**
     * @var LoggerInterface
     */
    private $log;

    /**
     * @var CustomerFactory
     */
    private $customerFactory;

    /**
     * @param State $state
     * @param CollectionFactory $productCollectionFactory
     * @param Visibility $productVisibility
     * @param DirectoryList $directoryList
     * @param Csv $csvProcessor
     * @param ZendClientFactory $httpClientFactory
     * @param Json $jsonSerializer
     * @param LoggerInterface $log
     * @param CustomerFactory $customerFactory
     */
    public function __construct(
        State $state,
        CollectionFactory $productCollectionFactory,
        Visibility $productVisibility,
        DirectoryList     $directoryList,
        Csv $csvProcessor,
        ZendClientFactory $httpClientFactory,
        Json $jsonSerializer,
        LoggerInterface $log,
        CustomerFactory $customerFactory
    ) {
        $this->state = $state;
        $this->productCollectionFactory = $productCollectionFactory;
        $this->productVisibility = $productVisibility;
        $this->directoryList = $directoryList;
        $this->csvProcessor = $csvProcessor;
        $this->httpClientFactory = $httpClientFactory;
        $this->jsonSerializer = $jsonSerializer;
        $this->log = $log;
        $this->customerFactory = $customerFactory;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('configurator:product-review:generate')
            ->setDescription('Generate Product Review Data');
    }

    /**
     * @inheritdoc
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return $this
     * @throws LocalizedException
     */
    public function execute(InputInterface $input, OutputInterface $output)
    {
        $this->state->setAreaCode(Area::AREA_ADMINHTML);
        try {
            $output->writeln('<info>Starting to generate product review data</info>');
            $this->generateReviews();
            $this->log->logInfo('Total review: '.$this->totalReviews);
            $output->writeln('<info>Completed!</info>');
        } catch (LocalizedException | \Exception $e) {
            $output->writeln($e->getMessage());
        }
        return $this;
    }

    public function generateReviews() {
        $this->log->logInfo('Prepare product data...');
        $productCollection = $this->getProductCollection();
        $this->totalProductReviews = count($productCollection);
        $this->log->logInfo('Total product: '. $this->totalProductReviews);
        $this->log->logInfo('Prepare review data...');
        $this->getReviewData($this->totalProductReviews);
        $this->log->logInfo('Prepare customer data...');
        $this->getAllCustomerData();
        $this->log->logInfo('Prepare csv file...');
        $this->prepareCsvFile();
        $this->log->logInfo('Generating product review data...');
        foreach ($productCollection->getItems() as $product) {
            $sku = $product->getData('sku');
            $rand = rand(self::MIN_QTY_PER_PRODUCTS, self::MAX_QTY_PER_PRODUCTS);
            for ($i = 0; $i < $rand; $i++) {
                $this->appendReviewData($sku);
            }
        }
    }

    public function appendReviewData($sku) {
        $filePath = $this->directoryList->getPath(DirectoryList::APP) . self::DIRECTORY_PATH;
        $apiData = $this->getProductReviewData();
        $customerData = $this->getCustomerData();
        $data[] = [
            $sku,
            'Rating',
            $apiData['rating_value'],
            $apiData['title'],
            $apiData['review'],
            $customerData['lastname'],
            $customerData['email']
        ];
        $this->csvProcessor->setEnclosure('"')->setDelimiter(',')
            ->appendData($filePath, $data, 'a');
        $this->totalReviews++;
    }

    public function getCustomerData(){
        return $this->customerData[array_rand($this->customerData,1)];
    }

    public function getAllCustomerData(){
        $collection = $this->customerFactory->create()->getCollection()
            ->addAttributeToSelect("*")->load();
        foreach ($collection as $customer) {
            $this->customerData[] = [
                'lastname' => $customer->getData('lastname'),
                'email' => $customer->getData('email')
            ];
        }
    }

    public function getProductReviewData(){
        if (count($this->productReviewData) > 0){
            $data = $this->productReviewData[0];
            unset($this->productReviewData[0]);
            $this->productReviewData = array_values($this->productReviewData);
            return $data;
        }
        return false;
    }

    public function getReviewData($qty){
        $totalItem = $qty * self::MAX_QTY_PER_PRODUCTS;
        $send = [];
        $data = [];
        while ($totalItem > self::API_MAX_COUNT) {
            $send[] = self::API_MAX_COUNT;
            $totalItem -= self::API_MAX_COUNT;
        }
        $send[] = $totalItem;
        foreach ($send as $countPerTime) {
            $data = array_merge($data,$this->getDataApi($countPerTime));
        }
        $this->productReviewData = $data;
    }

    public function getDataApi($qty) {
        $url = self::FAKE_PRODUCT_API . '?count=' . $qty . '&key=' . self::FAKE_PRODUCT_API_KEY;
        $client = $this->httpClientFactory->create();
        $client->setUri($url);
        $client->setMethod(\Zend_Http_Client::GET);
        $client->setHeaders(\Zend_Http_Client::CONTENT_TYPE, 'application/json');
        $client->setHeaders('Accept', 'application/json');
        $response = $client->request();
        return $this->jsonSerializer->unserialize($response->getBody());
    }

    public function getProductCollection(){
        $collection = $this->productCollectionFactory->create();
        $collection->setVisibility($this->productVisibility->getVisibleInSiteIds());
        return $collection;
    }

    public function prepareCsvFile() {
        $filePath = $this->directoryList->getPath(DirectoryList::APP) . self::DIRECTORY_PATH;
        $header[] = $this->header;
        if (file_exists($filePath)) {
            $data = $this->csvProcessor->getData($filePath);
            if (count($data) == 0) {
                $this->csvProcessor->saveData($filePath, $header);
            }
        }else{
            $this->csvProcessor->saveData($filePath, $header);
        }
    }


}
