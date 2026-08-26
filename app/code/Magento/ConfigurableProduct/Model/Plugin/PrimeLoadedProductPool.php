<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\ConfigurableProduct\Model\Plugin;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Block\Product\ListProduct;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\ConfigurableProduct\Model\Product\LoadedProductPool;
use Magento\ConfigurableProduct\Model\Product\ParentLinkCache;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\Collection;
use Magento\Framework\EntityManager\MetadataPool;

/**
 * Page-level preload of the configurable parents a listing will need.
 *
 * A category listing of configurables renders the child variants, not the
 * parents — measured on Luma's "Tops - Women": 47 simples and 1 configurable.
 * Each child's getIdentities() asks ProductIdentitiesExtender for its parents'
 * cache tags, and the plugin loads each parent individually through
 * ProductRepository: a full model load per product on the page.
 *
 * The parents are never loaded by the page, so there is nothing to reuse —
 * they must be fetched. This fetches them for the whole page in two queries
 * instead of one full load each:
 *
 *   1. one SELECT resolving every parent id (getParentIdsByChild already
 *      accepts an array), and
 *   2. one collection load for those parents.
 */
class PrimeLoadedProductPool
{
    /**
     * @var LoadedProductPool
     */
    private $pool;

    /**
     * @var CollectionFactory
     */
    private $collectionFactory;

    /**
     * @var ParentLinkCache
     */
    private $linkCache;

    /**
     * @var ResourceConnection
     */
    private $resource;

    /**
     * @var MetadataPool
     */
    private $metadataPool;

    /**
     * @param LoadedProductPool $pool
     * @param CollectionFactory $collectionFactory
     * @param ParentLinkCache $linkCache
     * @param ResourceConnection $resource
     * @param MetadataPool $metadataPool
     */
    public function __construct(
        LoadedProductPool $pool,
        CollectionFactory $collectionFactory,
        ParentLinkCache $linkCache,
        ResourceConnection $resource,
        MetadataPool $metadataPool
    ) {
        $this->pool = $pool;
        $this->collectionFactory = $collectionFactory;
        $this->linkCache = $linkCache;
        $this->resource = $resource;
        $this->metadataPool = $metadataPool;
    }

    /**
     * Preload the parents of everything the listing is about to render.
     *
     * ListProduct::getLoadedProductCollection() declares no return type and a
     * block may be handed any collection through setCollection() - core's own
     * ListTest does exactly that with a plain Data\Collection. A plugin must not
     * narrow that contract, so the parameter stays untyped and anything without
     * items to inspect is passed straight back.
     *
     * @param ListProduct $subject
     * @param mixed $collection
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterGetLoadedProductCollection(ListProduct $subject, $collection)
    {
        if (!$collection instanceof Collection) {
            return $collection;
        }

        $childIds = [];
        foreach ($collection->getItems() as $product) {
            $id = (int)$product->getId();
            if ($id > 0) {
                $childIds[] = $id;
            }
        }

        if (!$childIds) {
            return $collection;
        }

        // One query for the whole page, keyed by child, so the extender does not
        // repeat getParentIdsByChild() once per product.
        $parentIdsByChild = $this->loadParentMap($childIds);
        $this->linkCache->setParentIdsByChild($parentIdsByChild);

        $parentIds = [];
        foreach ($parentIdsByChild as $ids) {
            foreach ($ids as $id) {
                $parentIds[$id] = $id;
            }
        }
        $parentIds = array_keys($parentIds);
        if (!$parentIds) {
            return $collection;
        }

        // Parents that appear in the listing themselves are deliberately NOT
        // skipped. Reusing the listing's own instance looks free, but a listing
        // collection selects its own attribute set and does not guarantee the
        // orig-data state Product::getIdentities() depends on, so a pooled
        // listing item could yield different cache tags. Loading every parent
        // through the controlled select below costs one extra query for the
        // page and keeps the tags provably identical.

        // One query for the website assignments the extender checks per parent
        // whenever the store is not the admin store.
        $this->linkCache->setWebsiteIds($this->loadWebsiteMap($parentIds));

        $parents = $this->collectionFactory->create();
        $parents->addIdFilter($parentIds);
        // getIdentities() reads the id, status and news_from_date. Everything
        // else it touches is save-time state that a read never sets.
        $parents->addAttributeToSelect(['status', 'news_from_date']);
        $parents->load();

        foreach ($parents as $parent) {
            // A repository read leaves orig data equal to current data, which is
            // what makes Product::getIdentities() skip its status-changed and
            // is-new branches. A collection load does not guarantee that, and
            // without it a pooled parent would report a status change and yield
            // different cache tags than the repository would have.
            $parent->setOrigData();
            $this->pool->add($parent);
        }

        return $collection;
    }

    /**
     * Map each child on the page to its parent ids, in one query.
     *
     * ConfigurableResource::getParentIdsByChild() accepts an array but returns a
     * flat list, losing which parent belongs to which child. The mapping is what
     * lets the extender answer per child without querying again.
     *
     * @param int[] $childIds
     * @return array<int, int[]>
     */
    private function loadParentMap(array $childIds): array
    {
        $connection = $this->resource->getConnection();
        $linkField = $this->metadataPool
            ->getMetadata(ProductInterface::class)
            ->getLinkField();

        $select = $connection->select()
            ->from(['l' => $this->resource->getTableName('catalog_product_super_link')], ['product_id'])
            ->join(
                ['e' => $this->resource->getTableName('catalog_product_entity')],
                'e.' . $linkField . ' = l.parent_id',
                ['entity_id']
            )
            ->where('l.product_id IN (?)', $childIds, \Zend_Db::INT_TYPE);

        // Seed with empty arrays so a child with no parents is a cache HIT
        // rather than a miss that falls back to a per-child query.
        $map = array_fill_keys($childIds, []);
        foreach ($connection->fetchAll($select) as $row) {
            $map[(int)$row['product_id']][] = (int)$row['entity_id'];
        }

        return $map;
    }

    /**
     * Map each product to its website ids, in one query.
     *
     * @param int[] $productIds
     * @return array<int, int[]>
     */
    private function loadWebsiteMap(array $productIds): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName('catalog_product_website'), ['product_id', 'website_id'])
            ->where('product_id IN (?)', $productIds, \Zend_Db::INT_TYPE);

        $map = array_fill_keys($productIds, []);
        foreach ($connection->fetchAll($select) as $row) {
            $map[(int)$row['product_id']][] = (int)$row['website_id'];
        }

        return $map;
    }
}
