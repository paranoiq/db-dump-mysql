<?php declare(strict_types = 1);

namespace Dogma\Tools\Dumper;

use Dogma\Tools\Configurator;

class IoAdapter
{

    /** @var \Dogma\Tools\Configurator */
    private $config;

    /** @var \Closure */
    private $fileInit;

    /** @var \Closure(string) */
    private $databaseInit;

    /** @var string */
    private $lastDatabase;

    public function __construct(
        Configurator $config,
        \Closure $fileInit,
        \Closure $databaseInit
    ) {
        $this->config = $config;
        $this->fileInit = $fileInit;
        $this->databaseInit = $databaseInit;
    }

    public function cleanOutputDirectory(?string $path = null): void
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

    public function createOutputTypeDirectory(string $database, string $type): void
    {
        if ($this->config->write && !$this->config->singleFile && !$this->config->filePerDatabase) {
            $dir = sprintf('%s/%s/%s', $this->config->outputDir, $database, $type);
            umask(0002);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
        }
    }

    /**
     * @param string $database
     * @param string $type
     * @return string[]
     */
    public function scanInputTypeDirectory(string $database, string $type): array
    {
        $dir = sprintf('%s/%s/%s', $this->config->inputDir, $database, $type);

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

    public function write(string $data, ?string $database = null, ?string $type = null, ?string $item = null): void
    {
        static $handlers = [];

        if (!$this->config->write) {
            return;
        }

        $file = null;
        if ($this->config->singleFile) {
            if (!$handlers) {
                $file = sprintf('%s/export.sql', $this->config->outputDir);
                umask(0002);
                $handlers = $handler = fopen($file, 'w');
                ($this->fileInit)();
                if ($this->lastDatabase !== $database) {
                    ($this->databaseInit)($database);
                    $this->lastDatabase = $database;
                }
            } else {
                $handler = $handlers;
            }
        } elseif ($this->config->filePerDatabase) {
            $file = sprintf('%s/%s.sql', $this->config->outputDir, $database);
            if (!isset($handlers[$file])) {
                umask(0002);
                $handlers[$file] = $handler = fopen($file, 'w');
                ($this->fileInit)($database);
                ($this->databaseInit)($database);
            } else {
                $handler = $handlers[$file];
            }
        } else {
            $file = sprintf('%s/%s/%s/%s.sql', $this->config->outputDir, $database, $type, $item);
            umask(0002);
            if ($type === 'data') {
                $handler = fopen($file, 'a');
            } else {
                $handler = fopen($file, 'w');
            }
        }

        if ($handler === false) {
            die(sprintf('Error: Cannot write file %s.', $file));
        }

        fwrite($handler, $data);
    }

}
