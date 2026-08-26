<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\ConfigurableProduct\Model\Plugin;

use Magento\Catalog\Block\Product\ListProduct;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\ConfigurableProduct\Model\Product\LoadedProductPool;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable as ConfigurableResource;
use Magento\Framework\Data\Collection\AbstractDb;

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
     * @var ConfigurableResource
     */
    private $configurableResource;

    /**
     * @var CollectionFactory
     */
    private $collectionFactory;

    /**
     * @param LoadedProductPool $pool
     * @param ConfigurableResource $configurableResource
     * @param CollectionFactory $collectionFactory
     */
    public function __construct(
        LoadedProductPool $pool,
        ConfigurableResource $configurableResource,
        CollectionFactory $collectionFactory
    ) {
        $this->pool = $pool;
        $this->configurableResource = $configurableResource;
        $this->collectionFactory = $collectionFactory;
    }

    /**
     * Preload the parents of everything the listing is about to render.
     *
     * @param ListProduct $subject
     * @param AbstractDb $collection
     * @return AbstractDb
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterGetLoadedProductCollection(
        ListProduct $subject,
        AbstractDb $collection
    ): AbstractDb {
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

        $parentIds = array_unique(
            array_map('intval', $this->configurableResource->getParentIdsByChild($childIds))
        );
        $parentIds = array_diff($parentIds, $childIds);
        if (!$parentIds) {
            return $collection;
        }

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
}
