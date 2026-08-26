<?php
/**
 * Copyright 2018 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\ConfigurableProduct\Model\Plugin;

use Magento\Catalog\Model\Product\Type as ProductTypes;
use Magento\Catalog\Model\ResourceModel\Product\Website\Link as ProductWebsiteLink;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable as ConfigurableType;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\ConfigurableProduct\Model\Product\LoadedProductPool;
use Magento\ConfigurableProduct\Model\Product\ParentLinkCache;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Store\Model\Store;

/**
 *  Extender of product identities for child of configurable products
 */
class ProductIdentitiesExtender implements ResetAfterRequestInterface
{
    /**
     * @var ConfigurableType
     */
    private $configurableType;

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var ProductWebsiteLink
     */
    private $productWebsiteLink;

    /**
     * @var array
     */
    private $cacheParentIdsByChild = [];

    /**
     * @var LoadedProductPool
     */
    private $loadedProductPool;

    /**
     * @var ParentLinkCache
     */
    private $parentLinkCache;

    /**
     * @param ConfigurableType $configurableType
     * @param ProductRepositoryInterface $productRepository
     * @param ProductWebsiteLink $productWebsiteLink
     * @param LoadedProductPool|null $loadedProductPool
     * @param ParentLinkCache|null $parentLinkCache
     */
    public function __construct(
        ConfigurableType $configurableType,
        ProductRepositoryInterface $productRepository,
        ProductWebsiteLink $productWebsiteLink,
        ?LoadedProductPool $loadedProductPool = null,
        ?ParentLinkCache $parentLinkCache = null
    ) {
        $this->configurableType = $configurableType;
        $this->productRepository = $productRepository;
        $this->productWebsiteLink = $productWebsiteLink;
        // Optional and last so the constructor stays backward compatible for
        // anything that instantiates this plugin directly.
        $this->loadedProductPool = $loadedProductPool
            ?? ObjectManager::getInstance()->get(LoadedProductPool::class);
        $this->parentLinkCache = $parentLinkCache
            ?? ObjectManager::getInstance()->get(ParentLinkCache::class);
    }

    /**
     * Add parent identities to product identities
     *
     * @param Product $subject
     * @param array $identities
     * @return array
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterGetIdentities(Product $subject, array $identities): array
    {
        if ($subject->getTypeId() !== ProductTypes::TYPE_SIMPLE) {
            return $identities;
        }

        $store = $subject->getStore();
        $parentProductsIdentities = [];
        foreach ($this->getParentIdsByChild($subject->getId()) as $parentId) {
            $addParentIdentities = true;
            if (Store::DEFAULT_STORE_ID !== (int) $store->getId()) {
                $parentWebsiteIds = $this->parentLinkCache->getWebsiteIds((int)$parentId)
                    ?? $this->productWebsiteLink->getWebsiteIdsByProductId($parentId);
                $addParentIdentities = in_array($store->getWebsiteId(), $parentWebsiteIds);
            }
            if ($addParentIdentities) {
                $parentProductsIdentities[] = $this->getParentProduct((int)$parentId)->getIdentities();
            }
        }
        $identities = array_merge($identities, ...$parentProductsIdentities);

        return array_unique($identities);
    }

    /**
     * Resolve the parent product, preferring one this request already loaded.
     *
     * On a category page the parents are the listing's own products, so the
     * pool answers every lookup and the repository is never touched. Elsewhere
     * — a product page, a widget, an API call — the pool is empty and this
     * falls back to the original behaviour.
     *
     * @param int $parentId
     * @return \Magento\Catalog\Api\Data\ProductInterface
     */
    private function getParentProduct(int $parentId)
    {
        $pooled = $this->loadedProductPool->get($parentId);

        return $pooled ?? $this->productRepository->getById($parentId);
    }

    /**
     * Get parent ids by child with cache use
     *
     * @param int $childId
     * @return array
     */
    private function getParentIdsByChild($childId)
    {
        if (!isset($this->cacheParentIdsByChild[$childId])) {
            // A listing preloads the whole page's map in one query; outside a
            // listing nothing does, and this falls back to the per-child query.
            $this->cacheParentIdsByChild[$childId] = $this->parentLinkCache->getParentIdsByChild((int)$childId)
                ?? $this->configurableType->getParentIdsByChild($childId);
        }

        return $this->cacheParentIdsByChild[$childId];
    }

    /**
     * @inheritDoc
     */
    public function _resetState(): void
    {
        $this->cacheParentIdsByChild = [];
    }
}
