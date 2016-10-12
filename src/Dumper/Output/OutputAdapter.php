<?php

namespace Dogma\Tools\Dumper\Output;

interface OutputAdapter
{

    public function init(\Closure $initCallback, \Closure $databaseInitCallback);

    public function write(string $data, string $database = null, string $type = null, string $item = null);

}
