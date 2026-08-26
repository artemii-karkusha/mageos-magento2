<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\ConfigurableProduct\Block\Product\ListProduct;

use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Test\Fixture\Category as CategoryFixture;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\ConfigurableProduct\Test\Fixture\Attribute as ConfigurableAttributeFixture;
use Magento\ConfigurableProduct\Test\Fixture\Product as ConfigurableProductFixture;
use Magento\Framework\App\Config\MutableScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\ScopeInterface;
use Magento\Catalog\Model\Layer\Resolver as LayerResolver;
use Magento\Framework\Registry;
use Magento\Framework\View\LayoutInterface;
use Magento\Framework\Search\Request\Builder as SearchRequestBuilder;
use Magento\Framework\Search\Request\Config as SearchRequestConfig;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\CatalogSearch\Model\Indexer\Fulltext\Processor as FulltextProcessor;
use Magento\Search\Model\Search;
use Magento\TestFramework\Fixture\Cache;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\TestCase\AbstractController;

/**
 * Guards against N+1 query growth when a category listing renders configurable
 * products and their variants.
 *
 * The listing is built so both the parents and their child variants appear in
 * the category, which is the shape Luma produces. Query count is then compared
 * between a small and a large category and expressed per additional product.
 *
 * The assertion measures the SHAPE of the growth rather than an absolute total.
 * An absolute number is brittle — any unrelated core change shifts it and the
 * test fails for the wrong reason — whereas "an extra product must not cost a
 * proportional number of queries" is the actual defect.
 *
 * SCOPE — what this does NOT cover:
 *
 * It does not exercise ProductIdentitiesExtender, the parent-loading N+1 that
 * PrimeLoadedProductPool addresses. That was verified, not assumed: disabling
 * the plugin outright leaves the measured delta at exactly 80 queries over 12
 * products, unchanged. With only a handful of distinct parents the repository's
 * in-memory memoisation absorbs the loads entirely, so the defect is invisible
 * at this scale. It shows up on a real catalogue — a 48-product Luma category
 * went from 948 to 169 SELECTs — and is measured there instead
 * (see PLP-PROFILE.md).
 *
 * The growth this test does catch is real and worth guarding; it simply has a
 * different cause, still to be identified.
 *
 * @magentoAppArea frontend
 * @magentoAppIsolation enabled
 */
class PlpQueryCountTest extends AbstractController
{
    /**
     * Queries a single extra product on the listing is allowed to cost.
     *
     * 7 is a RATCHET pinned just above today's measured 6.7, not a target. It
     * exists to stop the per-product cost growing, and should be lowered as the
     * remaining sources are removed. Setting it to the value we would like
     * would leave a permanently red test that tells nobody anything new.
     */
    private const MAX_QUERIES_PER_EXTRA_PRODUCT = 7;

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
    private function renderCategory(int $categoryId): int
    {
        Bootstrap::getInstance()->reinitialize();
        $this->_objectManager = Bootstrap::getObjectManager();
        $this->resource = $this->_objectManager->get(ResourceConnection::class);

        // The default page size is 12. The large category holds 18 products, so
        // without raising this the listing silently truncates — and because the
        // toolbar orders simples first, it cut exactly the configurables the
        // test needs, turning the measured delta negative.
        // reinitialize() discards config fixtures, hence setting it per render.
        $config = $this->_objectManager->get(MutableScopeConfigInterface::class);
        $config->setValue('catalog/frontend/grid_per_page_values', '6,18,36', ScopeInterface::SCOPE_STORE, 'default');
        $config->setValue('catalog/frontend/grid_per_page', 36, ScopeInterface::SCOPE_STORE, 'default');

        $this->resetRequest();
        $this->dispatch('catalog/category/view/id/' . $categoryId);

        $body = (string)$this->getResponse()->getBody();
        $this->assertStringNotContainsString('Whoops', $body, 'Category page failed to render');

        $list = $this->_objectManager->get(LayoutInterface::class)->getBlock('category.products.list');
        $this->assertNotFalse($list, 'category.products.list block is missing');

        return count($list->getLoadedProductCollection()->getItems());
    }

    #[
        DbIsolation(false),
        // ProductIdentitiesExtender is reached through block cache TAG
        // computation (AbstractBlock::_saveCache -> getCacheTags ->
        // getIdentities). With block_html caching off — the integration default
        // — that path never executes and this test measures a listing in which
        // the defect under test is dormant.
        Cache('block_html', true),
        DataFixture(ConfigurableAttributeFixture::class, ['_options' => [[], []]], as: 'attr'),
        DataFixture(CategoryFixture::class, as: 'cat_small'),
        DataFixture(CategoryFixture::class, as: 'cat_large'),

        // cat_small: 2 configurables
        DataFixture(
            ProductFixture::class,
            ['category_ids' => ['$cat_small.id$'], 'visibility' => Visibility::VISIBILITY_BOTH],
            as: 's1'
        ),
        DataFixture(
            ProductFixture::class,
            ['category_ids' => ['$cat_small.id$'], 'visibility' => Visibility::VISIBILITY_BOTH],
            as: 's2'
        ),
        DataFixture(
            ProductFixture::class,
            ['category_ids' => ['$cat_small.id$'], 'visibility' => Visibility::VISIBILITY_BOTH],
            as: 's3'
        ),
        DataFixture(
            ProductFixture::class,
            ['category_ids' => ['$cat_small.id$'], 'visibility' => Visibility::VISIBILITY_BOTH],
            as: 's4'
        ),
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
        DataFixture(
            ProductFixture::class,
            ['category_ids' => ['$cat_large.id$'], 'visibility' => Visibility::VISIBILITY_BOTH],
            as: 'l1'
        ),
        DataFixture(
            ProductFixture::class,
            ['category_ids' => ['$cat_large.id$'], 'visibility' => Visibility::VISIBILITY_BOTH],
            as: 'l2'
        ),
        DataFixture(
            ProductFixture::class,
            ['category_ids' => ['$cat_large.id$'], 'visibility' => Visibility::VISIBILITY_BOTH],
            as: 'l3'
        ),
        DataFixture(
            ProductFixture::class,
            ['category_ids' => ['$cat_large.id$'], 'visibility' => Visibility::VISIBILITY_BOTH],
            as: 'l4'
        ),
        DataFixture(
            ProductFixture::class,
            ['category_ids' => ['$cat_large.id$'], 'visibility' => Visibility::VISIBILITY_BOTH],
            as: 'l5'
        ),
        DataFixture(
            ProductFixture::class,
            ['category_ids' => ['$cat_large.id$'], 'visibility' => Visibility::VISIBILITY_BOTH],
            as: 'l6'
        ),
        DataFixture(
            ProductFixture::class,
            ['category_ids' => ['$cat_large.id$'], 'visibility' => Visibility::VISIBILITY_BOTH],
            as: 'l7'
        ),
        DataFixture(
            ProductFixture::class,
            ['category_ids' => ['$cat_large.id$'], 'visibility' => Visibility::VISIBILITY_BOTH],
            as: 'l8'
        ),
        DataFixture(
            ProductFixture::class,
            ['category_ids' => ['$cat_large.id$'], 'visibility' => Visibility::VISIBILITY_BOTH],
            as: 'l9'
        ),
        DataFixture(
            ProductFixture::class,
            ['category_ids' => ['$cat_large.id$'], 'visibility' => Visibility::VISIBILITY_BOTH],
            as: 'l10'
        ),
        DataFixture(
            ProductFixture::class,
            ['category_ids' => ['$cat_large.id$'], 'visibility' => Visibility::VISIBILITY_BOTH],
            as: 'l11'
        ),
        DataFixture(
            ProductFixture::class,
            ['category_ids' => ['$cat_large.id$'], 'visibility' => Visibility::VISIBILITY_BOTH],
            as: 'l12'
        ),
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
        $smallCount = $this->renderCategory($smallId);
        $smallQueries = $this->countSelects(fn () => $this->renderCategory($smallId));

        $largeCount = $this->renderCategory($largeId);
        $largeQueries = $this->countSelects(fn () => $this->renderCategory($largeId));

        // The listing renders the child variants alongside their parents, which
        // is what makes ProductIdentitiesExtender fire: it only acts on simple
        // products. Sizes are read back from the block rather than assumed, so
        // the ratio stays correct whatever the fixtures end up producing.
        $this->assertGreaterThan(
            $smallCount,
            $largeCount,
            'The large category must list more products than the small one'
        );

        $this->assertGreaterThan(0, $smallQueries, 'No queries counted — the counter is not working');

        $extraProducts = $largeCount - $smallCount;
        $delta = $largeQueries - $smallQueries;
        fwrite(STDERR, sprintf(
            "\n    [measure] small=%d/%dq  large=%d/%dq  delta=%d over %d products\n",
            $smallCount,
            $smallQueries,
            $largeCount,
            $largeQueries,
            $delta,
            $extraProducts
        ));
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
                $smallCount,
                $smallQueries,
                $largeCount,
                $largeQueries,
                $delta,
                $extraProducts
            )
        );
    }
}
