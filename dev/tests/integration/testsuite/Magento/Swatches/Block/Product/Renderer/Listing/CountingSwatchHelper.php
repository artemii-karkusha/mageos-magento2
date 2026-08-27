<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Swatches\Block\Product\Renderer\Listing;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Swatches\Helper\Data;

/**
 * Counting stand-in for the swatch helper, used by CacheKeyCostTest.
 *
 * Delegates to the real implementation, so behaviour is unchanged; it only
 * records how often the configurable attribute set was actually loaded.
 */
class CountingSwatchHelper extends Data
{
    /**
     * @var int
     */
    private int $attributeLoads = 0;

    /**
     * How many times the configurable attribute set was loaded.
     *
     * @return int
     */
    public function attributeLoads(): int
    {
        return $this->attributeLoads;
    }

    /**
     * Forget the count so far.
     *
     * @return void
     */
    public function resetCount(): void
    {
        $this->attributeLoads = 0;
    }

    /**
     * @inheritdoc
     */
    public function getAttributesFromConfigurable(ProductInterface $product)
    {
        $this->attributeLoads++;

        return parent::getAttributesFromConfigurable($product);
    }
}
