<?php
namespace PhotoLibrary;

/**
 * Main class for accessing an iPhoto .photolibrary package
 *
 * @author Robbert Klarenbeek <robbertkl@renbeek.nl>
 * @copyright 2013 Robbert Klarenbeek
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */
class Library
{
    /**
     * Path to the library on this filesystem
     *
     * @var string
     */
    protected $path;

    /**
     * The plist data from AlbumData.xml as array
     *
     * @var array
     */
    protected $data;

    /**
     * Associative array (int => Album) of cached Album objects by ID
     *
     * @var array
     */
    protected $albums = null;

    /**
     * Zend Cache Storage Adapter to use for caching the plist data from AlbumData.xml
     *
     * @var \Zend\Cache\Storage\StorageInterface
     */
    protected static $cache = null;

    /**
     * Connection to Faces.db.
     *
     * @var PDO
     */
    protected $faceDb = null;

    /**
     * Set a Zend Cache storage adapter to use for caching the plist data from AlbumData.xml
     *
     * @param \Zend\Cache\Storage\StorageInterface $cache storage adapter which supports at least 'array' datatype and 'mtime' metadata
     */
    public static function setCache(\Zend\Cache\Storage\StorageInterface $cache)
    {
        // We will delay checking the Storage adapter capabilities until its first use
        self::$cache = $cache;
    }

    /**
     * Check whether a cache will be used or not (e.g. if no cache has been set or if its capabilities are insufficient)
     *
     * @return boolean true iff a cache will be used, i.e. if a cache with the right capabilities has been set
     */
    public static function isUsingCache()
    {
        try {
            self::validateCache();
        } catch (\Exception $exception) {
            return false;
        }
        return true;
    }

    /**
     * Validates whether we have a cache and whether it has the right capabilities
     *
     * @throws \Exception if no cache has been set or its capabilities meet the requirements
     */
    protected static function validateCache()
    {
        if (is_null(self::$cache)) {
            throw new \RuntimeException('No cache storage has been set');
        }

        $supportedDataTypes = self::$cache->getCapabilities()->getSupportedDatatypes();
        if (!array_key_exists('array', $supportedDataTypes) || !$supportedDataTypes['array']) {
            throw new \UnexpectedValueException('Cache storage does not support array datatype');
        }

        $supportedMetadata = self::$cache->getCapabilities()->getSupportedMetadata();
        if (!in_array('mtime', $supportedMetadata)) {
            throw new \UnexpectedValueException('Cache storage does not support mtime metadata');
        }
    }

    /**
     * Load an entry belonging to the given key from our cache (if any)
     *
     * @param string $key key to look for in the cache (should match /^[a-z0-9_+-]*$/Di)
     * @param int $mtime unix timestamp to compare the cache entry with (the entry will be ignored if older than $mtime)
     * @return mixed entry from cache or false if its key isn't found in cache or the entry is too old
     */
    protected static function loadFromCache($key, $mtime = 0)
    {
        try {
            self::validateCache();
        } catch (\Exception $exception) {
            // Fake an exception from getItem, so the cache's own EventManager can determine what to do with it
            $result = false;
            return self::triggerCacheException('getItem', array('key' => &$key), $result, $exception);
        }

        if (!self::$cache->hasItem($key)) {
            return false;
        }

        $meta = self::$cache->getMetadata($key);
        if (!array_key_exists('mtime', $meta) || $meta['mtime'] < $mtime) {
            return false;
        }

        return self::$cache->getItem($key);
    }

    /**
     * Store an entry in our cache (if any)
     *
     * @param string $key key used to store the entry in the cache (should match /^[a-z0-9_+-]*$/Di)
     * @param mixed $value entry to store in the cache
     */
    protected static function storeInCache($key, &$value)
    {
        try {
            self::validateCache();
        } catch (\Exception $exception) {
            // Fake an exception from setItem, so the cache's own EventManager can determine what to do with it
            $result = false;
            return self::triggerCacheException('setItem', array('key' => &$key, 'value' => &$value), $result, $exception);
        }

        return self::$cache->setItem($key, $value);
    }

    /**
     * Fakes an exception in the cache, so that the cache's own EventManager decides what to do with it (throw or continue without cache)
     *
     * @param string $eventName name of faked storage adapter method (e.g. getItem or setItem)
     * @param array $args arguments for the faked method call to the storage adapter
     * @param mixed $result return value of the faked method call in case the exception will not be (re)thrown
     * @param \Exception $exception exception which 'supposedly' got thrown within the faked method
     * @return mixed the return value after triggering an ExceptionEvent at the EventManager (most likely the original $result)
     * @throws \Exception the original $exception, depending on the ExceptionEvent's 'ThrowException' value after being triggered at the EventManager
     * @see \Zend\Cache\Storage\Adapter\AbstractAdapter::triggerException()
     */
    protected static function triggerCacheException($eventName, $args, &$result, \Exception $exception)
    {
        if (is_null(self::$cache)) {
            return $result;
        }

        if (is_array($args)) {
            $args = new \ArrayObject($args);
        }

        $exceptionEvent = new \Zend\Cache\Storage\ExceptionEvent($eventName . '.exception', self::$cache, $args, $result, $exception);
        $responseCollection = self::$cache->getEventManager()->trigger($exceptionEvent);

        if ($exceptionEvent->getThrowException()) {
            throw $exceptionEvent->getException();
        }

        if ($responseCollection->stopped()) {
            return $responseCollection->last();
        }

        return $exceptionEvent->getResult();
    }

    /**
     * Create new Library from a path
     *
     * @param string $path path to the .photolibrary directory (with or without trailing /)
     */
    public function __construct($path)
    {
        $path = rtrim($path, DIRECTORY_SEPARATOR);
        $plistPath = $path . DIRECTORY_SEPARATOR . 'AlbumData.xml';
        
        if (!is_dir($path) || !is_file($plistPath)) {
            throw new \InvalidArgumentException('Given path does not seem to be an iPhoto library package');
        }

        // Since loading/parsing the plist can be quite heavy, caching was added here
        $cacheKey = preg_replace('/[^A-Za-z0-9_+-]/', '--', realpath($path));
        $data = self::loadFromCache($cacheKey, filemtime($plistPath));
        if ($data === false) {
            // These next 2 lines can take a while, depending on the size of the plist / your library
            $plist = new \CFPropertyList\CFPropertyList($plistPath);
            $data = $plist->toArray();

            // Get rid of this large data asap
            unset($plist);

            self::storeInCache($cacheKey, $data);
        }

        $this->path = $path;
        $this->data = &$data;
    }

    /**
     * Ensure the album cache ($this->albums) is filled with albums of this library
     */
    protected function ensureAlbums()
    {
        if (is_null($this->albums)) {
            $this->albums = array();
            foreach ($this->data['List of Albums'] as &$albumData) {
                $album = new Album($this, $albumData);
                $this->albums[$album->getId()] = $album;
            }
        }
    }

    /**
     * Get the number of albums in this library
     *
     * @return int number of albums in this library
     */
    public function getAlbumCount()
    {
        return count($this->data['List of Albums']);
    }

    /**
     * Get all albums in this library
     *
     * @return Album[] list of Album objects
     */
    public function getAlbums()
    {
        $this->ensureAlbums();
        return array_values($this->albums);
    }

    /**
     * Get all albums of a specific "Album Type"
     *
     * @param string $type album type to look for, e.g. "Flagged", "Regular", "Event"
     * @return Album[] list of Album objects
     */
    public function getAlbumsOfType($type)
    {
        $this->ensureAlbums();
        $albums = array();
        foreach ($this->albums as $id => $album) {
            if ($album->getType() == $type) {
                $albums[] = $album;
            }
        }
        return $albums;
    }

    /**
     * Get an album by its "Album ID"
     *
     * @param int $id Album ID of the album to get
     * @return Album album with the given ID, or null iff not found
     */
    public function getAlbum($id)
    {
        $this->ensureAlbums();
        if (!array_key_exists($id, $this->albums)) {
            return null;
        }
        return $this->albums[$id];
    }

    /**
     * Get a photo by its key
     *
     * @param int $key key of the photo to get
     * @return Photo photo with the given key, or null iff not found
     */
    public function getPhoto($key)
    {
        if (!array_key_exists($key, $this->data['Master Image List'])) {
            return null;
        }
        return new Photo($this, $key, $this->data['Master Image List'][$key]);
    }

    /**
     * Rewrite an internal photolibrary path (called the "Archive Path" to the real one on disk
     *
     * @param string $path path to a file from the AlbumData.xml plist
     * @return string rewritten path to the real file (of the current .photolibrary)
     */
    public function rewritePath($path)
    {
        $archivePath = dirname($this->data['Archive Path']);
        $realPath = dirname($this->path);
        return preg_replace('/^' . preg_quote($archivePath, '/') . '/', $realPath, $path);
    }

    /**
     * Look up the name of a Face based on the face key.
     *
     * @param int $face_key
     * @return string The face name.
     */
    public function getFaceName($faceKey)
    {
        if (is_null($this->faceDb)) {
            $possibleFaceDbLocations = array('Database' . DIRECTORY_SEPARATOR . 'apdb' . DIRECTORY_SEPARATOR . 'Faces.db', 'Database' . DIRECTORY_SEPARATOR .

            foreach ($possibleFaceDbLocations as $faceDbPath) {
                try {
                    $this->faceDb = new \PDO('sqlite:' . $this->path . DIRECTORY_SEPARATOR . $faceDbPath);
                } catch (Exception $e) {
                }

                if ($this->faceDb) {
                    break;
                }
            }
        }

        if (!$this->faceDb) {
            return '';
        }

        $statement = $this->faceDb->prepare('SELECT name FROM RKFaceName WHERE faceKey = ?');
        $statement->execute(array($faceKey));
        return $statement->fetchColumn();
    }
}
