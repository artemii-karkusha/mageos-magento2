<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\ConfigurableProduct\Block\Product\ListProduct;

use Magento\Catalog\Test\Fixture\Category as CategoryFixture;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\ConfigurableProduct\Test\Fixture\Attribute as ConfigurableAttributeFixture;
use Magento\ConfigurableProduct\Test\Fixture\Product as ConfigurableProductFixture;
use Magento\Framework\App\ResourceConnection;
use Magento\Catalog\Model\Layer\Resolver as LayerResolver;
use Magento\Framework\Registry;
use Magento\Framework\View\LayoutInterface;
use Magento\Framework\Search\Request\Builder as SearchRequestBuilder;
use Magento\Framework\Search\Request\Config as SearchRequestConfig;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\CatalogSearch\Model\Indexer\Fulltext\Processor as FulltextProcessor;
use Magento\Search\Model\Search;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\TestCase\AbstractController;

/**
 * Guards against N+1 query regressions when a category listing renders
 * configurable products.
 *
 * Profiling a 48-product Luma category page (see PLP-PROFILE.md) showed ~950
 * SELECTs, roughly 20 per product, coming from the configurable path:
 * catalog_product_super_link, catalog_product_super_attribute, the media
 * gallery, and per-entity catalog_product_entity_varchar selects. Each scales
 * 1:1 with the number of products rendered.
 *
 * The assertion deliberately measures the SHAPE of the growth — how many extra
 * queries each additional product costs — rather than an absolute total. An
 * absolute number is brittle: any unrelated core change shifts it and the test
 * starts failing for the wrong reason. Per-product growth is the actual defect
 * and stays meaningful as the rest of the page evolves.
 *
 * @magentoAppIsolation enabled
 */
class PlpQueryCountTest extends AbstractController
{
    /**
     * Queries a single extra product on the listing is allowed to cost.
     *
     * A batched implementation should add close to zero: products come from one
     * collection load, and the configurable data for the whole page should be
     * fetched in a bounded number of statements. The threshold leaves headroom
     * for legitimate per-product work (e.g. URL rewrites) without tolerating the
     * ~20/product N+1 this test exists to catch.
     */
    private const MAX_QUERIES_PER_EXTRA_PRODUCT = 6;

    /**
     * @var ResourceConnection
     */
    private ResourceConnection $resource;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resource = $this->_objectManager->get(ResourceConnection::class);
    }

    /**
     * Counts SELECTs the database actually executed while $callback ran.
     *
     * Uses MySQL's Com_select session counter rather than Zend_Db_Profiler:
     * the profiler must be enabled through connection configuration, and
     * Magento's adapter only partially drives it, so switching it on mid-test
     * silently under-counts. Com_select needs no configuration and counts what
     * the server received.
     */
    private function countSelects(callable $callback): int
    {
        $connection = $this->resource->getConnection();
        $read = static function () use ($connection): int {
            $row = $connection->fetchRow("SHOW SESSION STATUS LIKE 'Com_select'");
            return (int)($row['Value'] ?? 0);
        };

        $before = $read();
        $callback();
        $after = $read();

        // Discount the two SHOW statements themselves.
        return max(0, $after - $before - 2);
    }

    /**
     * Dispatches a category page in a freshly reinitialised application.
     *
     * Magento holds a lot of per-request state in shared instances — the
     * catalog layer, the layout, the search request builder, and the fulltext
     * collection (which throws "Illegal state" if filtered after loading).
     * Clearing them individually turned into whack-a-mole, so the whole
     * application is reinitialised instead. Each measured render therefore
     * starts from the same state, which is exactly what makes the two counts
     * comparable.
     */
    private function renderCategory(int $categoryId): void
    {
        Bootstrap::getInstance()->reinitialize();
        $this->_objectManager = Bootstrap::getObjectManager();
        $this->resource = $this->_objectManager->get(ResourceConnection::class);

        $this->resetRequest();
        $this->dispatch('catalog/category/view/id/' . $categoryId);

        $body = (string)$this->getResponse()->getBody();
        $this->assertStringNotContainsString('Whoops', $body, 'Category page failed to render');
    }

    #[
        DbIsolation(false),
        DataFixture(ConfigurableAttributeFixture::class, ['_options' => [[], []]], as: 'attr'),
        DataFixture(CategoryFixture::class, as: 'cat_small'),
        DataFixture(CategoryFixture::class, as: 'cat_large'),

        // cat_small: 2 configurables
        DataFixture(ProductFixture::class, as: 's1'),
        DataFixture(ProductFixture::class, as: 's2'),
        DataFixture(ProductFixture::class, as: 's3'),
        DataFixture(ProductFixture::class, as: 's4'),
        DataFixture(
            ConfigurableProductFixture::class,
            ['_options' => ['$attr$'], '_links' => ['$s1$', '$s2$'], 'category_ids' => ['$cat_small.id$']],
            as: 'small1'
        ),
        DataFixture(
            ConfigurableProductFixture::class,
            ['_options' => ['$attr$'], '_links' => ['$s3$', '$s4$'], 'category_ids' => ['$cat_small.id$']],
            as: 'small2'
        ),

        // cat_large: 6 configurables — same shape, 4 more products
        DataFixture(ProductFixture::class, as: 'l1'),
        DataFixture(ProductFixture::class, as: 'l2'),
        DataFixture(ProductFixture::class, as: 'l3'),
        DataFixture(ProductFixture::class, as: 'l4'),
        DataFixture(ProductFixture::class, as: 'l5'),
        DataFixture(ProductFixture::class, as: 'l6'),
        DataFixture(ProductFixture::class, as: 'l7'),
        DataFixture(ProductFixture::class, as: 'l8'),
        DataFixture(ProductFixture::class, as: 'l9'),
        DataFixture(ProductFixture::class, as: 'l10'),
        DataFixture(ProductFixture::class, as: 'l11'),
        DataFixture(ProductFixture::class, as: 'l12'),
        DataFixture(
            ConfigurableProductFixture::class,
            ['_options' => ['$attr$'], '_links' => ['$l1$', '$l2$'], 'category_ids' => ['$cat_large.id$']],
            as: 'large1'
        ),
        DataFixture(
            ConfigurableProductFixture::class,
            ['_options' => ['$attr$'], '_links' => ['$l3$', '$l4$'], 'category_ids' => ['$cat_large.id$']],
            as: 'large2'
        ),
        DataFixture(
            ConfigurableProductFixture::class,
            ['_options' => ['$attr$'], '_links' => ['$l5$', '$l6$'], 'category_ids' => ['$cat_large.id$']],
            as: 'large3'
        ),
        DataFixture(
            ConfigurableProductFixture::class,
            ['_options' => ['$attr$'], '_links' => ['$l7$', '$l8$'], 'category_ids' => ['$cat_large.id$']],
            as: 'large4'
        ),
        DataFixture(
            ConfigurableProductFixture::class,
            ['_options' => ['$attr$'], '_links' => ['$l9$', '$l10$'], 'category_ids' => ['$cat_large.id$']],
            as: 'large5'
        ),
        DataFixture(
            ConfigurableProductFixture::class,
            ['_options' => ['$attr$'], '_links' => ['$l11$', '$l12$'], 'category_ids' => ['$cat_large.id$']],
            as: 'large6'
        ),
    ]
    public function testQueryCountDoesNotScaleWithProductCount(): void
    {
        $fixtures = DataFixtureStorageManager::getStorage();
        $smallId = (int)$fixtures->get('cat_small')->getId();
        $largeId = (int)$fixtures->get('cat_large')->getId();

        // Fixtures created the products; the fulltext index must reflect them
        // before a category listing can return anything. Done once here rather
        // than per render, so reindex queries stay out of the measurement.
        $this->_objectManager->create(FulltextProcessor::class)->reindexAll();

        // Warm caches that are populated on first render (config, EAV metadata,
        // attribute options). Without this the first measurement includes
        // one-off setup and the delta is meaningless.
        $this->renderCategory($smallId);
        $this->renderCategory($largeId);

        // Each measured render is preceded by an identical warm render of the
        // same category, so config/EAV/attribute caches are populated and the
        // count reflects listing work only.
        $this->renderCategory($smallId);
        $smallQueries = $this->countSelects(fn () => $this->renderCategory($smallId));

        $this->renderCategory($largeId);
        $largeQueries = $this->countSelects(fn () => $this->renderCategory($largeId));

        $this->assertGreaterThan(0, $smallQueries, 'No queries counted — the counter is not working');

        $extraProducts = 4;
        $delta = $largeQueries - $smallQueries;
        $perProduct = $delta / $extraProducts;

        $this->assertLessThanOrEqual(
            self::MAX_QUERIES_PER_EXTRA_PRODUCT,
            $perProduct,
            sprintf(
                "Category listing issues %.1f extra SELECTs per additional configurable product.\n"
                . "  2 products  -> %d SELECTs\n"
                . "  6 products  -> %d SELECTs\n"
                . "  delta       -> %d for %d products\n"
                . 'This is the configurable N+1 path; see PLP-PROFILE.md.',
                $perProduct,
                $smallQueries,
                $largeQueries,
                $delta,
                $extraProducts
            )
        );
    }
}
