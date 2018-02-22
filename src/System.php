<?php declare(strict_types = 1);

namespace Dogma\Tools\Dumper;

class System
{

    public static function isWindows(): bool
    {
        return strstr(strtolower(PHP_OS), 'win') !== null;
    }

}
