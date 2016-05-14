<?php declare(strict_types = 1);

namespace Dogma\Tools\Dumper;

use Dogma\Tools\Configurator;

class IoAdapter
{

    /** @var \Dogma\Tools\Configurator */
    private $config;

    public function __construct(Configurator $config)
    {
        $this->config = $config;
    }

    public function cleanOutputDirectory(string $path = null)
    {
        if (!$this->config->write) {
            return;
        }
        if ($path === null) {
            $path = $this->config->outputDir;
        }
        foreach (glob($path . '/*') as $path) {
            if (is_dir($path)) {
                $this->cleanOutputDirectory($path);
                rmdir($path);
            } elseif (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function createOutputTypeDirectory(string $database, string $type)
    {
        if ($this->config->write && !$this->config->singleFile && !$this->config->filePerDatabase) {
            $dir = sprintf('%s/%s/%ss', $this->config->outputDir, $database, $type);
            umask(0002);
            mkdir($dir, 0775, true);
        }
    }

    public function scanInputTypeDirectory(string $database, string $type): array
    {
        $dir = sprintf('%s/%s/%ss', $this->config->inputDir, $database, $type);

        return array_map(function (string $path) {
            return basename($path, '.sql');
        }, glob($dir . '/*'));
    }

    /**
     * @param string $database
     * @param string $type
     * @param string $item
     * @return string|null
     */
    public function readInputFile(string $database, string $type, string $item)
    {
        $file = sprintf('%s/%s/%ss/%s.sql', $this->config->inputDir, $database, $type, $item);

        if (!file_exists($file)) {
            return null;
        }

        $result = file_get_contents($file);
        if ($result === false) {
            die(sprintf('Error: Cannot read file %s.', $file));
        }

        return $result;
    }

    public function writeOutputFile(string $database, string $type, string $item, string $data)
    {
        static $handlers = [];

        if (!$this->config->write) {
            return;
        }

        if ($this->config->singleFile) {
            if (!$handlers) {
                $file = sprintf('%s/export.sql', $this->config->outputDir);
                umask(0002);
                $handlers = $handler = fopen($file, 'w');
            } else {
                $handler = $handlers;
            }
        } elseif ($this->config->filePerDatabase) {
            $file = sprintf('%s/%s.sql', $this->config->outputDir, $database);
            if (!isset($handlers[$file])) {
                umask(0002);
                $handlers[$file] = $handler = fopen($file, 'w');
            } else {
                $handler = $handlers[$file];
            }
        } else {
            $file = sprintf('%s/%s/%ss/%s.sql', $this->config->outputDir, $database, $type, $item);
            umask(0002);
            $handler = fopen($file, 'w');
        }

        if ($handler === false) {
            die(sprintf('Error: Cannot write file %s.', $file));
        }

        fwrite($handler, $data);
    }

}
