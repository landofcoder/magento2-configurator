<?php
namespace Lof\Configurator\Component;

use Lof\Configurator\Api\ComponentInterface;
use Lof\Configurator\Api\LoggerInterface;
use Lof\MarketPlace\Api\Data\SellerInterface;
use Lof\MarketPlace\Model\SellerFactory;
use Lof\MarketplaceGraphQl\Model\Resolver\DataProvider\CreateSeller;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\CustomerFactory;
use Magento\Framework\App\ResourceConnection;

class Sellers implements ComponentInterface
{
    /**
     * Define the number of seller to create
     */
    const SELLER_GENERATE = 200;

    protected $alias = 'sellers';
    protected $name = 'Sellers';
    protected $description = 'Component to install sellers using existed customer.';

    /**
     * @var LoggerInterface
     */
    private $log;

    /**
     * @var CustomerFactory
     */
    private $customerFactory;

    /**
     * @var SellerInterface
     */
    private $sellerInterface;

    /**
     * @var CreateSeller
     */
    private $createSeller;

    /**
     * @var SellerFactory
     */
    private $sellerFactory;

    /**
     * @var CustomerRepositoryInterface
     */
    private $customerRepository;

    /**
     * @var Customers
     */
    private $customersComponent;

    /**
     * @param LoggerInterface $log
     * @param CustomerFactory $customerFactory
     * @param SellerInterface $sellerInterface
     * @param CreateSeller $createSeller
     * @param SellerFactory $sellerFactory
     * @param CustomerRepositoryInterface $customerRepository
     * @param Customers $customersComponent
     */
    public function __construct(
        LoggerInterface $log,
        CustomerFactory $customerFactory,
        SellerInterface $sellerInterface,
        CreateSeller $createSeller,
        SellerFactory $sellerFactory,
        CustomerRepositoryInterface $customerRepository,
        Customers $customersComponent,
        ResourceConnection $resourceConnection
    ) {
        $this->log = $log;
        $this->customerFactory = $customerFactory;
        $this->sellerInterface = $sellerInterface;
        $this->createSeller = $createSeller;
        $this->sellerFactory = $sellerFactory;
        $this->customerRepository = $customerRepository;
        $this->customersComponent = $customersComponent;
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * @param null $data
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function execute($data)
    {
        $this->log->logInfo('Starting to install seller data');
        $this->customersComponent->execute($data);

        $this->log->logInfo('Starting to assign seller');
        try {
            $collection = $this->customerFactory->create()->getCollection();
            $collection->addOrder('entity_id', 'DESC');
            $collection->getSelect()->limit(self::SELLER_GENERATE);
            foreach ($collection as $customer) {
                $sellerInterface = $this->sellerInterface;
                $sellerInterface
                    ->setEmail($customer->getEmail())
                    ->setName($customer->getFirstname() . ' ' . $customer->getLastname())
                    ->setGroup(1)
                    ->setUrl(strtolower($customer->getFirstname()) . '-' . strtolower($customer->getLastname()). '-' . $customer->getId())
                    ->setCustomerId($customer->getId());

                $customerAddress = $this->customerRepository->getById($customer->getId())->getAddresses()[0];

                $this->createSeller->createSeller($sellerInterface, $customer->getId());
                $sellerModel = $this->sellerFactory->create()->load($customer->getId(), "customer_id");
                $sellerModel->setImage('https://via.placeholder.com/1000x700.png?text='.$customer->getFirstname().' '.$customer->getLastname())
                    ->setThumbnail('https://via.placeholder.com/500x500.png?text='.$customer->getFirstname().' '.$customer->getLastname())
                    ->setCountryId($customerAddress->getCountryId())
                    ->setCity($customerAddress->getCity())
                    ->setPostcode($customerAddress->getPostcode())
                    ->setTelephone($customerAddress->getTelephone())
                    ->setRegionId($customerAddress->getRegionId());
                $sellerModel->save();
                //update seller shipping settings
                $connection = $this->resourceConnection->getConnection();
                $table = $connection->getTableName('lof_marketplace_seller_settings');
                $query = "INSERT INTO ".$table."(`seller_id`, `group`, `key`, `value`, `serialized`, `scope`, `scope_id`, `path`) VALUES (".$sellerModel->getId().",'shipping','shipping/address/country_id','".$customerAddress->getCountryId()."',0,'default',0,'general')";
                $connection->query($query);
                $query = "INSERT INTO ".$table."(`seller_id`, `group`, `key`, `value`, `serialized`, `scope`, `scope_id`, `path`) VALUES (".$sellerModel->getId().",'shipping','shipping/address/region_id','".$customerAddress->getRegionId()."',0,'default',0,'general')";
                $connection->query($query);
                $query = "INSERT INTO ".$table."(`seller_id`, `group`, `key`, `value`, `serialized`, `scope`, `scope_id`, `path`) VALUES (".$sellerModel->getId().",'shipping','shipping/address/city','".$customerAddress->getCity()."',0,'default',0,'general')";
                $connection->query($query);
                $query = "INSERT INTO ".$table."(`seller_id`, `group`, `key`, `value`, `serialized`, `scope`, `scope_id`, `path`) VALUES (".$sellerModel->getId().",'shipping','shipping/address/postcode','".$customerAddress->getPostcode()."',0,'default',0,'general')";
                $connection->query($query);
                $this->log->logInfo('Installed Seller: '. $customer->getFirstname(). ' ' .$customer->getLastname());
            }
        } catch (\Exception $e) {
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
