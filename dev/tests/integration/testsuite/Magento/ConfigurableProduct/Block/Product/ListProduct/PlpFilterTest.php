<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\ConfigurableProduct\Block\Product\ListProduct;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Framework\View\LayoutInterface;
use Magento\Catalog\Model\Layer\Filter\Item;
use Magento\Catalog\Model\Layer\Resolver;
use Magento\Catalog\Test\Fixture\Category as CategoryFixture;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\CatalogSearch\Model\Indexer\Fulltext\Processor as FulltextProcessor;
use Magento\ConfigurableProduct\Test\Fixture\Attribute as ConfigurableAttributeFixture;
use Magento\ConfigurableProduct\Test\Fixture\Product as ConfigurableProductFixture;
use Magento\LayeredNavigation\Block\Navigation\Category as CategoryNavigationBlock;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\TestCase\AbstractController;

/**
 * Layered-navigation contract for a category listing of configurable products.
 *
 * Filters on a configurable listing are driven by the *variants'* attribute
 * values, not the parent's. That indirection is exactly what a batching
 * optimisation of the configurable data path is most likely to disturb: the
 * listing can keep rendering the right products while the filter beside it
 * silently loses options or reports wrong counts.
 *
 * Core already covers filter mechanics thoroughly in
 * Magento\LayeredNavigation\Block\Navigation\Category\*. These tests do not
 * repeat that; they assert filters still behave when the products are
 * configurables — the case the optimisation touches.
 *
 * @magentoAppIsolation enabled
 */
class PlpFilterTest extends AbstractController
{
    private function reindex(): void
    {
        $this->_objectManager->create(FulltextProcessor::class)->reindexAll();
    }

    private function dispatchCategory(int $categoryId, array $params = []): string
    {
        // Magento keeps per-request state in shared instances (registry entries,
        // the catalog layer, the search request builder), so a second dispatch
        // in one test fails with "Registry key ... already exists". Reinitialise
        // rather than clearing them one by one.
        Bootstrap::getInstance()->reinitialize();
        $this->_objectManager = Bootstrap::getObjectManager();

        $this->resetRequest();

        // Query-string parameters appended to the dispatch URI are NOT parsed
        // into request params by the integration test framework — the listing
        // renders unfiltered and a naive positive assertion still passes. Core
        // sets them on the request object, so do the same.
        if ($params) {
            $this->getRequest()->setParams($params);
        }

        $this->dispatch('catalog/category/view/id/' . $categoryId);
        $body = (string)$this->getResponse()->getBody();
        $this->assertStringNotContainsString('Whoops', $body, 'Category page failed to render');

        return $body;
    }

    /**
     * @return string[]
     */
    private function renderedSkus(string $html): array
    {
        preg_match_all('/data-product-sku="([^"]+)"/', $html, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    /**
     * Returns the layered-navigation filter items for the rendered category.
     */
    private function filterItemsFor(string $attributeCode): array
    {
        $filter = $this->findFilter($attributeCode);

        return $filter ? $filter->getItems() : [];
    }

    /**
     * Finds the layered-navigation filter for an attribute.
     *
     * Returns the filter object rather than its items so callers can read the
     * real request var and real option values off it. Assuming the request var
     * equals the attribute code, or that the option id can be taken from the
     * attribute repository, is how the first version of this test silently
     * dispatched an unfiltered URL and still "passed" its positive assertion.
     */
    private function findFilter(string $attributeCode)
    {
        // Built the way core's AbstractFiltersTest does: the generic Navigation
        // block cannot be instantiated directly because its filter-list
        // dependency is bound per layer type via DI virtual types.
        $this->_objectManager->removeSharedInstance('categoryFilterList');
        $layerResolver = $this->_objectManager->create(Resolver::class);
        $layerResolver->create(Resolver::CATALOG_LAYER_CATEGORY);

        /** @var CategoryNavigationBlock $navigation */
        $navigation = $this->_objectManager->create(
            CategoryNavigationBlock::class,
            ['layerResolver' => $layerResolver]
        );

        foreach ($navigation->getFilters() as $filter) {
            $attributeModel = $filter->getData('attribute_model');
            if ($attributeModel && $attributeModel->getAttributeCode() === $attributeCode) {
                return $filter;
            }
            if ($filter->getRequestVar() === $attributeCode) {
                return $filter;
            }
        }

        return null;
    }

    #[
        DbIsolation(false),
        DataFixture(
            ConfigurableAttributeFixture::class,
            ['is_filterable' => 1, 'is_filterable_in_search' => 1, '_options' => [[], []]],
            as: 'attr'
        ),
        DataFixture(CategoryFixture::class, as: 'cat'),
        DataFixture(ProductFixture::class, as: 'v1'),
        DataFixture(ProductFixture::class, as: 'v2'),
        DataFixture(ProductFixture::class, as: 'v3'),
        DataFixture(ProductFixture::class, as: 'v4'),
        DataFixture(
            ConfigurableProductFixture::class,
            [
                'sku' => 'plp-filter-conf-a',
                '_options' => ['$attr$'],
                '_links' => ['$v1$', '$v2$'],
                'category_ids' => ['$cat.id$'],
            ],
            as: 'confA'
        ),
        DataFixture(
            ConfigurableProductFixture::class,
            [
                'sku' => 'plp-filter-conf-b',
                '_options' => ['$attr$'],
                '_links' => ['$v3$', '$v4$'],
                'category_ids' => ['$cat.id$'],
            ],
            as: 'confB'
        ),
    ]
    public function testFilterIsAvailableAndCountsMatchListing(): void
    {
        $fixtures = DataFixtureStorageManager::getStorage();
        $categoryId = (int)$fixtures->get('cat')->getId();
        $attributeCode = $fixtures->get('attr')->getAttributeCode();

        $this->reindex();
        $html = $this->dispatchCategory($categoryId);

        $skus = $this->renderedSkus($html);
        $this->assertContains('plp-filter-conf-a', $skus);
        $this->assertContains('plp-filter-conf-b', $skus);

        $items = $this->filterItemsFor($attributeCode);
        $this->assertNotEmpty(
            $items,
            sprintf('Layered navigation exposed no options for configurable attribute "%s"', $attributeCode)
        );

        // Every option offered must correspond to at least one product,
        // otherwise the shopper gets a filter that leads to an empty listing.
        foreach ($items as $item) {
            /** @var Item $item */
            $this->assertGreaterThan(
                0,
                (int)$item->getCount(),
                sprintf('Filter option "%s" is offered with a zero product count', (string)$item->getLabel())
            );
        }
    }

    #[
        DbIsolation(false),
        DataFixture(
            ConfigurableAttributeFixture::class,
            ['is_filterable' => 1, 'is_filterable_in_search' => 1, '_options' => [[], []]],
            as: 'attr'
        ),
        DataFixture(CategoryFixture::class, as: 'cat'),
        DataFixture(ProductFixture::class, as: 'v1'),
        DataFixture(ProductFixture::class, as: 'v2'),
        DataFixture(
            ConfigurableProductFixture::class,
            [
                'sku' => 'plp-narrow-conf',
                '_options' => ['$attr$'],
                '_links' => ['$v1$', '$v2$'],
                'category_ids' => ['$cat.id$'],
            ],
            as: 'conf'
        ),
        DataFixture(
            ProductFixture::class,
            ['sku' => 'plp-narrow-simple', 'category_ids' => ['$cat.id$']],
            as: 'simple'
        ),
    ]
    public function testApplyingConfigurableFilterNarrowsTheListing(): void
    {
        $fixtures = DataFixtureStorageManager::getStorage();
        $categoryId = (int)$fixtures->get('cat')->getId();
        $attributeCode = $fixtures->get('attr')->getAttributeCode();

        $this->reindex();
        $this->dispatchCategory($categoryId);

        // Asserted at the layer, not through a second dispatch. Query params
        // appended to a dispatch URI are not turned into request params by the
        // test framework, so the page renders unfiltered — this is core's own
        // approach in LayeredNavigation\Block\Navigation\AbstractFiltersTest.
        $category = $this->_objectManager->get(CategoryRepositoryInterface::class)->get($categoryId);

        $this->_objectManager->removeSharedInstance('categoryFilterList');
        $layerResolver = $this->_objectManager->create(Resolver::class);
        $layerResolver->create(Resolver::CATALOG_LAYER_CATEGORY);

        /** @var CategoryNavigationBlock $navigation */
        $navigation = $this->_objectManager->create(
            CategoryNavigationBlock::class,
            ['layerResolver' => $layerResolver]
        );
        $navigation->getLayer()->setCurrentCategory($category);

        $filter = null;
        foreach ($navigation->getFilters() as $candidate) {
            $model = $candidate->getData('attribute_model');
            if ($model && $model->getAttributeCode() === $attributeCode) {
                $filter = $candidate;
                break;
            }
        }
        $this->assertNotNull($filter, sprintf('No layered filter for attribute "%s"', $attributeCode));

        $items = $filter->getItems();
        $this->assertNotEmpty($items, 'Configurable attribute filter has no options');

        $unfilteredSize = (int)$navigation->getLayer()->getProductCollection()->getSize();
        $this->assertSame(2, $unfilteredSize, 'Expected the configurable and the simple product');

        // Applying one variant value must keep the configurable and drop the
        // simple product, which has no value for this attribute at all.
        $optionValue = (string)$items[0]->getValue();
        $expectedMatches = (int)$items[0]->getCount();
        $this->assertNotSame('', $optionValue, 'Filter option has an empty value');
        $this->assertGreaterThan(0, $expectedMatches, 'Filter option reports no products');

        $navigation->getRequest()->setParam($filter->getRequestVar(), $optionValue);

        $this->_objectManager->removeSharedInstance('categoryFilterList');
        $filteredResolver = $this->_objectManager->create(Resolver::class);
        $filteredResolver->create(Resolver::CATALOG_LAYER_CATEGORY);
        /** @var CategoryNavigationBlock $filteredNavigation */
        $filteredNavigation = $this->_objectManager->create(
            CategoryNavigationBlock::class,
            ['layerResolver' => $filteredResolver]
        );
        // Order matters and mirrors core's AbstractFiltersTest: params first,
        // then the category, then setLayout() — it is _prepareLayout() that
        // walks the filters and pushes the request value into the layer state.
        $filteredNavigation->getRequest()->setParams([$filter->getRequestVar() => $optionValue]);
        $filteredNavigation->getLayer()->setCurrentCategory($category);
        $filteredNavigation->setLayout($this->_objectManager->get(LayoutInterface::class));

        $activeFilters = $filteredNavigation->getLayer()->getState()->getFilters();
        $this->assertCount(1, $activeFilters, 'Filter was not applied to the layer state');

        $filteredSize = (int)$filteredNavigation->getLayer()->getProductCollection()->getSize();

        $this->assertLessThan(
            $unfilteredSize,
            $filteredSize,
            'Applying the configurable attribute filter did not narrow the listing'
        );
        $this->assertSame(
            $expectedMatches,
            $filteredSize,
            'Filtered product count does not match the count the filter advertised'
        );
    }
}
