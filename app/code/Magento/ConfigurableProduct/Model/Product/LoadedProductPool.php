<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\ConfigurableProduct\Model\Product;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * Request-scoped pool of products already loaded during this request.
 *
 * A category listing loads every product it renders in one collection query.
 * Code further down the render then asks ProductRepository for some of those
 * same products by id, paying a full model load for each — on a page of
 * configurables that happens once per product.
 *
 * This pool lets such code reuse what the page already loaded. It only ever
 * answers for products it was explicitly given, so a miss is a normal outcome
 * and callers must keep their existing load path as a fallback.
 */
class LoadedProductPool implements ResetAfterRequestInterface
{
    /**
     * @var ProductInterface[] keyed by product id
     */
    private array $products = [];

    /**
     * Offer a product to the pool.
     *
     * @param ProductInterface $product
     * @return void
     */
    public function add(ProductInterface $product): void
    {
        $id = (int)$product->getId();
        if ($id > 0) {
            $this->products[$id] = $product;
        }
    }

    /**
     * Offer many products at once, e.g. a loaded listing collection.
     *
     * @param iterable $products
     * @return void
     */
    public function addMany(iterable $products): void
    {
        foreach ($products as $product) {
            if ($product instanceof ProductInterface) {
                $this->add($product);
            }
        }
    }

    /**
     * Returns the product if this request already loaded it, otherwise null.
     *
     * @param int $productId
     * @return ProductInterface|null
     */
    public function get(int $productId): ?ProductInterface
    {
        return $this->products[$productId] ?? null;
    }

    /**
     * @inheritDoc
     */
    public function _resetState(): void
    {
        $this->products = [];
    }
}
