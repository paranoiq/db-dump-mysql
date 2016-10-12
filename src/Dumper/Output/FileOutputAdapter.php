<?php declare(strict_types = 1);

namespace Dogma\Tools\Dumper\Output;

use Dogma\Tools\Configurator;

class FileOutputAdapter implements \Dogma\Tools\Dumper\Output\OutputAdapter
{

    /** @var \Dogma\Tools\Configurator */
    private $config;

    /** @var \Closure */
    private $fileInit;

    /** @var \Closure(string) */
    private $databaseInit;

    /** @var string */
    private $lastDatabase;

    public function __construct(Configurator $config)
    {
        $this->config = $config;
    }

    public function init(\Closure $fileInit, \Closure $databaseInit): void
    {
        $this->fileInit = $fileInit;
        $this->databaseInit = $databaseInit;

        $this->initPath($this->config->outputDir);
    }

    private function initPath(string $path): void
    {
        if (!$this->config->write) {
            return;
        }
        foreach (glob($path . '/*') as $path) {
            if (is_dir($path)) {
                $this->initPath($path);
                rmdir($path);
            } elseif (is_file($path)) {
                unlink($path);
            }
        }
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
            $dir = sprintf('%s/%s/%s', $this->config->outputDir, $database, $type);
            umask(0002);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }

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
