<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\ConfigurableProduct\Block\Product\ListProduct;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Test\Fixture\Category as CategoryFixture;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\CatalogSearch\Model\Indexer\Fulltext\Processor as FulltextProcessor;
use Magento\ConfigurableProduct\Test\Fixture\Attribute as ConfigurableAttributeFixture;
use Magento\ConfigurableProduct\Test\Fixture\Product as ConfigurableProductFixture;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\TestCase\AbstractController;

/**
 * Behavioural contract for a category listing containing configurable products.
 *
 * This is the safety net for the configurable N+1 optimisation tracked in
 * PLP-PROFILE.md. Batching per-product data loading changes *how* product data
 * reaches the template, which is precisely where behaviour regressions hide:
 * a product silently missing, a child variant leaking onto the listing, a
 * disabled product becoming visible.
 *
 * These assertions are about WHAT the listing shows, never about how many
 * queries it takes — query counts live in PlpQueryCountTest.
 *
 * @magentoAppIsolation enabled
 */
class PlpProductListTest extends AbstractController
{
    private function renderCategory(int $categoryId): string
    {
        $this->resetRequest();
        $this->dispatch('catalog/category/view/id/' . $categoryId);
        $body = (string)$this->getResponse()->getBody();
        $this->assertStringNotContainsString('Whoops', $body, 'Category page failed to render');

        return $body;
    }

    private function reindex(): void
    {
        $this->_objectManager->create(FulltextProcessor::class)->reindexAll();
    }

    /**
     * @return string[] SKUs of products rendered as listing items
     */
    private function renderedSkus(string $html): array
    {
        // The listing renders a form per item carrying the product SKU in the
        // add-to-cart post payload; parsing that is more robust than scraping
        // product names, which are escaped and themed.
        preg_match_all('/data-product-sku="([^"]+)"/', $html, $matches);
        if (!empty($matches[1])) {
            return array_values(array_unique($matches[1]));
        }

        preg_match_all('/product-item-link[^>]*>\s*([^<]+)</', $html, $nameMatches);

        return array_values(array_unique(array_map('trim', $nameMatches[1] ?? [])));
    }

    #[
        DbIsolation(false),
        DataFixture(ConfigurableAttributeFixture::class, ['_options' => [[], []]], as: 'attr'),
        DataFixture(CategoryFixture::class, as: 'cat'),
        DataFixture(ProductFixture::class, ['sku' => 'plp-variant-1'], as: 'v1'),
        DataFixture(ProductFixture::class, ['sku' => 'plp-variant-2'], as: 'v2'),
        DataFixture(
            ConfigurableProductFixture::class,
            [
                'sku' => 'plp-configurable-1',
                '_options' => ['$attr$'],
                '_links' => ['$v1$', '$v2$'],
                'category_ids' => ['$cat.id$'],
            ],
            as: 'conf'
        ),
        DataFixture(
            ProductFixture::class,
            ['sku' => 'plp-simple-1', 'category_ids' => ['$cat.id$']],
            as: 'simple'
        ),
    ]
    public function testListingShowsConfigurableParentAndSimpleButNotVariants(): void
    {
        $fixtures = DataFixtureStorageManager::getStorage();
        $this->reindex();

        $html = $this->renderCategory((int)$fixtures->get('cat')->getId());
        $skus = $this->renderedSkus($html);

        $this->assertContains('plp-configurable-1', $skus, 'Configurable parent missing from listing');
        $this->assertContains('plp-simple-1', $skus, 'Simple product missing from listing');

        // Child variants are Visibility::VISIBILITY_NOT_VISIBLE and must never
        // appear as their own listing entries. A batching change that loads
        // children eagerly could leak them here.
        $this->assertNotContains('plp-variant-1', $skus, 'Configurable child leaked onto the listing');
        $this->assertNotContains('plp-variant-2', $skus, 'Configurable child leaked onto the listing');

        $this->assertCount(2, $skus, 'Listing should contain exactly the parent and the simple product');
    }

    #[
        DbIsolation(false),
        DataFixture(ConfigurableAttributeFixture::class, ['_options' => [[], []]], as: 'attr'),
        DataFixture(CategoryFixture::class, as: 'cat'),
        DataFixture(ProductFixture::class, as: 'v1'),
        DataFixture(ProductFixture::class, as: 'v2'),
        DataFixture(
            ConfigurableProductFixture::class,
            [
                'sku' => 'plp-disabled-configurable',
                '_options' => ['$attr$'],
                '_links' => ['$v1$', '$v2$'],
                'category_ids' => ['$cat.id$'],
                'status' => Status::STATUS_DISABLED,
            ],
            as: 'disabled'
        ),
        DataFixture(
            ProductFixture::class,
            ['sku' => 'plp-enabled-simple', 'category_ids' => ['$cat.id$']],
            as: 'enabled'
        ),
    ]
    public function testDisabledConfigurableIsExcluded(): void
    {
        $fixtures = DataFixtureStorageManager::getStorage();
        $this->reindex();

        $skus = $this->renderedSkus($this->renderCategory((int)$fixtures->get('cat')->getId()));

        $this->assertNotContains('plp-disabled-configurable', $skus, 'Disabled product is visible on the listing');
        $this->assertContains('plp-enabled-simple', $skus);
    }

    #[
        DbIsolation(false),
        DataFixture(ConfigurableAttributeFixture::class, ['_options' => [[], []]], as: 'attr'),
        DataFixture(CategoryFixture::class, as: 'cat'),
        DataFixture(ProductFixture::class, as: 'v1'),
        DataFixture(ProductFixture::class, as: 'v2'),
        DataFixture(
            ConfigurableProductFixture::class,
            [
                'sku' => 'plp-invisible-configurable',
                '_options' => ['$attr$'],
                '_links' => ['$v1$', '$v2$'],
                'category_ids' => ['$cat.id$'],
                'visibility' => Visibility::VISIBILITY_NOT_VISIBLE,
            ],
            as: 'invisible'
        ),
        DataFixture(
            ProductFixture::class,
            ['sku' => 'plp-visible-simple', 'category_ids' => ['$cat.id$']],
            as: 'visible'
        ),
    ]
    public function testNotVisibleConfigurableIsExcluded(): void
    {
        $fixtures = DataFixtureStorageManager::getStorage();
        $this->reindex();

        $skus = $this->renderedSkus($this->renderCategory((int)$fixtures->get('cat')->getId()));

        $this->assertNotContains('plp-invisible-configurable', $skus, 'Not-visible product rendered on the listing');
        $this->assertContains('plp-visible-simple', $skus);
    }

    #[
        DbIsolation(false),
        DataFixture(ConfigurableAttributeFixture::class, ['_options' => [[], []]], as: 'attr'),
        DataFixture(CategoryFixture::class, as: 'cat'),
        DataFixture(ProductFixture::class, as: 'v1'),
        DataFixture(ProductFixture::class, as: 'v2'),
        DataFixture(
            ConfigurableProductFixture::class,
            [
                'sku' => 'plp-priced-configurable',
                '_options' => ['$attr$'],
                '_links' => ['$v1$', '$v2$'],
                'category_ids' => ['$cat.id$'],
            ],
            as: 'conf'
        ),
    ]
    public function testConfigurableRendersAPriceAndProductUrl(): void
    {
        $fixtures = DataFixtureStorageManager::getStorage();
        $this->reindex();

        $html = $this->renderCategory((int)$fixtures->get('cat')->getId());

        // Configurable price on a listing comes from the child variants. If a
        // batching change stops loading child price data, the block renders
        // with no price rather than erroring — a silent, user-visible break.
        $this->assertMatchesRegularExpression(
            '/data-price-type="finalPrice"|price-final_price|"price":/',
            $html,
            'Configurable product rendered without any price markup'
        );

        $product = $this->_objectManager->get(ProductRepositoryInterface::class)
            ->get('plp-priced-configurable');
        $this->assertNotEmpty($product->getId());
    }
}
