<?php
namespace Lof\Configurator\Component;

use Lof\Configurator\Api\ComponentInterface;
use Lof\MarketPlace\Model\SellerFactory;
use Lof\MarketPlace\Model\SellerProductManager;
use Magento\Catalog\Model\ProductFactory;
use Lof\Configurator\Api\LoggerInterface;
use Magento\ConfigurableProduct\Api\LinkManagementInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\StateException;
use Magento\Framework\App\ResourceConnection;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.ShortVariable)
 */
class SellerProducts implements ComponentInterface
{
    protected $alias = 'seller_products';
    protected $name = 'Seller products';
    protected $description = 'Component to install seller products using existed products.';

    private $listProduct = [];

    /**
     * @var ProductFactory
     */
    private $productFactory;

    /**
     * @var SellerFactory
     */
    private $sellerFactory;

    /**
     * @var LinkManagementInterface
     */
    private $linkManagement;

    /**
     * @var SellerProductManager
     */
    private $sellerProductManager;

    /**
     * @var LoggerInterface
     */
    private $log;

    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * Products constructor.
     * @param ProductFactory $productFactory
     * @param SellerFactory $sellerFactory
     * @param LinkManagementInterface $linkManagement
     * @param SellerProductManager $sellerProductManager
     * @param LoggerInterface $log
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        ProductFactory     $productFactory,
        SellerFactory $sellerFactory,
        LinkManagementInterface $linkManagement,
        SellerProductManager $sellerProductManager,
        LoggerInterface $log,
        ResourceConnection $resourceConnection
    ) {
        $this->productFactory = $productFactory;
        $this->sellerFactory = $sellerFactory;
        $this->linkManagement = $linkManagement;
        $this->sellerProductManager = $sellerProductManager;
        $this->log = $log;
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * @param null $data
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function execute($data)
    {
        try {
            $this->log->logInfo('Starting to assign seller product');
            $this->setListProduct($this->productFactory->create()->getCollection());
            $this->assignSellersProducts();
            $this->log->logInfo('Completed!');
        } catch (\Exception $e) {
            $this->log->logError($e->getMessage());
        }
    }

    /**
     * Assign Seller Product
     */
    public function assignSellersProducts() {
        $sellerCollection = $this->sellerFactory->create()->getCollection();
        $sellerCollection->getSelect()->where('true');
        $sellerIds = [];
        $customerIds = [];
        foreach ($sellerCollection as $seller) {
            $sellerIds[] = $seller->getId();
            $customerIds[$seller->getId()] = $seller->getCustomerId();
        }

        $productCollectionIds = $this->getProductAssignedToSeller();

        $sellerProductArr = [];
        foreach ($sellerIds as $sellerId) {
            array_push($sellerProductArr, [
                'sellerId' => $sellerId,
                'productIds' => []]
            );
        }
        $u = 0;
        $i = 0;
        $productCount = count($productCollectionIds);

        $sellerCount = count($sellerIds);
        while ($u < $productCount) {
            if (is_array($productCollectionIds[$u]) && (count($productCollectionIds[$u])) > 0) {
                foreach ($productCollectionIds[$u] as $childProduct) {
                    array_push($sellerProductArr[$i]['productIds'], $childProduct);
                }
            } else {
                array_push($sellerProductArr[$i]['productIds'], $productCollectionIds[$u]);
            }
            $i++;
            $u++;
            if ($i == $sellerCount) {
                $i = 0;
            }
        }

        foreach ($sellerProductArr as $sellerProduct) {
            foreach ($sellerProduct['productIds'] as $sellerProductId){
                $this->log->logInfo('Assigning product id: ' . $sellerProductId . ' to seller id: ' .$sellerProduct['sellerId']);
                $this->insertProductDataToSeller($sellerProductId, $sellerProduct['sellerId'], $customerIds[$sellerProduct['sellerId']]);
            }
        }
    }

    /**
     * Get Product Ids Assigned to Seller
     *
     * @return array
     */
    public function getProductAssignedToSeller() {
        $this->log->logInfo('Getting product assigned to seller');
        $productCollection = $this->listProduct;
        $childProductIds = [];
        $ignoreProductIds = [];

        $connection = $this->resourceConnection->getConnection();
        $table = $connection->getTableName('catalog_product_super_link');
        $query = "SELECT product_id, parent_id FROM ".$table." WHERE parent_id > 0";
        $fetchData = $connection->fetchAll($query);
        if (count($fetchData) > 0) {
            foreach ($fetchData as $rows) {
                $childProductIds[$rows['parent_id']][] = $rows['product_id'];
                $ignoreProductIds[$rows['product_id']] = $rows['product_id'];
            }
        }

        $productAssignedToSellerIds = [];
        foreach ($productCollection as $product) {
            if ($product->getIsSalable()) {
                if ($product->getTypeId() == 'configurable'){
                    $childProductIds[$product->getId()][] = $product->getId();
                    $productAssignedToSellerIds[] = $childProductIds[$product->getId()];
                } else {
                    if (!isset($ignoreProductIds[$product->getId()]))
                    $productAssignedToSellerIds[] = $product->getId();
                }
            }
        }
        return $productAssignedToSellerIds;
    }

    /**
     * Set product data to global variable
     *
     * @param $data
     */
    public function setListProduct($data) {
        $this->listProduct = $data;
    }

    /**
     * Insert product data to seller
     *
     * @param $productId
     * @param $sellerId
     * @param $customerId
     */
    public function insertProductDataToSeller($productId, $sellerId, $customerId){
        try {
            $connection = $this->resourceConnection->getConnection();
            $table = $connection->getTableName('lof_marketplace_product');
            $query = "INSERT INTO ".$table."(product_id, adminassign, seller_id, product_name, store_id, status, customer_id, commission) VALUES (".$productId.",0,".$sellerId.",'',1,2,".$customerId.",100)";
            $connection->query($query);

            // update data in table catalog_product_entity
            $table = $connection->getTableName('catalog_product_entity');
            $query = "UPDATE " . $table . " SET approval= 2, seller_id = ".$sellerId."  WHERE entity_id = ".$productId;
            $connection->query($query);
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
