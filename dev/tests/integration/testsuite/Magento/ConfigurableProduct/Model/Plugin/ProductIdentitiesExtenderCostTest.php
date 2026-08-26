<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\ConfigurableProduct\Model\Plugin;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\ConfigurableProduct\Test\Fixture\Attribute as ConfigurableAttributeFixture;
use Magento\ConfigurableProduct\Test\Fixture\Product as ConfigurableProductFixture;
use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Documents what ProductIdentitiesExtender actually gets for the cost it pays.
 *
 * The plugin extends a child product's cache identities with its parents'. To
 * do that it calls ProductRepository::getById() on each parent — a full model
 * load: every EAV attribute, the media gallery, configurable options and all
 * extension attributes.
 *
 * Profiling a 48-product category page (PLP-PROFILE.md) attributed ~1.1s of
 * inclusive time to 47 such loads, one per configurable on the page — for
 * products the listing collection had already loaded.
 *
 * These tests pin down the two facts an optimisation depends on:
 *
 *   1. what getIdentities() returns for a freshly loaded product, and
 *   2. how many queries the load costs.
 *
 * If someone later makes getIdentities() genuinely depend on loaded product
 * state, test 1 fails and the optimisation must be revisited — which is the
 * point of asserting it rather than reasoning about it.
 *
 * @magentoAppArea frontend
 * @magentoAppIsolation enabled
 */
class ProductIdentitiesExtenderCostTest extends TestCase
{
    /**
     * @var \Magento\Framework\ObjectManagerInterface
     */
    private $objectManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->objectManager = Bootstrap::getObjectManager();
    }

    private function countSelects(callable $callback): int
    {
        $connection = $this->objectManager->get(ResourceConnection::class)->getConnection();
        $read = static function () use ($connection): int {
            $row = $connection->fetchRow("SHOW SESSION STATUS LIKE 'Com_select'");
            return (int)($row['Value'] ?? 0);
        };

        $before = $read();
        $callback();

        return max(0, $read() - $before - 2);
    }

    #[
        DbIsolation(false),
        DataFixture(ConfigurableAttributeFixture::class, ['_options' => [[], []]], as: 'attr'),
        DataFixture(ProductFixture::class, as: 'v1'),
        DataFixture(ProductFixture::class, as: 'v2'),
        DataFixture(
            ConfigurableProductFixture::class,
            ['sku' => 'identities-parent', '_options' => ['$attr$'], '_links' => ['$v1$', '$v2$']],
            as: 'parent'
        ),
    ]
    public function testFreshlyLoadedParentContributesOnlyItsOwnCacheTag(): void
    {
        $fixtures = DataFixtureStorageManager::getStorage();
        $parentId = (int)$fixtures->get('parent')->getId();

        $parent = $this->objectManager->create(ProductRepositoryInterface::class)->getById($parentId);
        $identities = $parent->getIdentities();

        // Every branch inside Product::getIdentities() that could add more is
        // gated on state that only a *save* produces: getOrigData() differing
        // from getData(), getIsChangedCategories(), isStockStatusChanged(),
        // isObjectNew(). None of them is true for a product just read from the
        // repository, so the expensive load yields exactly two constant tags.
        sort($identities);
        $expected = [Product::CACHE_TAG, Product::CACHE_TAG . '_' . $parentId];
        sort($expected);

        $this->assertSame(
            $expected,
            $identities,
            'A freshly loaded parent contributed more than its own cache tag — '
            . 'the identities plugin cannot be optimised by skipping the load'
        );
    }

    #[
        DbIsolation(false),
        DataFixture(ConfigurableAttributeFixture::class, ['_options' => [[], []]], as: 'attr'),
        DataFixture(ProductFixture::class, as: 'v1'),
        DataFixture(ProductFixture::class, as: 'v2'),
        DataFixture(
            ConfigurableProductFixture::class,
            ['sku' => 'identities-cost-parent', '_options' => ['$attr$'], '_links' => ['$v1$', '$v2$']],
            as: 'parent'
        ),
    ]
    public function testLoadingAParentJustForItsCacheTagIsExpensive(): void
    {
        $fixtures = DataFixtureStorageManager::getStorage();
        $parentId = (int)$fixtures->get('parent')->getId();
        $repository = $this->objectManager->create(ProductRepositoryInterface::class);

        // Warm caches so the measurement is the load itself, not metadata setup.
        $repository->getById($parentId);

        $queries = $this->countSelects(
            function () use ($parentId) {
                // A distinct repository instance: the shared one memoises by id,
                // which is why a page pays this once per parent rather than once
                // per child, and exactly why it scales with products on the page.
                $fresh = $this->objectManager->create(ProductRepositoryInterface::class);
                $fresh->getById($parentId)->getIdentities();
            }
        );

        $this->assertGreaterThan(
            1,
            $queries,
            'Expected a full product load to cost several queries'
        );

        // Recorded, not enforced as an upper bound: the point is the ratio.
        // Producing ['cat_p', 'cat_p_N'] needs no queries at all.
        self::assertTrue(
            true,
            sprintf('Full parent load for two constant cache tags cost %d SELECTs', $queries)
        );
        fwrite(STDERR, sprintf("\n    [cost] parent load for cache tags = %d SELECTs\n", $queries));
    }
}
