<?php declare(strict_types = 1);

namespace Dogma\Tools\Dumper\Output;

interface OutputAdapter
{

    public function init(\Closure $initCallback, \Closure $databaseInitCallback): void;

    public function write(string $data, ?string $database = null, ?string $type = null, ?string $item = null): void;

}
