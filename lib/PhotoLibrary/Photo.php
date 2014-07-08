<?php
namespace PhotoLibrary;

/**
 * Class representing a photo within the iPhoto .photolibrary package
 *
 * @author Robbert Klarenbeek <robbertkl@renbeek.nl>
 * @copyright 2013 Robbert Klarenbeek
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */
class Photo
{
    /**
     * Link back to the library to which this photo belongs
     *
     * @var Library
     */
    protected $library;

    /**
     * Key of the photo (a unique identifier within the AlbumData.xml plist)
     *
     * @var int
     */
    protected $key;

    /**
     * Associative array with raw photo properties
     *
     * @var array
     */
    protected $data;

    /**
     * Create new Photo by supplying the raw properties
     *
     * @param Library $library library to which this photo belongs
     * @param int $key key of the photo (identifier from the AlbumData.xml plist)
     * @param array $data assiciative array with raw photo properties
     * @see Library::getPhoto()
     */
    public function __construct(Library $library, $key, $data)
    {
        $this->library = $library;
        $this->key = $key;
        $this->data = $data;
    }

    /**
     * Get the key of this photo (a unique identifier within the AlbumData.xml plist)
     *
     * @return int key of this photo
     */
    public function getKey()
    {
        return $this->key;
    }

    /**
     * Get the caption of this photo (usually the original filename)
     *
     * @return string caption of this photo
     */
    public function getCaption()
    {
        return $this->data['Caption'];
    }

    /**
     * Get the description of this photo
     *
     * @return string description of this photo
     */
    public function getDescription()
    {
        return $this->data['Comment'];
    }

    /**
     * Get the path to the (original) image file on disk
     *
     * @return string path to the image file
     */
    public function getPath()
    {
        return $this->library->rewritePath($this->data['ImagePath']);
    }

    /**
     * Get the path to the iPhoto-generated thumbnail file on disk
     *
     * @return string path to the thumbnail file
     */
    public function getThumbnailPath()
    {
        return $this->library->rewritePath($this->data['ThumbPath']);
    }

    /**
     * Get the string representation of this photo
     *
     * @return string string representation of this photo (just it's caption)
     */
    public function __toString()
    {
        return $this->getCaption();
    }

    /**
     * Get the date of the photo.
     *
     * @param string $format The date formatting string.
     * @return string The photo date.
     */
    public function getDateTime($format = "Y-m-d H:i:s")
    {
        // iPhoto stores timestamps as offsets from 2001-01-01 00:00:00.
        // gmmktime( 0, 0, 0, 1, 1, 2001 ) = 978307200
        $IPHOTO_EPOCH = 978307200;

        return new \DateTime('@' . ($IPHOTO_EPOCH + intval($this->data['DateAsTimerInterval'])));
    }

    /**
     * Get the faces in the photo, along with their locations and sizes.
     *
     * @return array An array of Faces
     */
    public function getFaces()
    {
        $faces = array();

        if (!empty($this->data['Faces'])) {
            foreach ($this->data['Faces'] as $faceEntry) {
                $faces[] = new Face($this->library, $faceEntry['face key'], $faceEntry['rectangle']);
            }
        }

        return $faces;
    }
}
