<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework\Config;

use Magento\Framework\App\Cache\Type\Config as ConfigCacheType;
use Magento\Framework\View\Config as ViewConfig;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * The merged view configuration must be read from disk once, then cached.
 *
 * Every view.xml that applies to a theme was previously read, XSD-validated,
 * DOM-merged and converted on every single request - Config\View extends
 * Reader\Filesystem and had no cache, while Framework\View\Config memoised only
 * for the current request. Measured at 14.1 ms for the first call on a Luma page.
 *
 * These tests pin both halves of the fix: that the second read comes from the
 * cache, and that what comes back is exactly what the files produced.
 *
 * @magentoAppArea frontend
 * @magentoAppIsolation enabled
 */
class ViewConfigCacheTest extends TestCase
{
    private const AREA = 'frontend';

    /**
     * @var CountingConfigCache|null
     */
    private ?CountingConfigCache $cache = null;

    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $real = $objectManager->get(ConfigCacheType::class);
        $this->cache = new CountingConfigCache($real);
        $objectManager->addSharedInstance($this->cache, CacheInterface::class);
        $objectManager->addSharedInstance($this->cache, ConfigCacheType::class);
    }

    private function readMediaAttributes(): array
    {
        // A fresh View\Config every time, so a fresh Config\View underneath it:
        // the point is what happens across instances, since View\Config already
        // memoises within one. Whatever theme this install is configured with is
        // the right one to ask for.
        /** @var ViewConfig $viewConfig */
        $viewConfig = Bootstrap::getObjectManager()->create(ViewConfig::class);
        $config = $viewConfig->getViewConfig(['area' => self::AREA]);

        return $config->getMediaAttributes('Magento_Catalog', 'images', 'category_page_grid');
    }

    public function testSecondInstanceReadsTheMergedConfigFromCache(): void
    {
        $this->cache->clean(\Zend_Cache::CLEANING_MODE_ALL);
        $this->cache->reset();

        $cold = $this->readMediaAttributes();
        $coldSaves = $this->cache->savesFor('view_config_');
        $this->assertCount(
            1,
            $coldSaves,
            'The first read should have stored exactly one merged view config entry'
        );

        $this->cache->reset();
        $warm = $this->readMediaAttributes();

        $this->assertSame(
            [],
            $this->cache->savesFor('view_config_'),
            'A second instance re-read and re-stored the config, so it is not being cached'
        );
        $this->assertCount(
            1,
            $this->cache->loadsFor('view_config_'),
            'A second instance should look the merged config up in the cache exactly once'
        );
        $this->assertSame($cold, $warm, 'The cached config differs from what the files produced');
    }

    public function testCachedConfigIsNotEmptyAndSurvivesTheRoundTrip(): void
    {
        $this->cache->clean(\Zend_Cache::CLEANING_MODE_ALL);

        $cold = $this->readMediaAttributes();

        // Guard against the test passing on two identically empty results, which
        // would make the comparison above meaningless.
        $this->assertNotEmpty($cold, 'No media attributes were resolved; the test proves nothing');
        $this->assertArrayHasKey('width', $cold);

        $this->assertSame($cold, $this->readMediaAttributes());
    }
}
