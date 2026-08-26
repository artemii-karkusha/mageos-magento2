<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\ConfigurableProduct\Model\Product;

use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * Request-scoped link data preloaded for a product listing.
 *
 * Holds two maps that ProductIdentitiesExtender would otherwise build one
 * product at a time:
 *
 *   - child id  -> configurable parent ids
 *   - product id -> website ids
 *
 * A miss is a normal outcome — nothing preloads these outside a listing — so
 * every reader must keep its original per-product query as a fallback. That is
 * why the getters distinguish "not preloaded" (null) from "preloaded and empty"
 * (an empty array): a child with no parents is a real answer, not a miss.
 */
class ParentLinkCache implements ResetAfterRequestInterface
{
    /**
     * @var array<int, int[]>
     */
    private array $parentIdsByChild = [];

    /**
     * @var array<int, int[]>
     */
    private array $websiteIdsByProduct = [];

    /**
     * Store the preloaded child-to-parents map, keyed by child id.
     *
     * @param array $map
     * @return void
     */
    public function setParentIdsByChild(array $map): void
    {
        $this->parentIdsByChild = $map + $this->parentIdsByChild;
    }

    /**
     * Parent ids for a child, or null when it was not preloaded.
     *
     * @param int $childId
     * @return int[]|null
     */
    public function getParentIdsByChild(int $childId): ?array
    {
        return array_key_exists($childId, $this->parentIdsByChild)
            ? $this->parentIdsByChild[$childId]
            : null;
    }

    /**
     * Store the preloaded product-to-websites map, keyed by product id.
     *
     * @param array $map
     * @return void
     */
    public function setWebsiteIds(array $map): void
    {
        $this->websiteIdsByProduct = $map + $this->websiteIdsByProduct;
    }

    /**
     * Website ids for a product, or null when it was not preloaded.
     *
     * @param int $productId
     * @return int[]|null
     */
    public function getWebsiteIds(int $productId): ?array
    {
        return array_key_exists($productId, $this->websiteIdsByProduct)
            ? $this->websiteIdsByProduct[$productId]
            : null;
    }

    /**
     * @inheritDoc
     */
    public function _resetState(): void
    {
        $this->parentIdsByChild = [];
        $this->websiteIdsByProduct = [];
    }
}
