<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\ConfigurableProduct\Model\Product;

use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * Request-scoped answers to "does this configurable have a salable child?".
 *
 * Configurable::isSalable() answers that with a COUNT over the parent's linked
 * products, once per configurable. A listing of 48 configurables therefore runs
 * 48 counts - 14.2 ms of database time on one Luma category page - for a
 * question that can be asked about the whole page at once.
 *
 * This pool holds the answers a page-level batch has already worked out. It
 * only ever answers for parents it was explicitly given, so a miss is a normal
 * outcome and callers must keep their existing path as a fallback.
 */
class SalableChildPool implements ResetAfterRequestInterface
{
    /**
     * @var array<int|string, array<int, bool>> keyed by store, then parent id
     */
    private array $hasSalableChild = [];

    /**
     * Record what a batch worked out for one store.
     *
     * @param int|string $storeKey
     * @param array $answersByParentId keyed by parent link-field value
     * @return void
     */
    public function set($storeKey, array $answersByParentId): void
    {
        foreach ($answersByParentId as $parentId => $answer) {
            $this->hasSalableChild[$storeKey][(int)$parentId] = (bool)$answer;
        }
    }

    /**
     * Whether this parent is known to have a salable child.
     *
     * Returns null when the pool was never told about it, which callers must
     * treat as "find out the usual way" rather than as "no".
     *
     * @param int|string $storeKey
     * @param int $parentId
     * @return bool|null
     */
    public function get($storeKey, int $parentId): ?bool
    {
        return $this->hasSalableChild[$storeKey][$parentId] ?? null;
    }

    /**
     * @inheritdoc
     */
    public function _resetState(): void
    {
        $this->hasSalableChild = [];
    }
}
