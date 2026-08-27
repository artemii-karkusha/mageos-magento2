<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\ConfigurableProduct\Model\Plugin;

use Magento\Catalog\Block\Product\ListProduct;
use Magento\ConfigurableProduct\Model\Product\SalableChildPool;
use Magento\ConfigurableProduct\Model\Product\Type\Collection\SalableProcessor;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable\Product\CollectionFactory;
use Magento\Framework\Data\Collection as DataCollection;
use Magento\Framework\EntityManager\MetadataPool;

/**
 * Work out, for the whole listing at once, which configurables have a salable child.
 *
 * Configurable::isSalable() asks that question with a COUNT over one parent's
 * linked products. The listing template asks it for every item, so a page of 48
 * configurables runs 48 counts.
 *
 * The child collection already supports several parents - setProductFilter()
 * appends, and _renderFilters() turns the lot into a single
 * "link_table.parent_id IN (...)" - and it selects parent_id. So the same
 * SalableProcessor applied to one collection over every parent on the page
 * gives the same answers in one query. Using the real processor rather than a
 * hand-written query is deliberate: the definition of "salable" stays in one
 * place.
 */
class PrimeSalableChildPool
{
    /**
     * @var SalableChildPool
     */
    private $pool;

    /**
     * @var CollectionFactory
     */
    private $collectionFactory;

    /**
     * @var SalableProcessor
     */
    private $salableProcessor;

    /**
     * @var MetadataPool
     */
    private $metadataPool;

    /**
     * @param SalableChildPool $pool
     * @param CollectionFactory $collectionFactory
     * @param SalableProcessor $salableProcessor
     * @param MetadataPool $metadataPool
     */
    public function __construct(
        SalableChildPool $pool,
        CollectionFactory $collectionFactory,
        SalableProcessor $salableProcessor,
        MetadataPool $metadataPool
    ) {
        $this->pool = $pool;
        $this->collectionFactory = $collectionFactory;
        $this->salableProcessor = $salableProcessor;
        $this->metadataPool = $metadataPool;
    }

    /**
     * Prime the pool from the listing the page is about to render.
     *
     * ListProduct::getLoadedProductCollection() declares no return type and a
     * block may be handed any collection through setCollection(), so anything
     * without items to inspect is passed straight back.
     *
     * @param ListProduct $subject
     * @param mixed $collection
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterGetLoadedProductCollection(ListProduct $subject, $collection)
    {
        if (!$collection instanceof DataCollection) {
            return $collection;
        }

        // The child collection filters on the link field, not the entity id -
        // they differ on Commerce - so the pool is keyed the same way.
        $linkField = $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();

        $parentsByStore = [];
        foreach ($collection->getItems() as $product) {
            $linkValue = $product->getData($linkField);
            if ($product->getTypeId() !== Configurable::TYPE_CODE || !$linkValue) {
                continue;
            }
            $storeKey = $product->getStoreId() === null ? 0 : (int)$product->getStoreId();
            $parentsByStore[$storeKey][(int)$linkValue] = $product;
        }

        foreach ($parentsByStore as $storeKey => $parents) {
            $this->pool->set($storeKey, $this->resolveSalableParents($storeKey, $parents));
        }

        return $collection;
    }

    /**
     * One query per store: which of these parents have at least one salable child.
     *
     * @param int $storeKey
     * @param array $parents keyed by parent id
     * @return array<int, bool> keyed by parent id
     */
    private function resolveSalableParents(int $storeKey, array $parents): array
    {
        $answers = array_fill_keys(array_keys($parents), false);

        $childCollection = $this->collectionFactory->create();
        foreach ($parents as $parent) {
            $childCollection->setProductFilter($parent);
        }
        $childCollection->addStoreFilter($storeKey);
        $childCollection = $this->salableProcessor->process($childCollection);

        $select = clone $childCollection->getSelect();
        $select->reset(\Magento\Framework\DB\Select::COLUMNS)
            ->columns(['parent_id' => 'link_table.parent_id'])
            // The collection only renders setProductFilter() into a
            // "parent_id IN (...)" during load(), and this never loads it, so the
            // restriction has to be applied here. Without it the query groups over
            // every configurable in the catalogue and merely happens to return a
            // superset of the right answer.
            ->where('link_table.parent_id IN (?)', array_keys($parents), \Zend_Db::INT_TYPE)
            ->group('link_table.parent_id');

        foreach ($childCollection->getConnection()->fetchCol($select) as $parentId) {
            $answers[(int)$parentId] = true;
        }

        return $answers;
    }
}
