<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Swatches\Block\Product\Renderer\Listing;

use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\ConfigurableProduct\Test\Fixture\Attribute as ConfigurableAttributeFixture;
use Magento\ConfigurableProduct\Test\Fixture\Product as ConfigurableProductFixture;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\View\LayoutInterface;
use Magento\Swatches\Helper\Data as SwatchHelper;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Building a swatch listing block's cache key must not load the catalogue.
 *
 * getCacheKey() appends a hash of the *layered* attributes - the configurable
 * attributes that also appear as request query parameters. It found them by
 * loading every configurable attribute for the product and intersecting the
 * result with the query string. With no query parameters that intersection is
 * empty by construction, so the load could never affect the key, and a listing
 * of 48 configurables paid for it 96 times: 22.6 ms and 96 SELECTs of the
 * variant matrix on one Luma category page.
 *
 * This pins the cost, because the output cannot pin it: the key is identical
 * either way, which is precisely why the load was safe to skip.
 *
 * @magentoAppArea frontend
 * @magentoAppIsolation enabled
 */
class CacheKeyCostTest extends TestCase
{
    /**
     * @var CountingSwatchHelper|null
     */
    private ?CountingSwatchHelper $helper = null;

    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->helper = $objectManager->create(CountingSwatchHelper::class);
        $objectManager->addSharedInstance($this->helper, SwatchHelper::class);
    }

    private function buildBlock(): Configurable
    {
        $fixtures = DataFixtureStorageManager::getStorage();
        $objectManager = Bootstrap::getObjectManager();

        /** @var Configurable $block */
        $block = $objectManager->get(LayoutInterface::class)->createBlock(Configurable::class);
        $block->setProduct($fixtures->get('conf'));

        return $block;
    }

    private function setQuery(array $params): void
    {
        $request = Bootstrap::getObjectManager()->get(RequestInterface::class);
        $request->setParams($params);
        $request->getQuery()->fromArray($params);
    }

    #[
        DbIsolation(false),
        DataFixture(ConfigurableAttributeFixture::class, ['_options' => [[], []]], as: 'attr'),
        DataFixture(ProductFixture::class, as: 'v1'),
        DataFixture(ProductFixture::class, as: 'v2'),
        DataFixture(
            ConfigurableProductFixture::class,
            ['sku' => 'swatch-cachekey-conf', '_options' => ['$attr$'], '_links' => ['$v1$', '$v2$']],
            as: 'conf'
        ),
    ]
    public function testCacheKeyLoadsNoAttributesWhenThereAreNoQueryParameters(): void
    {
        $this->setQuery([]);
        $block = $this->buildBlock();

        $this->helper->resetCount();
        $key = $block->getCacheKey();

        $this->assertSame(
            0,
            $this->helper->attributeLoads(),
            'The cache key loaded the configurable attribute set even though there are no query '
            . 'parameters for it to intersect with, so the result could not have affected the key'
        );
        $this->assertNotEmpty($key, 'No cache key was produced, so this test proves nothing');
    }

    #[
        DbIsolation(false),
        DataFixture(ConfigurableAttributeFixture::class, ['_options' => [[], []]], as: 'attr'),
        DataFixture(ProductFixture::class, as: 'v1'),
        DataFixture(ProductFixture::class, as: 'v2'),
        DataFixture(
            ConfigurableProductFixture::class,
            ['sku' => 'swatch-cachekey-conf-2', '_options' => ['$attr$'], '_links' => ['$v1$', '$v2$']],
            as: 'conf'
        ),
    ]
    public function testCacheKeyStillVariesWithAMatchingQueryParameter(): void
    {
        $fixtures = DataFixtureStorageManager::getStorage();
        $attributeCode = $fixtures->get('attr')->getAttributeCode();

        $this->setQuery([]);
        $unfilteredKey = $this->buildBlock()->getCacheKey();

        $this->setQuery([$attributeCode => '1']);
        $this->helper->resetCount();
        $filteredKey = $this->buildBlock()->getCacheKey();

        $this->assertGreaterThan(
            0,
            $this->helper->attributeLoads(),
            'A request carrying a configurable attribute code must still resolve the attribute set'
        );
        $this->assertNotSame(
            $unfilteredKey,
            $filteredKey,
            'Filtering on a configurable attribute must still change the block cache key'
        );
    }
}
