<?php

namespace Dogma\Tools\Dumper;

class System
{

    public static function isWindows(): bool
    {
        return strstr(strtolower(PHP_OS), 'win');
    }

}
