<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Catalog\Block\Product\ListProduct;

use Magento\Catalog\Model\Product\Url;
use Magento\Catalog\Test\Fixture\Category as CategoryFixture;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\CatalogSearch\Model\Indexer\Fulltext\Processor as FulltextProcessor;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\TestCase\AbstractController;

/**
 * A category listing must build each URL once per product, not twice.
 *
 * Two templates independently asked for the same URL twice per item:
 *
 *   - product/list.phtml calls $_product->getProductUrl() for the image link
 *     and again for the name link (and a third time in list mode).
 *   - Review's helper/summary_short.phtml called $block->getReviewsUrl() twice,
 *     once per fragment, and did so ABOVE its own if/elseif - so a product with
 *     no reviews built two URLs and rendered nothing at all.
 *
 * Neither is a query: Product\Url::getUrl memoises the rewrite lookup onto the
 * product via setRequestPath(). What repeats is the URL *building* -
 * urlFactory->create() plus a full route resolution - which a query-count guard
 * cannot see. Hence counting builds directly.
 *
 * Measured on a 48-product Luma category: 96 product-URL builds and 96
 * review-URL builds, against 48 and 1 after the fix. Worth ~3 ms of a ~160 ms
 * render - the traced figure is roughly three times that, because tracing
 * inflates functions called this often.
 *
 * SCOPE - what this does NOT cover:
 *
 * It asserts call counts, not output. That the listing still renders the right
 * hrefs is covered by PlpProductListTest and the Review block's own tests; this
 * test would happily pass if both templates emitted no links at all, so it is a
 * regression guard rather than a correctness proof.
 *
 * It also covers only the category listing. helper/summary.phtml, the product-page
 * sibling carrying the same defect, is fixed but not guarded here - it renders once
 * per page, so a count assertion would not distinguish the two versions.
 *
 * @magentoAppArea frontend
 * @magentoAppIsolation enabled
 */
class UrlBuildCountTest extends AbstractController
{
    private function installCountingUrlModel(): CountingProductUrl
    {
        /** @var CountingProductUrl $counter */
        $counter = $this->_objectManager->create(CountingProductUrl::class);
        // Product receives its URL model by constructor injection, and the
        // listing's products are created during dispatch - after this point.
        $this->_objectManager->addSharedInstance($counter, Url::class);

        return $counter;
    }

    #[
        DbIsolation(false),
        DataFixture(CategoryFixture::class, as: 'cat'),
        DataFixture(ProductFixture::class, ['category_ids' => ['$cat.id$']], as: 'p1'),
        DataFixture(ProductFixture::class, ['category_ids' => ['$cat.id$']], as: 'p2'),
        DataFixture(ProductFixture::class, ['category_ids' => ['$cat.id$']], as: 'p3'),
        DataFixture(ProductFixture::class, ['category_ids' => ['$cat.id$']], as: 'p4'),
    ]
    public function testEachProductBuildsAtMostOneProductUrlAndOneReviewUrl(): void
    {
        $productCount = 4;
        $fixtures = DataFixtureStorageManager::getStorage();
        $this->_objectManager->create(FulltextProcessor::class)->reindexAll();

        $counter = $this->installCountingUrlModel();

        $this->dispatch('catalog/category/view/id/' . (int)$fixtures->get('cat')->getId());
        $body = (string)$this->getResponse()->getBody();

        $this->assertStringNotContainsString('Whoops', $body, 'Category page failed to render');
        $this->assertGreaterThan(
            0,
            $counter->totalBuilds(),
            'No product URLs were built at all - the listing did not render, so this test proves nothing'
        );

        $this->assertLessThanOrEqual(
            $productCount,
            $counter->productUrlBuilds(),
            sprintf(
                'The listing built %d product URLs for %d products. Each template that needs the '
                . 'product URL should resolve it once per item and reuse it.',
                $counter->productUrlBuilds(),
                $productCount
            )
        );

        $this->assertLessThanOrEqual(
            $productCount,
            $counter->reviewUrlBuilds(),
            sprintf(
                'The listing built %d review URLs for %d products. summary_short.phtml should '
                . 'resolve the review URL once, and only in the branch that renders a link.',
                $counter->reviewUrlBuilds(),
                $productCount
            )
        );
    }
}
