<?php

namespace Dogma\Tools\Dumper\Input;

interface InputAdapter
{

    /**
     * @param string $database
     * @param string $type
     * @return string[]
     */
    public function scanItems(string $database, string $type): array;

    /**
     * @param string $database
     * @param string $type
     * @param string $item
     * @return string|null
     */
    public function read(string $database, string $type, string $item);

}
