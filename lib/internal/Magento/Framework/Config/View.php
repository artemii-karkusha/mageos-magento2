<?php
/**
 * Copyright 2014 Adobe
 * All Rights Reserved.
 */
namespace Magento\Framework\Config;

use Magento\Framework\Serialize\SerializerInterface;

/**
 * View configuration files handler
 *
 * @api
 * @since 100.0.2
 */
class View extends \Magento\Framework\Config\Reader\Filesystem
{
    /**
     * Prefix for the merged view configuration cache entries.
     */
    private const CACHE_ID_PREFIX = 'view_config_';

    /**
     * @var array
     */
    protected $xpath;

    /**
     * View config data
     *
     * @var array
     */
    protected $data;

    /**
     * @var CacheInterface|null
     */
    private $cache;

    /**
     * @var SerializerInterface|null
     */
    private $serializer;

    /**
     * Identifies which theme and area this instance resolves files for.
     *
     * Empty when the caller did not say, in which case the merged result is not
     * cached: the file set would then depend on whatever design is current, and
     * a single cache entry could not represent it correctly.
     *
     * @var string
     */
    private $cacheSuffix;

    /**
     * @param FileResolverInterface $fileResolver
     * @param ConverterInterface $converter
     * @param SchemaLocatorInterface $schemaLocator
     * @param ValidationStateInterface $validationState
     * @param string $fileName
     * @param array $idAttributes
     * @param string $domDocumentClass
     * @param string $defaultScope
     * @param array $xpath
     * @param CacheInterface|null $cache
     * @param SerializerInterface|null $serializer
     * @param string $cacheSuffix
     */
    public function __construct(
        FileResolverInterface $fileResolver,
        ConverterInterface $converter,
        SchemaLocatorInterface $schemaLocator,
        ValidationStateInterface $validationState,
        $fileName,
        $idAttributes = [],
        $domDocumentClass = \Magento\Framework\Config\Dom::class,
        $defaultScope = 'global',
        $xpath = [],
        ?CacheInterface $cache = null,
        ?SerializerInterface $serializer = null,
        string $cacheSuffix = ''
    ) {
        $this->xpath = $xpath;
        $this->cache = $cache;
        $this->serializer = $serializer;
        $this->cacheSuffix = $cacheSuffix;
        $idAttributes = $this->getIdAttributes();
        parent::__construct(
            $fileResolver,
            $converter,
            $schemaLocator,
            $validationState,
            $fileName,
            $idAttributes,
            $domDocumentClass,
            $defaultScope
        );
    }

    /**
     * Get a list of variables in scope of specified module
     *
     * Returns array(<var_name> => <var_value>)
     *
     * @param string $module
     * @return array
     */
    public function getVars($module)
    {
        $this->initData();
        return $this->data['vars'][$module] ?? [];
    }

    /**
     * Get value of a configuration option variable
     *
     * @param string $module
     * @param string $var
     * @return string|false|array
     */
    public function getVarValue($module, $var)
    {
        $this->initData();
        if (!isset($this->data['vars'][$module])) {
            return false;
        }

        $value = $this->data['vars'][$module];
        foreach (explode('/', $var ?: '') as $node) {
            if (is_array($value) && isset($value[$node])) {
                $value = $value[$node];
            } else {
                return false;
            }
        }

        return $value;
    }

    /**
     * Retrieve a list media attributes in scope of specified module
     *
     * @param string $module
     * @param string $mediaType
     * @return array
     */
    public function getMediaEntities($module, $mediaType)
    {
        $this->initData();
        return $this->data['media'][$module][$mediaType] ?? [];
    }

    /**
     * Retrieve array of media attributes
     *
     * @param string $module
     * @param string $mediaType
     * @param string $mediaId
     * @return array
     */
    public function getMediaAttributes($module, $mediaType, $mediaId)
    {
        $this->initData();
        return $this->data['media'][$module][$mediaType][$mediaId] ?? [];
    }

    /**
     * Variables are identified by module and name
     *
     * @return array
     */
    protected function getIdAttributes()
    {
        $idAttributes = [
            '/view/vars' => 'module',
            '/view/vars/(var/)*var' => 'name',
            '/view/exclude/item' => ['type', 'item'],
        ];
        foreach ($this->xpath as $attribute) {
            if (is_array($attribute)) {
                foreach ($attribute as $key => $id) {
                    if (count($id) > 1) {
                        $idAttributes[$key] = array_values($id);
                    } else {
                        $idAttributes[$key] = array_shift($id);
                    }
                }
            }
        }
        return $idAttributes;
    }

    /**
     * Get excluded file list
     *
     * @return array
     */
    public function getExcludedFiles()
    {
        $items = $this->getItems();
        return $items['file'] ?? [];
    }

    /**
     * Get excluded directory list
     *
     * @return array
     */
    public function getExcludedDir()
    {
        $items = $this->getItems();
        return $items['directory'] ?? [];
    }

    /**
     * Get a list of excludes
     *
     * @return array
     */
    protected function getItems()
    {
        $this->initData();
        return $this->data['exclude'] ?? [];
    }

    /**
     * Initialize data array
     *
     * @return void
     */
    protected function initData()
    {
        if ($this->data === null) {
            $this->data = $this->read();
        }
    }

    /**
     * @inheritdoc
     *
     * @since 100.1.0
     */
    public function read($scope = null)
    {
        $scope = $scope ?: $this->_defaultScope;

        if ($this->cache === null || $this->serializer === null || $this->cacheSuffix === '') {
            return $this->readAndMerge($scope);
        }

        $cacheId = self::CACHE_ID_PREFIX . preg_replace(
            '/[^a-zA-Z0-9_]/',
            '_',
            $this->cacheSuffix . '_' . $scope
        );

        $cached = $this->cache->load($cacheId);
        if ($cached !== false) {
            return $this->serializer->unserialize($cached);
        }

        $result = $this->readAndMerge($scope);
        // Saved through the config cache frontend, which applies its own CONFIG
        // tag, so `bin/magento cache:clean config` clears this with every other
        // merged XML config.
        $this->cache->save($this->serializer->serialize($result), $cacheId);

        return $result;
    }

    /**
     * Read every view.xml that applies to this scope and merge them, parents first.
     *
     * @param string $scope
     * @return array
     */
    private function readAndMerge($scope)
    {
        $result = [];

        $parents = (array)$this->_fileResolver->getParents($this->_fileName, $scope);
        // Sort parents desc
        krsort($parents);

        foreach ($parents as $parent) {
            $result = array_replace_recursive($result, $this->_readFiles([$parent]));
        }

        return array_replace_recursive($result, parent::read($scope));
    }
}
