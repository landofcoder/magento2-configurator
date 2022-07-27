<?php

namespace Lof\Configurator\Component;

use Lof\Configurator\Api\ComponentInterface;
use Lof\Configurator\Api\LoggerInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Api\Data\ProductLinkInterfaceFactory;
use Lof\Configurator\Exception\ComponentException;

class ProductLinks implements ComponentInterface
{
    protected $alias = 'product_links';
    protected $name = 'Product Links';
    protected $description = 'Component to create and maintain product links (related/up-sells/cross-sells)';

    /**
     * @var ProductLinkInterfaceFactory
     */
    protected $productLinkFactory;

    /**
     * @var ProductRepositoryInterface
     */
    protected $productRepository;

    /**
     * @var LoggerInterface
     */
    private $log;

    protected $allowedLinks = ['relation', 'up_sell', 'cross_sell'];
    protected $linkTypeMap = ['relation' => 'related', 'up_sell' => 'upsell', 'cross_sell' => 'crosssell'];

    /**
     * ProductLinks constructor.
     * @param ProductRepositoryInterface $productRepository
     * @param ProductLinkInterfaceFactory $productLinkFactory
     * @param LoggerInterface $log
     */
    public function __construct(
        ProductRepositoryInterface $productRepository,
        ProductLinkInterfaceFactory $productLinkFactory,
        LoggerInterface $log
    ) {
        $this->productRepository = $productRepository;
        $this->productLinkFactory = $productLinkFactory;
        $this->log = $log;
    }

    /**
     * Process the data by splitting up the different link types.
     *
     * @param $data
     */
    public function execute($data = null)
    {
        try {
            // Loop through all the product link types - if there are multiple link types in the yaml file
            foreach ($data as $linkType => $skus) {
                // Validate the link type to see if it is allowed
                if (!in_array($linkType, $this->allowedLinks)) {
                    throw new ComponentException(sprintf('Link type %s is not supported', $linkType));
                }

                // Process creating the links
                $this->processSkus($skus, $linkType);
            }
        } catch (ComponentException $e) {
            $this->log->logError($e->getMessage());
        } catch (\Exception $e) {
            $this->log->logError($e->getMessage());
        }
    }

    /**
     * Process an array of products that require products linking to them
     *
     * @param array $data
     * @param $linkType
     */
    private function processSkus(array $data, $linkType)
    {
        try {
            // Loop through the SKUs in the link type
            foreach ($data as $sku => $linkSkus) {
                // Check if the product exists
                if (!$this->doesProductExist($sku)) {
                    throw new ComponentException(sprintf('SKU (%s) for products to link to is not found', $sku));
                }
                $this->log->logInfo(sprintf('Creating product links for %s', $sku));

                // Process the links for that product
                $this->processLinks($sku, $linkSkus, $linkType);
            }
        } catch (ComponentException $e) {
            $this->log->logError($e->getMessage());
        } catch (\Exception $e) {
            $this->log->logError($e->getMessage());
        }
    }

    /**
     * Process all the SKUs that need to be linked to a particular product (SKU)
     *
     * @param $sku
     * @param $linkSkus
     * @param $linkType
     */
    private function processLinks($sku, $linkSkus, $linkType)
    {
        try {
            $productLinks = [];

            // Loop through all the products that require linking to a product
            foreach ($linkSkus as $position => $linkSku) {
                // Check if the product exists
                if (!$this->doesProductExist($linkSku)) {
                    throw new ComponentException(sprintf('SKU (%s) to link does not exist', $linkSku));
                }

                // Create an array of product link objects
                $productLinks[] = $this->productLinkFactory->create()->setSku($sku)
                    ->setLinkedProductSku($linkSku)
                    ->setLinkType($this->linkTypeMap[$linkType])
                    ->setPosition($position * 10);
                $this->log->logInfo($linkSku, 1);
            }

            // Save product links onto the main product
            $this->productRepository->get($sku)->setProductLinks($productLinks)->save();
            $this->log->logComment(sprintf('Saved product links for %s', $sku), 1);
        } catch (ComponentException $e) {
            $this->log->logError($e->getMessage(), 1);
        } catch (\Exception $e) {
            $this->log->logError($e->getMessage(), 1);
        }
    }

    /**
     * Check if the product exists function
     *
     * @param string $sku
     * @return bool
     * @todo find an efficient way to check if the product exists.
     */
    private function doesProductExist($sku)
    {
        if ($this->productRepository->get($sku)->getId()) {
            return true;
        }
        return false;
    }

    /**
     * @return string
     */
    public function getAlias()
    {
        return $this->alias;
    }

    /**
     * @return string
     */
    public function getDescription()
    {
        return $this->description;
    }
}
