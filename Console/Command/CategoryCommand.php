<?php
namespace Lof\Configurator\Console\Command;

use Lof\Configurator\Helper\Generated;
use Magento\Framework\App\Area;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\State;
use Magento\Framework\HTTP\ZendClientFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\Framework\Exception\LocalizedException;
use Symfony\Component\Yaml\Yaml;

class CategoryCommand extends Command
{
    const LEVEL_CATEGORY = 3;
    const LEVEL_1_CATEGORY_QTY = 12;
    const LEVEL_2_CATEGORY_QTY = 15;
    const LEVEL_3_CATEGORY_QTY = 5;
    const DIRECTORY_PATH = "/etc/configurator/Components/Categories/categories.yaml";
    const FAKE_CATEGORY_API = 'https://api.mockaroo.com/api/e41912f0';
    const FAKE_CATEGORY_API_KEY = 'bbf6a440';
    const DEFAULT_IMAGE = 'https://via.placeholder.com/1000x700.png';
    const API_MAX_COUNT = 5000;

    public $categoriesData = [];

    /**
     * @var State
     */
    private $state;

    /**
     * @var Generated
     */
    private $generatedHelper;

    public $cate= [];

    /**
     * @param State $state
     * @param Generated $generatedHelper
     */
    public function __construct(
        State $state,
        ZendClientFactory $httpClientFactory,
        Json $jsonSerializer,
        DirectoryList $directoryList,
        Yaml $yaml
    ) {
        $this->state = $state;
        $this->httpClientFactory = $httpClientFactory;
        $this->jsonSerializer = $jsonSerializer;
        $this->directoryList = $directoryList;
        $this->yaml = $yaml;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('configurator:category:generate')
            ->setDescription('Generate Category Data');
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
        try {
            $output->writeln('<info>Starting to generate customer data</info>');
            $category = $this->generateCategories();
            $this->generateYaml($category);
            $output->writeln('<info>Completed!</info>');
        } catch (LocalizedException | \Exception $e) {
            $output->writeln($e->getMessage());
        }
        return $this;
    }

    public function generateYaml($data) {
        $fileName = $this->directoryList->getPath(DirectoryList::APP) . self::DIRECTORY_PATH;
        $yaml = $this->yaml->dump($data, 7);
        file_put_contents($fileName, $yaml);
    }

    public function generateCategories(){
        $dataFinal['categories'] = [
            'store_group' => 'Main Website Store'
        ];
        $this->setCategoryData();
        $currentData = [];
        for ($level_1 = 0; $level_1 < self::LEVEL_1_CATEGORY_QTY; $level_1++){
            $currentData[] = $this->getData();
        }
        //lv2
        foreach ($currentData as $key => $value) {
            for ($level_2 = 0; $level_2 < self::LEVEL_2_CATEGORY_QTY; $level_2++) {
                $currentData[$key]['categories'][] = $this->getData();
            }
        }
        //lv3
        foreach ($currentData as $key => $value) {
            foreach ($value['categories'] as $cateKey1 => $cateValue1) {
                for ($level_3 = 0; $level_3 < self::LEVEL_3_CATEGORY_QTY; $level_3++) {
                    $currentData[$key]['categories'][$cateKey1]['categories'][] = $this->getData();
                }
            }
        }
        //lv4
//        foreach ($currentData as $key => $value) {
//            foreach ($value['categories'] as $cateKey1 => $cateValue1) {
//                foreach ($cateValue1['categories'] as $cateKey2 => $cateValue2){
//                    for ($level = 1; $level <= self::DEFAULT_CATEGORY_PER_LEVEL; $level++) {
//                        $currentData[$key]['categories'][$cateKey1]['categories'][$cateKey2]['categories'][] = $this->getData();
//                    }
//                }
//            }
//        }
        //lv5
//        foreach ($currentData as $key => $value) {
//            foreach ($value['categories'] as $cateKey1 => $cateValue1) {
//                foreach ($cateValue1['categories'] as $cateKey2 => $cateValue2){
//                    foreach ($cateValue2['categories'] as $cateKey3 => $cateValue3){
//                        for ($level = 1; $level <= self::DEFAULT_CATEGORY_PER_LEVEL; $level++) {
//                            $currentData[$key]['categories'][$cateKey1]['categories'][$cateKey2]['categories'][$cateKey3]['categories'][] = $this->getData();
//                        }
//                    }
//                }
//            }
//        }
        $dataFinal['categories']['categories'] = $currentData;
        return $dataFinal;
    }

    public function setCategoryData(){
        $categoriesQty = $this->calculateNumberOfCategories();
        $this->categoriesData = $this->getCategoryDataApi($categoriesQty);
    }

    public function getData(){
        if (count($this->categoriesData) > 0){
            $data = $this->categoriesData[0];
            unset($this->categoriesData[0]);
            $this->categoriesData = array_values($this->categoriesData);
            $data['image'] = self::DEFAULT_IMAGE;
            return $data;
        }
        return false;
    }

    public function getCategoryDataApi($qty) {
        $url = self::FAKE_CATEGORY_API . '?count=' . $qty . '&key=' . self::FAKE_CATEGORY_API_KEY;
        $client = $this->httpClientFactory->create();
        $client->setUri($url);
        $client->setMethod(\Zend_Http_Client::GET);
        $client->setHeaders(\Zend_Http_Client::CONTENT_TYPE, 'application/json');
        $client->setHeaders('Accept', 'application/json');
        $response = $client->request();
        return $this->jsonSerializer->unserialize($response->getBody());
    }

    public function calculateNumberOfCategories(){
        $total = 0;
        for ($lv1 = 0; $lv1 < self::LEVEL_1_CATEGORY_QTY; $lv1++){
            $total++;
            for ($lv2 = 0; $lv2 < self::LEVEL_2_CATEGORY_QTY; $lv2++){
                $total++;
                for ($lv3 = 0; $lv3 < self::LEVEL_3_CATEGORY_QTY; $lv3++){
                    $total++;
                }
            }
        }
        return $total;
    }
}
