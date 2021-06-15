<?php

namespace NoQ\RoomQ;

class ValidationResult
{
    private $redirectURL;

    public function __construct($redirectURL)
    {
        $this->redirectURL = $redirectURL;
    }

    public function needRedirect()
    {
        return !is_null($this->redirectURL);
    }

    public function getRedirectURL()
    {
        return $this->redirectURL;
    }
}
