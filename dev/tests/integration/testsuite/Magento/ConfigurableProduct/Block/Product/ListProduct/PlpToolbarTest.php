<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\ConfigurableProduct\Block\Product\ListProduct;

use Magento\Catalog\Test\Fixture\Category as CategoryFixture;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\CatalogSearch\Model\Indexer\Fulltext\Processor as FulltextProcessor;
use Magento\ConfigurableProduct\Test\Fixture\Attribute as ConfigurableAttributeFixture;
use Magento\ConfigurableProduct\Test\Fixture\Product as ConfigurableProductFixture;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\Framework\App\Config\MutableScopeConfigInterface;
use Magento\Framework\View\LayoutInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\TestCase\AbstractController;

/**
 * Toolbar contract for a category listing of configurable products:
 * page size, pagination and sort order.
 *
 * This is the part of the safety net most likely to catch a subtle batching
 * bug. Loading per-product data in bulk means collecting ids, fetching in one
 * query, then reassociating results with products. Every step there can lose
 * the collection's ORDER, or apply a batch built for one page to another.
 * The listing still renders the right *number* of products, so only an
 * order-and-membership assertion catches it.
 *
 * @magentoAppIsolation enabled
 */
class PlpToolbarTest extends AbstractController
{
    /**
     * Page sizes the toolbar will accept. A requested product_list_limit that
     * is not in this list is discarded without any error.
     */
    private const ALLOWED_PAGE_SIZES = '2,4,12,24,36';

    private function reindex(): void
    {
        $this->_objectManager->create(FulltextProcessor::class)->reindexAll();
    }

    /**
     * Renders a category with toolbar parameters applied.
     *
     * Params go on the request object, not the URI: the integration framework
     * does not parse a query string appended to the dispatch path, so a URI
     * like "...?product_list_limit=2" renders an unpaginated listing while
     * still returning 200.
     */
    private function renderCategory(int $categoryId, array $params = []): string
    {
        // Magento holds per-request state in shared instances (registry keys,
        // catalog layer, search request builder), so a second dispatch in one
        // test otherwise fails with "Registry key ... already exists".
        Bootstrap::getInstance()->reinitialize();
        $this->_objectManager = Bootstrap::getObjectManager();

        // reinitialize() rebuilds the object manager, which discards the
        // in-memory override the Config fixture attribute installed. Without
        // re-applying it the toolbar sees the stock grid_per_page_values
        // ("12,24,36"), silently rejects a page size of 2, and falls back to 12.
        $this->_objectManager->get(MutableScopeConfigInterface::class)->setValue(
            'catalog/frontend/grid_per_page_values',
            self::ALLOWED_PAGE_SIZES,
            ScopeInterface::SCOPE_STORE,
            'default'
        );

        $this->resetRequest();
        if ($params) {
            $this->getRequest()->setParams($params);
        }

        $this->dispatch('catalog/category/view/id/' . $categoryId);
        $body = (string)$this->getResponse()->getBody();
        $this->assertStringNotContainsString('Whoops', $body, 'Category page failed to render');

        return $body;
    }

    /**
     * Renders the category and returns the SKUs the listing block loaded.
     *
     * @return string[]
     */
    private function renderAndList(int $categoryId, array $params = []): array
    {
        $this->renderCategory($categoryId, $params);

        return $this->listedSkus();
    }

    /**
     * SKUs of the products the listing block actually loaded, in order.
     *
     * Read off the block's collection rather than scraped out of the HTML.
     * Scraping conflates "the toolbar ignored my parameter" with "the template
     * rendered differently", and the collection is what the batching change
     * under test actually touches.
     *
     * @return string[]
     */
    private function listedSkus(): array
    {
        $layout = $this->_objectManager->get(LayoutInterface::class);
        $list = $layout->getBlock('category.products.list');
        $this->assertNotFalse($list, 'category.products.list block is missing from the layout');

        $skus = [];
        foreach ($list->getLoadedProductCollection() as $product) {
            $skus[] = $product->getSku();
        }

        return $skus;
    }

    /**
     * Asserts the toolbar actually received a parameter.
     *
     * The toolbar silently falls back to defaults for a limit that is not in
     * grid_per_page_values, so a bare count assertion cannot distinguish
     * "paging is broken" from "my parameter was discarded".
     */
    private function assertToolbarLimit(int $expected): void
    {
        $layout = $this->_objectManager->get(LayoutInterface::class);
        $toolbar = $layout->getBlock('product_list_toolbar');
        $this->assertNotFalse($toolbar, 'product_list_toolbar block is missing from the layout');
        $this->assertSame(
            $expected,
            (int)$toolbar->getLimit(),
            'The toolbar did not accept the requested page size — check grid_per_page_values'
        );
    }

    #[
        DbIsolation(false),
        // Without this the toolbar SILENTLY ignores product_list_limit: it
        // validates the requested value against grid_per_page_values (default
        // "12,24,36") and falls back to the default when the value is absent.
        // The page then renders unlimited and a count assertion fails for a
        // reason that has nothing to do with paging.
        Config('catalog/frontend/grid_per_page_values', self::ALLOWED_PAGE_SIZES, 'store', 'default'),
        DataFixture(ConfigurableAttributeFixture::class, ['_options' => [[], []]], as: 'attr'),
        DataFixture(CategoryFixture::class, as: 'cat'),
        DataFixture(ProductFixture::class, as: 'v1'),
        DataFixture(ProductFixture::class, as: 'v2'),
        DataFixture(ProductFixture::class, as: 'v3'),
        DataFixture(ProductFixture::class, as: 'v4'),
        DataFixture(ProductFixture::class, as: 'v5'),
        DataFixture(ProductFixture::class, as: 'v6'),
        DataFixture(
            ConfigurableProductFixture::class,
            [
                'sku' => 'plp-tb-aaa',
                'name' => 'AAA Toolbar Product',
                '_options' => ['$attr$'],
                '_links' => ['$v1$', '$v2$'],
                'category_ids' => ['$cat.id$'],
            ],
            as: 'c1'
        ),
        DataFixture(
            ConfigurableProductFixture::class,
            [
                'sku' => 'plp-tb-bbb',
                'name' => 'BBB Toolbar Product',
                '_options' => ['$attr$'],
                '_links' => ['$v3$', '$v4$'],
                'category_ids' => ['$cat.id$'],
            ],
            as: 'c2'
        ),
        DataFixture(
            ConfigurableProductFixture::class,
            [
                'sku' => 'plp-tb-ccc',
                'name' => 'CCC Toolbar Product',
                '_options' => ['$attr$'],
                '_links' => ['$v5$', '$v6$'],
                'category_ids' => ['$cat.id$'],
            ],
            as: 'c3'
        ),
    ]
    public function testPageSizeIsRespected(): void
    {
        $fixtures = DataFixtureStorageManager::getStorage();
        $categoryId = (int)$fixtures->get('cat')->getId();
        $this->reindex();

        $all = $this->renderAndList($categoryId);
        $this->assertCount(3, $all, 'Expected all three configurables without a page-size limit');

        $limited = $this->renderAndList($categoryId, ['product_list_limit' => 2]);
        $this->assertToolbarLimit(2);

        $this->assertCount(2, $limited, 'product_list_limit=2 did not limit the listing');
        $this->assertSame(
            array_slice($all, 0, 2),
            $limited,
            'The limited page is not the first slice of the unlimited listing'
        );
    }

    #[
        DbIsolation(false),
        // Without this the toolbar SILENTLY ignores product_list_limit: it
        // validates the requested value against grid_per_page_values (default
        // "12,24,36") and falls back to the default when the value is absent.
        // The page then renders unlimited and a count assertion fails for a
        // reason that has nothing to do with paging.
        Config('catalog/frontend/grid_per_page_values', self::ALLOWED_PAGE_SIZES, 'store', 'default'),
        DataFixture(ConfigurableAttributeFixture::class, ['_options' => [[], []]], as: 'attr'),
        DataFixture(CategoryFixture::class, as: 'cat'),
        DataFixture(ProductFixture::class, as: 'v1'),
        DataFixture(ProductFixture::class, as: 'v2'),
        DataFixture(ProductFixture::class, as: 'v3'),
        DataFixture(ProductFixture::class, as: 'v4'),
        DataFixture(ProductFixture::class, as: 'v5'),
        DataFixture(ProductFixture::class, as: 'v6'),
        DataFixture(
            ConfigurableProductFixture::class,
            [
                'sku' => 'plp-pg-1',
                '_options' => ['$attr$'],
                '_links' => ['$v1$', '$v2$'],
                'category_ids' => ['$cat.id$'],
            ],
            as: 'c1'
        ),
        DataFixture(
            ConfigurableProductFixture::class,
            [
                'sku' => 'plp-pg-2',
                '_options' => ['$attr$'],
                '_links' => ['$v3$', '$v4$'],
                'category_ids' => ['$cat.id$'],
            ],
            as: 'c2'
        ),
        DataFixture(
            ConfigurableProductFixture::class,
            [
                'sku' => 'plp-pg-3',
                '_options' => ['$attr$'],
                '_links' => ['$v5$', '$v6$'],
                'category_ids' => ['$cat.id$'],
            ],
            as: 'c3'
        ),
    ]
    public function testSecondPageShowsTheRemainingProducts(): void
    {
        $fixtures = DataFixtureStorageManager::getStorage();
        $categoryId = (int)$fixtures->get('cat')->getId();
        $this->reindex();

        $page1 = $this->renderAndList($categoryId, ['product_list_limit' => 2, 'p' => 1]);
        $this->assertToolbarLimit(2);
        $page2 = $this->renderAndList($categoryId, ['product_list_limit' => 2, 'p' => 2]);

        $this->assertCount(2, $page1, 'Page 1 should hold the page-size number of products');
        $this->assertCount(1, $page2, 'Page 2 should hold the remaining product');

        // The critical property: pages must partition the catalogue. A batching
        // bug that reuses a batch across pages shows up here as an overlap.
        $this->assertEmpty(
            array_intersect($page1, $page2),
            'The same product appears on both pages — pagination is not partitioning the listing'
        );

        $union = array_merge($page1, $page2);
        sort($union);
        $this->assertSame(
            ['plp-pg-1', 'plp-pg-2', 'plp-pg-3'],
            $union,
            'Paginating lost or duplicated a product'
        );
    }

    #[
        DbIsolation(false),
        // Without this the toolbar SILENTLY ignores product_list_limit: it
        // validates the requested value against grid_per_page_values (default
        // "12,24,36") and falls back to the default when the value is absent.
        // The page then renders unlimited and a count assertion fails for a
        // reason that has nothing to do with paging.
        Config('catalog/frontend/grid_per_page_values', self::ALLOWED_PAGE_SIZES, 'store', 'default'),
        DataFixture(ConfigurableAttributeFixture::class, ['_options' => [[], []]], as: 'attr'),
        DataFixture(CategoryFixture::class, as: 'cat'),
        DataFixture(ProductFixture::class, as: 'v1'),
        DataFixture(ProductFixture::class, as: 'v2'),
        DataFixture(ProductFixture::class, as: 'v3'),
        DataFixture(ProductFixture::class, as: 'v4'),
        DataFixture(ProductFixture::class, as: 'v5'),
        DataFixture(ProductFixture::class, as: 'v6'),
        DataFixture(
            ConfigurableProductFixture::class,
            [
                'sku' => 'plp-sort-a',
                'name' => 'Alpha Sort Product',
                '_options' => ['$attr$'],
                '_links' => ['$v1$', '$v2$'],
                'category_ids' => ['$cat.id$'],
            ],
            as: 'c1'
        ),
        DataFixture(
            ConfigurableProductFixture::class,
            [
                'sku' => 'plp-sort-m',
                'name' => 'Mike Sort Product',
                '_options' => ['$attr$'],
                '_links' => ['$v3$', '$v4$'],
                'category_ids' => ['$cat.id$'],
            ],
            as: 'c2'
        ),
        DataFixture(
            ConfigurableProductFixture::class,
            [
                'sku' => 'plp-sort-z',
                'name' => 'Zulu Sort Product',
                '_options' => ['$attr$'],
                '_links' => ['$v5$', '$v6$'],
                'category_ids' => ['$cat.id$'],
            ],
            as: 'c3'
        ),
    ]
    public function testSortByNameOrdersTheListingBothWays(): void
    {
        $fixtures = DataFixtureStorageManager::getStorage();
        $categoryId = (int)$fixtures->get('cat')->getId();
        $this->reindex();

        $asc = $this->renderAndList(
            $categoryId,
            ['product_list_order' => 'name', 'product_list_dir' => 'asc']
        );
        $desc = $this->renderAndList(
            $categoryId,
            ['product_list_order' => 'name', 'product_list_dir' => 'desc']
        );

        $this->assertSame(
            ['plp-sort-a', 'plp-sort-m', 'plp-sort-z'],
            $asc,
            'Ascending name sort produced the wrong order'
        );
        $this->assertSame(
            array_reverse($asc),
            $desc,
            'Descending name sort is not the reverse of ascending'
        );
    }
}
