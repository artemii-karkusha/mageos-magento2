<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework\Config;

use Magento\Framework\Cache\FrontendInterface;

/**
 * Counting decorator over the config cache, used by ViewConfigCacheTest.
 *
 * It delegates everything, so behaviour is unchanged; it only records how often
 * the merged view configuration was looked up and how often it had to be stored.
 */
class CountingConfigCache implements CacheInterface
{
    /**
     * @var FrontendInterface
     */
    private FrontendInterface $subject;

    /**
     * @var string[]
     */
    private array $loads = [];

    /**
     * @var string[]
     */
    private array $saves = [];

    /**
     * @param FrontendInterface $subject
     */
    public function __construct(FrontendInterface $subject)
    {
        $this->subject = $subject;
    }

    /**
     * Identifiers loaded since the last reset, in order.
     *
     * @param string $prefix
     * @return string[]
     */
    public function loadsFor(string $prefix): array
    {
        return array_values(array_filter($this->loads, static fn ($id) => str_starts_with($id, $prefix)));
    }

    /**
     * Identifiers saved since the last reset, in order.
     *
     * @param string $prefix
     * @return string[]
     */
    public function savesFor(string $prefix): array
    {
        return array_values(array_filter($this->saves, static fn ($id) => str_starts_with($id, $prefix)));
    }

    /**
     * Forget everything recorded so far.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->loads = [];
        $this->saves = [];
    }

    /**
     * @inheritdoc
     */
    public function load($identifier)
    {
        $this->loads[] = (string)$identifier;

        return $this->subject->load($identifier);
    }

    /**
     * @inheritdoc
     */
    public function save($data, $identifier, array $tags = [], $lifeTime = null)
    {
        $this->saves[] = (string)$identifier;

        return $this->subject->save($data, $identifier, $tags, $lifeTime);
    }

    /**
     * @inheritdoc
     */
    public function test($identifier)
    {
        return $this->subject->test($identifier);
    }

    /**
     * @inheritdoc
     */
    public function remove($identifier)
    {
        return $this->subject->remove($identifier);
    }

    /**
     * @inheritdoc
     */
    public function clean($mode = \Zend_Cache::CLEANING_MODE_ALL, array $tags = [])
    {
        return $this->subject->clean($mode, $tags);
    }

    /**
     * @inheritdoc
     */
    public function getBackend()
    {
        return $this->subject->getBackend();
    }

    /**
     * @inheritdoc
     */
    public function getLowLevelFrontend()
    {
        return $this->subject->getLowLevelFrontend();
    }
}
