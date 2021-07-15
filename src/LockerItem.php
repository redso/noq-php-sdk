<?php

namespace NoQ\RoomQ;

class LockerItem
{
    public $key;
    public $value;
    public $limit;
    public $kvLimit;

    /**
     * LockerItem constructor.
     * @param string $key key
     * @param string $value value
     * @param integer $limit max number of values can be stored in this key
     * @param integer $kvLimit max number of this key-value pair can be stored in all the lockers in this room
     */
    public function __construct($key, $value, $limit, $kvLimit)
    {
        $this->key = $key;
        $this->value = $value;
        $this->limit = $limit;
        $this->kvLimit = $kvLimit;
    }
}
