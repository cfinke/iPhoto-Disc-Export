<?php
namespace PhotoLibrary;

/**
 * Class representing a face within an iPhoto photo
 *
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */
class Face
{
    /**
     * Link back to the library to which this face's photo belongs
     *
     * @var Library
     */
    protected $library;

    /**
     * Key of the face (a unique identifier within the Faces.db database)
     *
     * @var int
     */
    protected $key;

    /**
     * Array of the location coordinates: x, y, width, height.
     * x and y are the lower left corner of the face, as relative percentages from the lower left of the image.
     *
     * @var array
     */
    protected $coordinates;

    /**
     * The (string) name of this face.
     */
    protected $name;

    /**
     * Create new Photo by supplying the raw properties
     *
     * @param int $face_key The key of the face in Faces.db.
     * @param string $rectange iPhoto's string representation of the face coordinates.
     */
    public function __construct( Library $library, $faceKey, $rectangle )
    {
        $this->library = $library;

        $this->key = (int) $faceKey;

        preg_match("/^\{\{([0-9\.]+), ([0-9\.]+)\}, \{([0-9\.]+), ([0-9\.]+)\}\}$/", $rectangle, $rectangleCoords );
        $this->coordinates = array_slice($rectangleCoords, 1);
    }

    /**
     * Get the string representation of this face
     *
     * @return string string representation of this face
     */
    public function __toString()
    {
        return "Face #" . $this->key;
    }

    /**
     * Get the key of this face (a unique identifier within the Faces.db database)
     *
     * @return int key of this face
     */
    public function getKey()
    {
        return $this->key;
    }

    /**
     * Get the name of this face.
     *
     * @return string The name of this face.
     */
     public function getName()
     {
         if (is_null($this->name)) {
             $this->name = $this->library->getFaceName($this->key);
         }

         return $this->name;
     }

    /**
     * Get the coordinates of this face.
     *
     * @return array [ x-value of lower left corner, y-value of lower-left corner, width, height ]
     */
    public function getCoordinates()
    {
        return $this->coordinates;
    }
}
