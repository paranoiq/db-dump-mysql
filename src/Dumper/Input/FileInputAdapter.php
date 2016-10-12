<?php declare(strict_types = 1);

namespace Dogma\Tools\Dumper\Input;

use Dogma\Tools\Configurator;

class FileInputAdapter implements \Dogma\Tools\Dumper\Input\InputAdapter
{

    /** @var \Dogma\Tools\Configurator */
    private $config;

    public function __construct(Configurator $config)
    {
        $this->config = $config;
    }

    /**
     * @param string $database
     * @param string $type
     * @return string[]
     */
    public function scanItems(string $database, string $type): array
    {
        $dir = sprintf('%s/%s/%s', $this->config->inputDir, $database, $type);

        return array_map(function (string $path) {
            return basename($path, '.sql');
        }, glob($dir . '/*'));
    }

    public function read(string $database, string $type, string $item): ?string
    {
        $file = sprintf('%s/%s/%s/%s.sql', $this->config->inputDir, $database, $type, $item);

        if (!file_exists($file)) {
            return null;
        }

        $result = file_get_contents($file);
        if ($result === false) {
            die(sprintf('Error: Cannot read file %s.', $file));
        }

        return $result;
    }

}
