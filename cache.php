<?php
/**
 * Shell script that interfaces with the Magento cache.
 *
 * @category    Ash
 * @package     Ash_Shell
 * @copyright   Copyright (c) 2015 August Ash, Inc. (http://www.augustash.com)
 * @license     LICENSE.txt (MIT)
 */

require_once realpath(dirname(__FILE__) . '/../../../shell/') . '/abstract.php';

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Cache Shell Script
 *
 * @category    Ash
 * @package     Ash_Shell
 * @author      August Ash Team <core@augustash.com>
 */
class Ash_Shell_Cache extends Mage_Shell_Abstract
{
    /**
     * Run script
     *
     * @return  void
     */
    public function run()
    {
        if ($this->getArg('list')) {
            $this->listAll();
        } elseif ($this->getArg('enable')) {
            $types = $this->_parseCacheTypeString($this->getArg('enable'));
            $this->enable($types);
        } elseif ($this->getArg('disable')) {
            $types = $this->_parseCacheTypeString($this->getArg('disable'));
            $this->disable($types);
        } elseif ($this->getArg('flush')) {
            switch ($this->getArg('flush')) {
                case 'magento':
                    $this->cleanCache();
                    break;
                case 'storage':
                    $this->flushCache();
                    break;
                default:
                    echo "The flush type must be magento|storage\n";
                    break;
            }
        } elseif ($this->getArg('refresh')) {
            $types = $this->_parseCacheTypeString($this->getArg('refresh'));
            $this->refresh($types);
        } elseif ($this->getArg('cleanmedia')) {
            $this->cleanMediaCache();
        } elseif ($this->getArg('cleanimages')) {
            $this->cleanImagesCache();
        } else if ($this->getArg('purge')) {
            $this->purgeAllCache();
        } else {
            echo $this->usageHelp();
        }
    }

    /**
     * Returns a list of cache types
     *
     * @return void
     */
    public function listAll()
    {
        $header            = array('Cache ID', 'Status', 'State', 'Type');
        $rows              = array();
        $invalidatedCaches = $this->_getInvalidatedTypes();

        foreach ($this->_getCacheTypes() as $cache) {
            if ($cache->status == 'Enabled') {
                $invalidated = (array_key_exists($cache, $invalidatedCaches)) ? 'Invalid': 'Valid';
            } else {
                $invalidated = 'N/A';
            }

            $rows[] = array(
                $cache->id,
                $cache->status,
                $invalidated,
                $cache->cache_type
            );
        }

        $this->_renderTable($header, $rows);
    }

    /**
     * Purge all Magento caches at once. Be careful brah!
     *
     * @return  Ash_Shell_Cache
     */
    public function purgeAllCache()
    {
        $this->refresh()
             ->cleanImagesCache()
             ->cleanMediaCache()
             ->cleanCache()
             ->flushCache();

        return $this;
    }

    /**
     * Flush cache storage (can clear other non-Magento cache data)
     *
     * @return  Ash_Shell_Cache
     */
    public function flushCache()
    {
        try {
            Mage::app()->getCacheInstance()->flush();
            echo "The cache storage has been flushed.\n";
        } catch (Exception $e) {
            sprintf("Exception:\n%s\n", $e->getMessage());
        }

        return $this;
    }

    /**
     * Flush cached data by tag (defaults to MAGE data)
     *
     * @return  Ash_Shell_Cache
     */
    public function cleanCache()
    {
        try {
            Mage::app()->cleanCache();
            echo "The cache has been cleaned.\n";
        } catch (Exception $e) {
            sprintf("Exception:\n%s\n", $e->getMessage());
        }

        return $this;
    }

    /**
     * Refresh cache for specific cache type
     *
     * @param   array $types
     * @return  Ash_Shell_Cache
     */
    public function refresh(array $types)
    {
        $refreshCount = 0;

        // if empty, refresh all cache types
        if (empty($types)) {
            $availableTypes = $this->_getCacheTypeCodes();
            foreach ($availableTypes as $cache) {
                $types[] = $cache;
            }
        }

        foreach ($types as $type) {
            try {
                Mage::app()->getCacheInstance()->cleanType($type);
                $refreshCount++;
            } catch (Exception $e) {
                sprintf("%s cache error:\n%s\n", $type, $e->getMessage());
            }
        }

        if ($refreshCount > 0) {
            sprintf("%s cache type(s) refreshed.\n", $refreshCount);
        }

        return $this;
    }

    /**
     * Flush the merged JS/CSS cache
     *
     * @return  Ash_Shell_Cache
     */
    public function cleanMediaCache()
    {
        try {
            Mage::getModel('core/design_package')->cleanMergedJsCss();
            Mage::dispatchEvent('clean_media_cache_after');
            echo "The JavaScript/CSS cache has been cleaned.\n";
        } catch (Exception $e) {
            sprintf("Exception:\n%s\n", $e->getMessage());
        }

        return $this;
    }

    /**
     * Flush the image cache
     *
     * @return  Ash_Shell_Cache
     */
    public function cleanImagesCache()
    {
        try {
            Mage::getModel('catalog/product_image')->clearCache();
            Mage::dispatchEvent('clean_catalog_images_cache_after');
            echo "The image cache has been cleaned.\n";
        } catch (Exception $e) {
            sprintf("Exception:\n%s\n", $e->getMessage());
        }

        return $this;
    }

    /**
     * Enable caching for specific cache types
     *
     * @param   array $types
     * @return  Ash_Shell_Cache
     */
    public function enable(array $types)
    {
        $availableTypes = $this->_getCacheTypes();
        $enabledCount   = 0;

        foreach ($types as $type) {
            if (empty($availableTypes[$type])) {
                $availableTypes[$type] = 1;
                $enabledCount++;
            }
        }

        if ($enabledCount > 0) {
            Mage::app()->saveUseCache($availableTypes);
            sprintf("%s cache type(s) enabled.\n", $enabledCount);
        }

        return $this;
    }

    /**
     * Disable caching for specific cache types
     *
     * @param   array $types
     * @return  Ash_Shell_Cache
     */
    public function disable($types)
    {
        $availableTypes = $this->_getCacheTypes();
        $disabledCount  = 0;

        foreach ($types as $type) {
            if (!empty($availableTypes[$type])) {
                $availableTypes[$type] = 0;
                $disabledCount++;
            }
            Mage::app()->getCacheInstance()->cleanType($type);
        }

        if ($disabledCount > 0) {
            Mage::app()->saveUseCache($availableTypes);
            sprintf("%s cache type(s) disabled.\n", $disabledCount);
        }

        return $this;
    }

    /**
     * Render array data as a table using Symfony components
     *
     * @param   array $headers
     * @param   array $rows
     * @return  void
     */
    protected function _renderTable(array $headers, array $rows)
    {
        // generate table
        $table = new Table(new ConsoleOutput());
        $table
            ->setHeaders($headers)
            ->setRows($rows);
        $table->render();
    }

    /**
     * Get valid list of cache types
     *
     * @param   string $string
     * @return  array
     */
    protected function _parseCacheTypeString($string)
    {
        $cachetypes = array();

        if (!empty($string)) {
            $types = explode(',', $string);
            foreach ($types as $cacheCode) {
                $cachetypes[] = $cacheCode;
            }
        }

        return $cachetypes;
    }

    /**
     * Get all cache types
     *
     * @return  array
     */
    private function _getCacheTypes()
    {
        return Mage::getModel('core/cache')->getTypes();
    }

    /**
     * Get array of cache type codes
     *
     * @return  array
     */
    private function _getCacheTypeCodes()
    {
        return array_keys($this->_getCacheTypes());
    }

    /**
     * Get array of invalidated cache types
     *
     * @return  array
     */
    private function _getInvalidatedTypes()
    {
        return Mage::getModel('core/cache')->getInvalidatedTypes();
    }

    /**
     * Retrieve usage help message
     *
     * @return  void
     */
    public function usageHelp()
    {
        return <<<USAGE
Usage:  php -f cache.php -- [options]
  list                          Show Magento cache types
  --enable <cachetype>          Enable caching for a cachetype
  --disable <cachetype>         Disable caching for a cachetype
  --refresh <cachetype>         Clean cache types
  --flush <magento|storage>     Flush cache storage or Magento cache

  cleanmedia                    Clean the JS/CSS cache
  cleanimages                   Clean the image cache
  purge                         Flush all caches
  help                          This help

  <cachetype>     Comma separated cache codes or value "all" for all caches

USAGE;
    }
}

$shell = new Ash_Shell_Cache();
$shell->run();
