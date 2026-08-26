<?php
/**
 * Copyright 2015 Adobe
 * All Rights Reserved.
 */

namespace Magento\Framework\Config;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\View\Design\ThemeInterface;

/**
 * @api
 */
class ViewFactory
{
    /**
     * @var \Magento\Framework\ObjectManagerInterface
     */
    private $objectManager;

    /**
     * @var Filesystem|null
     */
    private $filesystem;

    /**
     * Constructor
     *
     * @param \Magento\Framework\ObjectManagerInterface $objectManager
     */
    public function __construct(ObjectManagerInterface $objectManager)
    {
        $this->objectManager = $objectManager;
    }

    /**
     * Create new view object
     *
     * @param array $arguments
     * @return \Magento\Framework\Config\View
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function create(array $arguments = [])
    {
        $viewConfigArguments = [];

        if (isset($arguments['themeModel']) && isset($arguments['area'])) {
            if (!($arguments['themeModel'] instanceof \Magento\Framework\View\Design\ThemeInterface)) {
                throw new \Magento\Framework\Exception\LocalizedException(
                    new \Magento\Framework\Phrase('%1 doesn\'t implement ThemeInterface', [$arguments['themeModel']])
                );
            }
            /** @var \Magento\Theme\Model\View\Design $design */
            $design = $this->objectManager->create(\Magento\Theme\Model\View\Design::class);
            $design->setDesignTheme($arguments['themeModel'], $arguments['area']);
            /** @var \Magento\Framework\Config\FileResolver $fileResolver */
            $fileResolver = $this->objectManager->create(
                \Magento\Framework\Config\FileResolver::class,
                [
                    'designInterface' => $design,
                ]
            );
            $viewConfigArguments['fileResolver'] = $fileResolver;
            // Names the theme and area this instance resolves for, so the merged
            // result can be cached under a key that actually identifies it. The
            // theme's customised view.xml is part of that result and is written at
            // runtime, with no config-cache invalidation behind it, so its state
            // belongs in the key too.
            $viewConfigArguments['cacheSuffix'] = $arguments['themeModel']->getFullPath()
                . '_' . $arguments['area']
                . '_' . $this->getCustomViewConfigStamp($arguments['themeModel']);
        }

        return $this->objectManager->create(
            \Magento\Framework\Config\View::class,
            $viewConfigArguments
        );
    }

    /**
     * Identify the current state of a theme's customised view.xml, if it has one.
     *
     * Returns the file's modification time, or '0' when the theme has no custom
     * view configuration - which is the usual case.
     *
     * @param ThemeInterface $theme
     * @return string
     */
    private function getCustomViewConfigStamp(ThemeInterface $theme)
    {
        $path = $theme->getCustomization()->getCustomViewConfigPath();
        if (!$path) {
            return '0';
        }

        if ($this->filesystem === null) {
            $this->filesystem = $this->objectManager->get(Filesystem::class);
        }

        $directory = $this->filesystem->getDirectoryRead(DirectoryList::ROOT);
        $relativePath = $directory->getRelativePath($path);
        if (!$directory->isExist($relativePath)) {
            return '0';
        }

        $stat = $directory->stat($relativePath);

        return (string)($stat['mtime'] ?? '0');
    }
}
