<?php

namespace Lof\Configurator\Console\Command;

use Lof\Configurator\Api\LoggerInterface;
use Lof\Configurator\Model\Processor;
use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Catalog\Model\ResourceModel\Category\Collection;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use Magento\Framework\App\Area;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\State;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\File\Csv;
use Magento\Framework\HTTP\ZendClientFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\Framework\Exception\LocalizedException;


class ProductCommand extends Command
{
    const QTY = 5000; //the number of product will be created
    const SIMPLE_PRODUCT_RATE = 70; // percent of simple products, configurable products will be 100% - this value
    const CONFIGURABLE_CHILD_PRODUCT_COUNT = 6; // the number of child configurable product
    const SIMPLE_PRODUCT_PATH = "/etc/configurator/Components/Products/simple.csv";
    const CONFIGURABLE_PRODUCT_PATH = "/etc/configurator/Components/Products/configurable.csv";
    const CATEGORY_PATH = "app/etc/configurator/Components/Categories/categories.yaml";
    const FAKE_PRODUCT_API = 'https://api.mockaroo.com/api/e5b78430';
    const FAKE_PRODUCT_API_KEY = '6b04ce10';
    const DEFAULT_IMAGE = 'https://via.placeholder.com/1000x700.png';
    const API_MAX_COUNT = 5000;

    public array $simpleProductHeader = [
        'attribute_set_code',
        'product_websites',
        'product_type',
        'sku',
        'name',
        'short_description',
        'description',
        'price',
        'url_key',
        'visibility',
        'meta_title',
        'meta_keywords',
        'meta_description',
        'image',
        'small_image',
        'thumbnail',
        'qty',
        'is_in_stock',
        'categories',
        'size',
        'color'
    ];
    public array $configurableProductHeader = [
        'attribute_set_code',
        'product_websites',
        'product_type',
        'sku',
        'name',
        'short_description',
        'description',
        'price',
        'url_key',
        'visibility',
        'meta_title',
        'meta_keywords',
        'meta_description',
        'associated_products',
        'configurable_attributes',
        'image',
        'small_image',
        'thumbnail',
        'qty',
        'is_in_stock',
        'categories'
    ];
    private $fakeProductData = [];
    private $allCategories;
    private $listCategories;
    private $simpleProducts = 0;
    private $configurableProducts = 0;
    private $totalProducts = 0;

    /**
     * @var State
     */
    private $state;

    /**
     * @var DirectoryList
     */
    private $directoryList;

    /**
     * @var CollectionFactory
     */
    private $categoryCollectionFactory;

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
     * @var ProductAttributeRepositoryInterface
     */
    private $attributeRepository;

    /**
     * @var LoggerInterface
     */
    private $log;

    /**
     * @param State $state
     * @param Processor $processor
     * @param DirectoryList $directoryList
     * @param CollectionFactory $categoryCollectionFactory
     * @param Csv $csvProcessor
     * @param ZendClientFactory $httpClientFactory
     * @param Json $jsonSerializer
     * @param ProductAttributeRepositoryInterface $attributeRepository
     * @param LoggerInterface $log
     */
    public function __construct(
        State             $state,
        Processor         $processor,
        DirectoryList     $directoryList,
        CollectionFactory $categoryCollectionFactory,
        Csv $csvProcessor,
        ZendClientFactory $httpClientFactory,
        Json $jsonSerializer,
        ProductAttributeRepositoryInterface $attributeRepository,
        LoggerInterface $log,
    )
    {
        $this->state = $state;
        $this->directoryList = $directoryList;
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->csvProcessor = $csvProcessor;
        $this->httpClientFactory = $httpClientFactory;
        $this->jsonSerializer = $jsonSerializer;
        $this->attributeRepository = $attributeRepository;
        $this->log = $log;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('configurator:product:generate')
            ->setDescription('Generate Customer Data');
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
        $qty = self::QTY;

        try {
            $output->writeln('<info>Prepare categories</info>');
            $this->setListCategories();
            $output->writeln('<info>Prepare product data</info>');
            $this->saveFakeProductData();
            $output->writeln('<info>Prepare csv file</info>');
            // Simple csv file
            $header[] = $this->simpleProductHeader;
            $filePath = $this->directoryList->getPath(DirectoryList::APP) . self::SIMPLE_PRODUCT_PATH;
            $this->prepareCsvFile($header,$filePath);
            // configurable csv file
            $header = [];
            $header[] = $this->configurableProductHeader;
            $filePath = $this->directoryList->getPath(DirectoryList::APP) . self::CONFIGURABLE_PRODUCT_PATH;
            $this->prepareCsvFile($header,$filePath);
            $output->writeln('<info>Starting to generate product data</info>');
            for ($i = 0; $i < $qty; $i++) {
                if ($this->isSimpleProduct()){
                    $this->generateSimpleProducts([]);
                } else{
                    $this->generateConfigurableProducts();
                }
            }
            $this->log->logInfo('Simple product: ' . $this->simpleProducts);
            $this->log->logInfo('Configurable product: ' . $this->configurableProducts);
            $this->log->logInfo('Total product: ' . $this->totalProducts);
            $output->writeln('<info>Completed!</info>');
        } catch (LocalizedException | \Exception $e) {
            $output->writeln($e->getMessage());
        }
        return $this;
    }

    /**
     * Set list category to global variable
     *
     * @throws LocalizedException
     */
    public function setListCategories(){
        $this->setAllCategories($this->getAllCategoriesCollection());
        $this->setAllCategoriesPath();
        $result = [];
        $categories = $this->getAllCategories();
        foreach ($categories as $category) {
            if ($category->getLevel() > 1){
                $result[] = $category->getData('path_name');
            }
        }
        $this->listCategories = $result;
    }

    /**
     * Generate simple products
     *
     * @param $productData
     * @throws FileSystemException
     */
    public function generateSimpleProducts($productData)
    {
        if (count($productData) == 0){
            $fakeData = $this->getFakeData();
            $fakeData['visibility'] = 'catalog, search';
            $fakeData['image'] = self::DEFAULT_IMAGE;
            $listCategoriesCount = count($this->listCategories) - 1;
            $fakeData['categories'] = $this->listCategories[rand(0, $listCategoriesCount)];
            $fakeData['color'] = null;
            $fakeData['size'] = null;
            $fakeData['sku'] = $this->generateRandomString() . '-' . $fakeData['random_num'];
            $productData = $fakeData;
            $this->simpleProducts++;
        }

        $filePath = $this->directoryList->getPath(DirectoryList::APP) . self::SIMPLE_PRODUCT_PATH;
        $data[] = [
            'Default',
            'base',
            'simple',
            $productData['sku'],
            $productData['product_name'],
            $productData['short_description'],
            $productData['description'],
            $productData['price'],
            $productData['sku'],
            $productData['visibility'],
            $productData['product_name'],
            $productData['sku'],
            $productData['short_description'],
            $productData['image'],
            $productData['image'],
            $productData['image'],
            100,
            1,
            $productData['categories'],
            $productData['size'],
            $productData['color']
        ];
        $this->generateProducts($data, $filePath);
    }

    /**
     * Generate configurable products
     *
     * @throws FileSystemException|NoSuchEntityException
     */
    public function generateConfigurableProducts(){
        $fakeData = $this->getFakeData();
        $fakeData['visibility'] = 'catalog, search';
        $fakeData['image'] = self::DEFAULT_IMAGE;
        $listCategoriesCount = count($this->listCategories) - 1;
        $fakeData['categories'] = $this->listCategories[rand(0, $listCategoriesCount)];
        $fakeData['sku'] = $this->generateRandomString() . '-' . $fakeData['random_num'];
        $productData = $fakeData;

        $colorValues = $this->getAttribute('color');

        $colorValuesLength = count($colorValues);
        $sizeValues = $this->getAttribute('size');
        $sizeValuesLength = count($sizeValues);

        $associated_products = [];
        for ($i=0; $i < self::CONFIGURABLE_CHILD_PRODUCT_COUNT; $i++){
            $size = rand(0, $sizeValuesLength-1);
            $color = rand(0, $colorValuesLength-1);
            $childSku = $this->generateChildConfigurableProduct($sizeValues[$size], $colorValues[$color]);
            $associated_products[] = $childSku;
        }
        if (count($associated_products) > 1) {
            $productData['associated_products'] = implode(',', $associated_products);
        }else{
            $productData['associated_products'] = $associated_products[0];
        }
        $productData['configurable_attributes'] = 'size,color';


        $filePath = $this->directoryList->getPath(DirectoryList::APP) . self::CONFIGURABLE_PRODUCT_PATH;
        $data[] = [
            'Default',
            'base',
            'configurable',
            $productData['sku'],
            $productData['product_name'],
            $productData['short_description'],
            $productData['description'],
            $productData['price'],
            $productData['sku'],
            $productData['visibility'],
            $productData['product_name'],
            $productData['sku'],
            $productData['short_description'],
            $productData['associated_products'],
            $productData['configurable_attributes'],
            $productData['image'],
            $productData['image'],
            $productData['image'],
            100,
            1,
            $productData['categories']
        ];
        $this->generateProducts($data, $filePath);
        $this->configurableProducts++;
    }

    /**
     * Get attribute options by code
     *
     * @param $code
     * @return array
     * @throws NoSuchEntityException
     */
    public function getAttribute($code){
        $attribute = $this->attributeRepository->get($code)->getOptions();
        $result = [];
        foreach ($attribute as $option){
            if(($option->getValue() != null) && ($option->getLabel() != null)){
                $result[] = $option->getLabel();
            }
        }
        return $result;
    }

    /**
     * Generate child configurable products
     *
     * @param $size
     * @param $color
     * @return string
     * @throws FileSystemException
     */
    public function generateChildConfigurableProduct($size, $color){
        $childProductData = $this->getFakeData();
        $childProductData['visibility'] = 'not visible individually';
        $childProductData['categories'] = '';
        $childProductData['image'] = self::DEFAULT_IMAGE;
        $childProductData['sku'] = $this->generateRandomString() . '-' . $childProductData['random_num'];
        $childProductData['size'] = $size;
        $childProductData['color'] = $color;
        $this->generateSimpleProducts($childProductData);
        return $childProductData['sku'];
    }

    /**
     * Get fake product data
     *
     * @return false|mixed
     */
    public function getFakeData(){
        if (count($this->fakeProductData) > 0){
            $data = $this->fakeProductData[0];
            unset($this->fakeProductData[0]);
            $this->setFakeProductData(array_values($this->fakeProductData));
            return $data;
        }
        return false;
    }

    /**
     * Get random string
     *
     * @return string
     */
    function generateRandomString() {
        $length = 4;
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }

    /**
     * Save fake product data
     */
    public function saveFakeProductData(){
        $totalProductApi = self::QTY * (self::CONFIGURABLE_CHILD_PRODUCT_COUNT + 1);
        $send = [];
        $data = [];
        while ($totalProductApi > self::API_MAX_COUNT) {
            $send[] = self::API_MAX_COUNT;
            $totalProductApi -= self::API_MAX_COUNT;
        }
        $send[] = $totalProductApi;
        foreach ($send as $countPerTime) {
            $data = array_merge($data,$this->getFakeProductDataApi($countPerTime));
        }
        $this->setFakeProductData($data);
    }

    /**
     * Get fake product data from api
     *
     * @param $qty
     * @return array|bool|float|int|mixed|string|null
     * @throws \Zend_Http_Client_Exception
     */
    public function getFakeProductDataApi($qty) {
        if ($qty == null) {
            $qty = self::QTY;
        }
        $url = self::FAKE_PRODUCT_API . '?count=' . $qty . '&key=' . self::FAKE_PRODUCT_API_KEY;
        $client = $this->httpClientFactory->create();
        $client->setUri($url);
        $client->setMethod(\Zend_Http_Client::GET);
        $client->setHeaders(\Zend_Http_Client::CONTENT_TYPE, 'application/json');
        $client->setHeaders('Accept', 'application/json');
        $response = $client->request();
        return $this->jsonSerializer->unserialize($response->getBody());
    }

    /**
     * Check the csv file, create new if not existed
     *
     * @param $header
     * @param $filePath
     * @throws FileSystemException
     */
    public function prepareCsvFile($header, $filePath) {
        if (file_exists($filePath)) {
            $data = $this->csvProcessor->getData($filePath);
            if (count($data) == 0) {
                $this->csvProcessor->saveData($filePath, $header);
            }
        }else{
            $this->csvProcessor->saveData($filePath, $header);
        }
    }

    /**
     * Generate products
     *
     * @param $data
     * @param $filePath
     * @throws FileSystemException
     */
    public function generateProducts($data, $filePath)
    {
        $this->csvProcessor->setEnclosure('"')->setDelimiter(',')
            ->appendData($filePath, $data, 'a');
        $this->totalProducts++;
    }

    /**
     * Is simple product?
     *
     * @return bool
     */
    public function isSimpleProduct()
    {
        $random = rand(1, 100);
        if ($random <= self::SIMPLE_PRODUCT_RATE) {
            return true;
        }
        return false;
    }

    /**
     * Set categories data to global variables
     */
    public function setAllCategoriesPath()
    {
        $categories = $this->getAllCategories();
        foreach ($categories as $category) {
            $this->allCategories[$category->getId()]
                ->setData('path_name', $this->getFullPath($category->getPath()));
        }
    }

    /**
     * Get full path name from path id
     *
     * @param $path
     * @return string
     */
    public function getFullPath($path)
    {
        $path = explode('/', $path);
        $fullPath = [];
        foreach ($path as $id) {
            if (isset($this->allCategories[$id])) {
                array_push($fullPath, $this->allCategories[$id]->getData('name'));
            }
        }
        return implode('/', $fullPath);
    }

    /**
     * Get all categories
     *
     * @return Collection
     * @throws LocalizedException
     */
    function getAllCategoriesCollection()
    {
        $collection = $this->categoryCollectionFactory->create()
            ->addAttributeToSelect('*')
            ->setStore(1) //default store
            ->addAttributeToFilter('is_active', '1');
        return $collection;
    }

    /**
     * Set category data to global variable
     *
     * @param $categories
     */
    public function setAllCategories($categories)
    {
        $this->allCategories = $categories->getItems();
    }

    /**
     * Get category data from global variable
     *
     * @return mixed
     */
    public function getAllCategories()
    {
        return $this->allCategories;
    }

    /**
     * Set fake product data to global variable
     *
     * @param $data
     */
    public function setFakeProductData($data) {
        $this->fakeProductData = $data;
    }

}
