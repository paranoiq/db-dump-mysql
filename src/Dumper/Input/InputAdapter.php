<?php declare(strict_types = 1);

namespace Dogma\Tools\Dumper\Input;

interface InputAdapter
{

    /**
     * @param string $database
     * @param string $type
     * @return string[]
     */
    public function scanItems(string $database, string $type): array;

    public function read(string $database, string $type, string $item): ?string;

}
