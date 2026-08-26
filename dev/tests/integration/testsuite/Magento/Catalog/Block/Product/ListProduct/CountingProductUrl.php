<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Catalog\Block\Product\ListProduct;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Url;

/**
 * Counting stand-in for the product URL model, used by UrlBuildCountTest.
 *
 * It delegates every call, so the listing renders exactly as it would in
 * production; it only records how often a URL was built and with which
 * parameters. Declaring no constructor of its own lets the ObjectManager
 * build it from Url's own signature, so it stays correct if that signature
 * changes.
 */
class CountingProductUrl extends Url
{
    /**
     * @var array<int, array> parameters of every getUrl() call, in order
     */
    private array $calls = [];

    /**
     * @inheritdoc
     */
    public function getUrl(Product $product, $params = [])
    {
        $this->calls[] = $params;

        return parent::getUrl($product, $params);
    }

    /**
     * URLs built for the product itself — the listing's image and name links.
     */
    public function productUrlBuilds(): int
    {
        return count(array_filter($this->calls, static fn (array $p): bool => !isset($p['_ignore_category'])));
    }

    /**
     * URLs built for the review summary, which passes _ignore_category.
     */
    public function reviewUrlBuilds(): int
    {
        return count(array_filter($this->calls, static fn (array $p): bool => isset($p['_ignore_category'])));
    }

    /**
     * Every URL this model was asked to build, of either kind.
     */
    public function totalBuilds(): int
    {
        return count($this->calls);
    }
}
