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
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
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
     * @param ConfigurableType $configurableType
     * @param ProductRepositoryInterface $productRepository
     * @param ProductWebsiteLink $productWebsiteLink
     */
    public function __construct(
        ConfigurableType $configurableType,
        ProductRepositoryInterface $productRepository,
        ProductWebsiteLink $productWebsiteLink
    ) {
        $this->configurableType = $configurableType;
        $this->productRepository = $productRepository;
        $this->productWebsiteLink = $productWebsiteLink;
    }

    /**
     * Add parent identities to product identities
     *
     * The parent's cache tag is derived from its id rather than by loading the
     * product. Product::getIdentities() gates every branch that could add more
     * than [CACHE_TAG . '_' . id, CACHE_TAG] on state that only a save
     * produces — getOrigData() differing from getData(), getIsChangedCategories(),
     * isStockStatusChanged(), isObjectNew() — and none of those is ever true for
     * a product just read from the repository. The bare CACHE_TAG is already in
     * $identities whenever the parent would have contributed it, because the
     * subject added it under the same frontend-area condition.
     *
     * Loading the parent therefore cost a full model load (10 SELECTs measured)
     * to produce one predictable string. On a category page of configurables
     * that ran once per product: ~470 of 948 SELECTs on a 48-product page.
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
                $parentWebsiteIds = $this->productWebsiteLink->getWebsiteIdsByProductId($parentId);
                $addParentIdentities = in_array($store->getWebsiteId(), $parentWebsiteIds);
            }
            if ($addParentIdentities) {
                $parentProductsIdentities[] = [Product::CACHE_TAG . '_' . $parentId];
            }
        }
        $identities = array_merge($identities, ...$parentProductsIdentities);

        return array_unique($identities);
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
            $this->cacheParentIdsByChild[$childId] = $this->configurableType->getParentIdsByChild($childId);
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
