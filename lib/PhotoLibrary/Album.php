<?php
namespace PhotoLibrary;

/**
 * Class representing an album (or event) from the iPhoto .photolibrary package
 *
 * @author Robbert Klarenbeek <robbertkl@renbeek.nl>
 * @copyright 2013 Robbert Klarenbeek
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */
class Album
{
    /**
     * Link back to the library to which this album belongs
     *
     * @var Library
     */
    protected $library;

    /**
     * Associative array with raw album properties
     *
     * @var array
     */
    protected $data;

    /**
     * Create new Album by supplying the raw properties
     *
     * @param Library $library library to which this album belongs
     * @param array $data associative array with raw album properties
     * @see Library::ensureAlbums()
     */
    public function __construct(Library $library, $data)
    {
        $this->library = $library;
        $this->data = $data;
    }

    /**
     * Get the ID of this album
     *
     * @return int ID of this album
     */
    public function getId()
    {
        return intval($this->data['AlbumId']);
    }

    /**
     * Get the name of this album
     *
     * @return string name of this album
     */
    public function getName()
    {
        return $this->data['AlbumName'];
    }

    /**
     * Get the type (property "Album Type") of this album
     *
     * @return string type of this album, e.g. "Flagged", "Regular", "Event"
     */
    public function getType()
    {
        return $this->data['Album Type'];
    }

    /**
     * Get the key photo for this album
     *
     * @return Photo object representing the key photo for this album
     */
    public function getKeyPhoto()
    {
        return $this->library->getPhoto($this->data['KeyPhotoKey']);
    }

    /**
     * Get the number of photos in this album
     *
     * @return int number of photos in this album
     */
    public function getPhotoCount()
    {
        return intval($this->data['PhotoCount']);
    }

    /**
     * Get all photos in this album
     *
     * @return Photo[] list of Photo objects
     */
    public function getPhotos()
    {
        $photos = array();
        foreach ($this->data['KeyList'] as $key) {
            $photos[$key] = $this->library->getPhoto($key);
        }
        return $photos;
    }

    /**
     * Get a photo from this album by its key
     *
     * @param int $key key of the photo to get
     * @return Photo photo with the given key, or null iff not found
     */
    public function getPhoto($key)
    {
        if (!in_array($key, $this->data['KeyList'])) {
            return null;
        }
        return $this->library->getPhoto($key);
    }

    /**
     * Get the string representation of this album
     *
     * @return string string representation of this album (just it's name)
     */
    public function __toString()
    {
        return $this->getName();
    }
}
