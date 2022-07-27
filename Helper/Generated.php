<?php
namespace Lof\Configurator\Helper;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\File\Csv;
use Magento\Framework\HTTP\ZendClientFactory;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Generated extends AbstractHelper
{
    const FAKE_USER_DATA_API_URL = 'https://random-data-api.com/api/users/random_user';
    const API_LIMIT_USER_DATA = 100;
    const DEFAULT_PASSWORD = '12345678';

    public array $customerDataMapping = [
        'email' => 'email',
        'firstname' => 'first_name',
        'lastname' => 'last_name',
        '_address_telephone' => 'phone_number',
        'dob'
    ];
    public array $customerDataAddressMapping = [
        '_address_city' => 'city',
        '_address_country_id' => 'country',
        '_address_postcode' => 'zip_code',
        '_address_region' => 'state',
        '_address_street' => 'street_address'
    ];

    /**
     * @var Csv
     */
    private $csvProcessor;

    /**
     * @var DirectoryList
     */
    private $directoryList;

    /**
     * @var ZendClientFactory
     */
    private $httpClientFactory;

    /**
     * @var Json
     */
    private $jsonSerializer;

    /**
     * @var EncryptorInterface
     */
    private $encryptor;

    /**
     * @param Context $context
     * @param Csv $csvProcessor
     * @param DirectoryList $directoryList
     * @param ZendClientFactory $httpClientFactory
     * @param Json $jsonSerializer
     * @param EncryptorInterface $encryptor
     */
    public function __construct(
        Context $context,
        Csv $csvProcessor,
        DirectoryList $directoryList,
        ZendClientFactory $httpClientFactory,
        Json $jsonSerializer,
        EncryptorInterface $encryptor
    ) {
        parent::__construct($context);
        $this->csvProcessor = $csvProcessor;
        $this->directoryList = $directoryList;
        $this->httpClientFactory   = $httpClientFactory;
        $this->jsonSerializer      = $jsonSerializer;
        $this->encryptor = $encryptor;
    }

    /**
     * Generate customers
     *
     * @param $qty
     * @param $dirPath
     * @throws FileSystemException|\Zend_Http_Client_Exception
     */
    public function generateCustomers($qty ,$dirPath)
    {
        $data[] = [
            'email',
            '_website',
            '_store',
            'firstname',
            'gender',
            'group_id',
            'lastname',
            '_address_city',
            '_address_country_id',
            '_address_firstname',
            '_address_lastname',
            '_address_postcode',
            '_address_region',
            '_address_street',
            '_address_telephone',
            '_address_default_billing_',
            '_address_default_shipping_',
            'dob',
            'password_hash'
        ];
        $filePath = $this->directoryList->getPath(DirectoryList::APP) .$dirPath;

        $dataApi = $this->getFakeData($qty);

        for ($i = 0; $i < $qty; $i++) {
            $data[] = [
                $dataApi[$i]['email'],
                'base',
                'default',
                $dataApi[$i]['first_name'],
                rand(1, 2),
                '1',
                $dataApi[$i]['last_name'],
                $dataApi[$i]['address']['city'],
                'US',
                $dataApi[$i]['first_name'],
                $dataApi[$i]['last_name'],
                $dataApi[$i]['address']['zip_code'],
                $dataApi[$i]['address']['state'],
                $dataApi[$i]['address']['street_address'],
                $dataApi[$i]['phone_number'],
                '1',
                '1',
                $dataApi[$i]['date_of_birth'],
                $this->encryptor->getHash(self::DEFAULT_PASSWORD, true)
            ];
        }

        $this->csvProcessor->setEnclosure('"')->setDelimiter(',')
            ->saveData($filePath, $data);
    }

    /**
     * @param $qty
     * @return array
     * @throws \Zend_Http_Client_Exception
     */
    public function getFakeData($qty) {
        $data = [];
        $limit = self::API_LIMIT_USER_DATA;
        $loop = 1;
        if ($qty > $limit) {
            $loop = ceil($qty/$limit) + 1;
        }
        for ($i = 0; $i < $loop; $i++) {
            if ($qty > 0) {
                $apiData = $this->getApiData($qty, $limit);
                if (count($apiData) > 0) {
                    foreach ($apiData as $item) {
                        array_push($data, $item);
                    }
                }
                $qty -= $limit;
            }
        }
        return $data;
    }

    /**
     * @param $size
     * @param $limit
     * @return array|bool|float|int|mixed|string|null
     * @throws \Zend_Http_Client_Exception
     */
    public function getApiData($size, $limit) {
        if ($size > $limit) {
            $size = $limit;
        }
        $url = self::FAKE_USER_DATA_API_URL . '?size=' . $size;
        $client = $this->httpClientFactory->create();
        $client->setUri($url);
        $client->setMethod(\Zend_Http_Client::GET);
        $client->setHeaders(\Zend_Http_Client::CONTENT_TYPE, 'application/json');
        $client->setHeaders('Accept', 'application/json');
        $response = $client->request();
        return $this->jsonSerializer->unserialize($response->getBody());
    }
}
