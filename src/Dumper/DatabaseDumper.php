<?php

namespace Dogma\Tools\Dumper;

use cli\Table;
use Dogma\Tools\Colors as C;
use Dogma\Tools\Configurator;
use Dogma\Tools\SimplePdo;

/**
 * Dumps tables, views, functions, procedures, triggers and events from database
 */
final class DatabaseDumper
{

    const LINE_ENDINGS = [
        'LF' => "\n",
        'CRLF' => "\r\n",
        'CR' => "\r",
    ];

    const INDENTATION = [
        2 => '  ',
        4 => '    ',
        'tab' => "\t",
    ];

    const TYPES = [
        'table',
        'view',
        'function',
        'procedure',
        'trigger',
        'event',
    ];

    const STATUS_NEW     = ' (new)';
    const STATUS_REMOVED = ' (deleted)';
    const STATUS_CHANGED = ' (changed)';
    const STATUS_SAME    = '';

    /** @var string[][] */
    private $config;

    /** @var \Dogma\Tools\Dumper\MysqlAdapter */
    private $adapter;

    public function __construct(Configurator $config, MysqlAdapter $adapter)
    {
        $this->config = $config;
        $this->adapter = $adapter;
    }

    public function run()
    {
        $time = microtime(true);

        if ($this->config->query) {
            $this->output("Query: ". C::black(' ' . $this->config->query . ' ', C::YELLOW) . "\n\n");
            $this->query($this->config->query);
        } elseif ($this->config->write) {
            $this->output("Exporting database structure\n");
            $this->export();
        } else {
            $this->output("Running in read-only mode\n");
            $this->export();
        }

        $time = microtime(true) - $time;
        $this->output("\nFinished in: " . ($time > 1000 ? round($time / 1000, 3) . ' s' : round($time, 3) . ' ms'));
    }

    private function query(string $query) {
        try {
            $result = $this->adapter->query($query);
        } catch (\Throwable $e) {
            $this->output(C::white($e->getMessage(), C::RED));
            return;
        }
        $table = new Table();
        foreach ($result as $i => $row) {
            if ($i === 0) {
                $headers = [];
                foreach ($row as $column => $value) {
                    $headers[$column] = $column;
                }
                $table->setHeaders($headers);
            }
            $table->addRow(array_map(function ($value) {
                return trim($value);
            }, $row));
        }
        @$table->display();
    }

    private function export()
    {
        umask(0002);
        $this->cleanOutputDirectory();

        $databases = $this->adapter->getDatabaseList();
        foreach ($this->config->databases as $database) {
            $this->output(C::lyellow("\ndatabase: " . C::yellow($database) . "\n"));
            if (!in_array($database, $databases)) {
                $this->output(C::lred("  not found. skipped!\n"));
                continue;
            }
            $this->dumpDatabase($database);
        }
    }

    private function dumpDatabase(string $database)
    {
        $this->adapter->use($database);

        $skip = $this->config->skip ?: [];
        foreach (self::TYPES as $type) {
            if (in_array($type, $skip)) {
                continue;
            }

            $method = sprintf('get%sList', ucfirst($type));
            $newItems = call_user_func([$this->adapter, $method], $database);
            $oldItems = $this->scanInputTypeDirectory($database, $type);
            if (!$newItems && !$oldItems) {
                continue;
            }
            if ($newItems) {
                $this->createOutputTypeDirectory($database, $type);
            }

            $allItems = array_unique(array_merge($oldItems, $newItems));
            sort($allItems);

            $scannedItems = [];
            foreach ($allItems as $i => $item) {
                if (in_array($item, $newItems)) {
                    $method = sprintf('dump%sStructure', ucfirst($type));
                    $sql = "\n" . call_user_func([$this->adapter, $method], $item);

                    if ($type === 'table' && $this->config->removeCharsets) {
                        $sql = $this->adapter->removeCharsets($sql, $this->config->removeCharsets);
                    }
                    if ($type === 'table' && $this->config->removeCollations) {
                        $sql = $this->adapter->removeCollations($sql, $this->config->removeCollations);
                    }
                    if ($type === 'view' && $this->config->formatViews) {
                        $sql = $this->adapter->formatView($sql);
                    }
                    if ($type === 'table' && !empty($this->config->data[$database][$item])) {
                        list($data, $count) = $this->adapter->dumpTableData($item);
                        $sql .= "\n\n" . $data;
                        $scannedItems[$i] = C::yellow($item . "($count)");
                    }

                    $result = $this->process($database, $type, $item, $sql . "\n");
                } else {
                    $result = C::lred(self::STATUS_REMOVED);
                    $item = C::gray($item);
                }
                $scannedItems[$i] = $item . $result;
            }
            $this->output(C::lyellow(sprintf("  %ss (%d):\n    ", $type, count($newItems))));
            $this->output(implode(
                (!$this->config->short ? "\n    " : ", "),
                array_map([C::class, 'lgray'], $scannedItems)
            ) . "\n");
        }
    }

    private function process(string $database, string $type, string $item, string $sql)
    {
        $sql = $this->normalizeOutput($sql, $this->config->lineEndings, $this->config->indentation);

        $message = '';
        $previousSql = $this->readInputFile($database, $type, $item);
        if ($previousSql === null) {
            $message .= C::lgreen(self::STATUS_NEW);
        } else {
            $previousNormalizedSql = $this->normalizeOutput($previousSql, $this->config->lineEndings, $this->config->indentation);
            if ($previousNormalizedSql !== $sql) {
                $message .= C::yellow(self::STATUS_CHANGED);
            } else {
                $message .= C::gray(self::STATUS_SAME);
            }
        }

        $this->writeOutputFile($database, $type, $item, $sql);

        return $message;
    }

    private function normalizeOutput(string $sql, string $lineEnding, string $indent)
    {
        if ($indent === 'tab') {
            $indent = "\t";
        }
        $sql = str_replace("\n", self::LINE_ENDINGS[$lineEnding], str_replace("\r", "\n", str_replace("\r\n", "\n", $sql)));
        $sql = str_replace("\t", $indent, str_replace('  ', "\t", $sql));
        return $sql;
    }

    private function cleanOutputDirectory(string $path = null)
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

    private function createOutputTypeDirectory(string $database, string $type)
    {
        if ($this->config->write && !$this->config->singleFile && !$this->config->filePerDatabase) {
            $dir = sprintf('%s/%s/%ss', $this->config->outputDir, $database, $type);
            mkdir($dir, 0775, true);
        }
    }

    private function scanInputTypeDirectory(string $database, string $type): array
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
    private function readInputFile(string $database, string $type, string $item)
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

    private function writeOutputFile(string $database, string $type, string $item, string $data)
    {
        static $handlers = [];

        if (!$this->config->write) {
            return;
        }

        if ($this->config->singleFile) {
            if (!$handlers) {
                $file = sprintf('%s/export.sql', $this->config->outputDir);
                $handlers = $handler = fopen($file, 'w');
            } else {
                $handler = $handlers;
            }
        } elseif ($this->config->filePerDatabase) {
            $file = sprintf('%s/%s.sql', $this->config->outputDir, $database);
            if (!isset($handlers[$file])) {
                $handlers[$file] = $handler = fopen($file, 'w');
            } else {
                $handler = $handlers[$file];
            }
        } else {
            $file = sprintf('%s/%s/%ss/%s.sql', $this->config->outputDir, $database, $type, $item);
            $handler = fopen($file, 'w');
        }

        if ($handler === false) {
            die(sprintf('Error: Cannot write file %s.', $file));
        }

        fwrite($handler, $data);
    }

    /**
     * @param string $string
     */
    private function output(string $string)
    {
        echo $string;
    }

}
