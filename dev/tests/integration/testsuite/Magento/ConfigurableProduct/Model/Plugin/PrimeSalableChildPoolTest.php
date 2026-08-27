<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\ConfigurableProduct\Model\Plugin;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Block\Product\ListProduct;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\ConfigurableProduct\Model\Product\SalableChildPool;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\ConfigurableProduct\Test\Fixture\Attribute as ConfigurableAttributeFixture;
use Magento\ConfigurableProduct\Test\Fixture\Product as ConfigurableProductFixture;
use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Batching "has a salable child?" must give the same answers as asking one at a time.
 *
 * Configurable::isSalable() answers that with a COUNT over the parent's linked
 * products. A listing asks it for every item, so 48 configurables cost 48
 * counts - 14.2 ms on one Luma category page.
 *
 * PrimeSalableChildPool answers for the whole page in one query, by handing every
 * parent to the same child collection (setProductFilter appends, and the
 * collection turns the lot into a single parent_id IN (...)) and running the same
 * SalableProcessor over it. Using the real processor rather than a hand-written
 * query is the point: "salable" stays defined in one place.
 *
 * The risk in that is answering differently, so these tests compare the batched
 * answers against the unbatched ones for a configurable that is salable and one
 * that is not - the negative case being the one that changes what a listing
 * renders, Add to Cart against Out of stock.
 *
 * @magentoAppArea frontend
 * @magentoAppIsolation enabled
 */
class PrimeSalableChildPoolTest extends TestCase
{
    /**
     * Answers from a Configurable instance that has no pool to consult.
     *
     * @param array $products
     * @return array<string, bool> keyed by sku
     */
    private function unbatchedAnswers(array $products): array
    {
        $answers = [];
        foreach ($products as $product) {
            // A fresh type instance each time: isSalable memoises by sku.
            $type = Bootstrap::getObjectManager()->create(Configurable::class, ['salableChildPool' => null]);
            $answers[$product->getSku()] = $type->isSalable($product);
        }

        return $answers;
    }

    /**
     * Answers once the page-level batch has primed the pool.
     *
     * @param array $products
     * @return array<string, bool> keyed by sku
     */
    private function primePool(array $products): SalableChildPool
    {
        $objectManager = Bootstrap::getObjectManager();
        $pool = $objectManager->create(SalableChildPool::class);

        $collection = $objectManager->get(CollectionFactory::class)->create();
        $collection->addIdFilter(array_map(static fn ($p) => $p->getId(), $products));
        $collection->addAttributeToSelect('*');
        $collection->load();

        $primer = $objectManager->create(PrimeSalableChildPool::class, ['pool' => $pool]);
        $primer->afterGetLoadedProductCollection(
            $objectManager->create(ListProduct::class),
            $collection
        );

        return $pool;
    }

    /**
     * Answers from Configurable instances that consult the given primed pool.
     *
     * @param array $products
     * @param SalableChildPool $pool
     * @return array<string, bool> keyed by sku
     */
    private function answersUsingPool(array $products, SalableChildPool $pool): array
    {
        $answers = [];
        foreach ($products as $product) {
            $type = Bootstrap::getObjectManager()
                ->create(Configurable::class, ['salableChildPool' => $pool]);
            $answers[$product->getSku()] = $type->isSalable($product);
        }

        return $answers;
    }

    /**
     * @param array $products
     * @return array<string, bool> keyed by sku
     */
    private function batchedAnswers(array $products): array
    {
        return $this->answersUsingPool($products, $this->primePool($products));
    }

    #[
        DbIsolation(false),
        DataFixture(ConfigurableAttributeFixture::class, ['_options' => [[], []]], as: 'attr'),
        DataFixture(ProductFixture::class, as: 'in1'),
        DataFixture(ProductFixture::class, as: 'in2'),
        DataFixture(
            ConfigurableProductFixture::class,
            ['sku' => 'salable-conf', '_options' => ['$attr$'], '_links' => ['$in1$', '$in2$']],
            as: 'salableConf'
        ),
    ]
    public function testBatchedAnswerMatchesTheUnbatchedOneForASalableConfigurable(): void
    {
        $fixtures = DataFixtureStorageManager::getStorage();
        $repository = Bootstrap::getObjectManager()->get(ProductRepositoryInterface::class);
        $products = [$repository->get('salable-conf')];

        $unbatched = $this->unbatchedAnswers($products);
        $batched = $this->batchedAnswers($products);

        $this->assertTrue($unbatched['salable-conf'], 'Fixture is wrong: this configurable should be salable');
        $this->assertSame($unbatched, $batched, 'The batch disagreed with counting one at a time');
    }

    #[
        DbIsolation(false),
        DataFixture(ConfigurableAttributeFixture::class, ['_options' => [[], []]], as: 'attr'),
        DataFixture(
            ProductFixture::class,
            ['extension_attributes' => ['stock_item' => ['is_in_stock' => false, 'qty' => 0]]],
            as: 'out1'
        ),
        DataFixture(
            ProductFixture::class,
            ['extension_attributes' => ['stock_item' => ['is_in_stock' => false, 'qty' => 0]]],
            as: 'out2'
        ),
        DataFixture(
            ConfigurableProductFixture::class,
            ['sku' => 'unsalable-conf', '_options' => ['$attr$'], '_links' => ['$out1$', '$out2$']],
            as: 'unsalableConf'
        ),
    ]
    public function testBatchedAnswerMatchesTheUnbatchedOneWhenNoChildIsSalable(): void
    {
        $repository = Bootstrap::getObjectManager()->get(ProductRepositoryInterface::class);
        $products = [$repository->get('unsalable-conf')];

        $unbatched = $this->unbatchedAnswers($products);
        $batched = $this->batchedAnswers($products);

        $this->assertFalse(
            $unbatched['unsalable-conf'],
            'Fixture is wrong: a configurable whose children are all out of stock should not be salable'
        );
        $this->assertSame($unbatched, $batched, 'The batch disagreed with counting one at a time');
    }

    #[
        DbIsolation(false),
        DataFixture(ConfigurableAttributeFixture::class, ['_options' => [[], []]], as: 'attr'),
        DataFixture(ProductFixture::class, as: 'c1'),
        DataFixture(ProductFixture::class, as: 'c2'),
        DataFixture(
            ConfigurableProductFixture::class,
            ['sku' => 'batch-conf-1', '_options' => ['$attr$'], '_links' => ['$c1$', '$c2$']],
            as: 'conf1'
        ),
        DataFixture(
            ConfigurableProductFixture::class,
            ['sku' => 'batch-conf-2', '_options' => ['$attr$'], '_links' => ['$c1$', '$c2$']],
            as: 'conf2'
        ),
    ]
    public function testTheBatchAsksFewerQuestionsThanAskingOneAtATime(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $repository = $objectManager->get(ProductRepositoryInterface::class);
        $products = [$repository->get('batch-conf-1'), $repository->get('batch-conf-2')];

        // Priming is deliberately outside the measurement: the listing loads its
        // collection either way, so what matters is what each ANSWER then costs.
        $pool = $this->primePool($products);

        $pooledCost = $this->countSelects(fn () => $this->answersUsingPool($products, $pool));
        $unpooledCost = $this->countSelects(fn () => $this->unbatchedAnswers($products));

        $this->assertGreaterThanOrEqual(
            count($products),
            $unpooledCost,
            'Asking one at a time should cost at least one query per product; this measures nothing otherwise'
        );
        $this->assertSame(
            0,
            $pooledCost,
            sprintf('Answering from a primed pool still cost %d selects', $pooledCost)
        );
    }

    /**
     * SELECTs issued by the given callable.
     *
     * @param callable $work
     * @return int
     */
    private function countSelects(callable $work): int
    {
        $connection = Bootstrap::getObjectManager()->get(ResourceConnection::class)->getConnection();
        $before = (int)$connection->fetchRow("SHOW SESSION STATUS LIKE 'Com_select'")['Value'];
        $work();

        return (int)$connection->fetchRow("SHOW SESSION STATUS LIKE 'Com_select'")['Value'] - $before;
    }
}
