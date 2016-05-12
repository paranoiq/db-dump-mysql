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
        if ($this->config->write) {
            $this->cleanDirectory(sprintf('%s/%s', $this->config->outputDir, $database));
        }
        $skip = $this->config->skip ?: [];
        foreach (self::TYPES as $type) {
            if (in_array($type . 's', $skip)) {
                continue;
            }

            $inputTypeDir = sprintf('%s/%s/%ss', $this->config->inputDir, $database, $type);
            $outputTypeDir = sprintf('%s/%s/%ss', $this->config->outputDir, $database, $type);

            $method = sprintf('get%sList', ucfirst($type));
            $newItems = call_user_func([$this->adapter, $method], $database);
            $oldItems = $this->scanDirectory($inputTypeDir);
            if (!$newItems && !$oldItems) {
                continue;
            }

            if ($this->config->write && $newItems) {
                mkdir($outputTypeDir, 0775, true);
            }

            $allItems = array_unique(array_merge($oldItems, $newItems));
            sort($allItems);

            $scannedItems = [];
            foreach ($allItems as $i => $item) {
                if (in_array($item, $newItems)) {
                    $inputFile = sprintf('%s/%s.sql', $inputTypeDir, $item);
                    $outputFile = sprintf('%s/%s.sql', $outputTypeDir, $item);

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
                        //$result = $this->process(str_replace('.sql', '.data.sql', $file), $data . "\n");
                        $scannedItems[$i] = C::yellow($item . "($count)");
                    }

                    $result = $this->process($sql . "\n", $inputFile, $outputFile);
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

    /**
     * Process result
     */
    private function process(string $sql, string $inputFile, string $outputFile): string
    {
        $sql = $this->normalizeOutput($sql, $this->config->lineEndings, $this->config->indentation);

        $result = '';
        if (!file_exists($inputFile)) {
            $result .= C::lgreen(self::STATUS_NEW);
        } else {
            $old = $this->normalizeOutput(file_get_contents($inputFile), $this->config->lineEndings, $this->config->indentation);
            if ($old !== $sql) {
                $result .= C::yellow(self::STATUS_CHANGED);
            } else {
                $result .= C::gray(self::STATUS_SAME);
            }
        }

        if ($this->config->write) {
            $res = file_put_contents($outputFile, $sql);
            if ($res === false) {
                die(sprintf('Error: Cannot write file %s.', $outputFile));
            }
        }

        return $result;
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

    /**
     * @param string $string
     */
    private function output(string $string)
    {
        echo $string;
    }

    private function cleanDirectory(string $path)
    {
        foreach (glob($path . '/*') as $path) {
            if (is_dir($path)) {
                $this->cleanDirectory($path);
                rmdir($path);
            } elseif (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * @param string $path
     * @return string[]
     */
    private function scanDirectory(string $path): array
    {
        return array_map(function (string $path) {
            return basename($path, '.sql');
        }, glob($path . '/*'));
    }

}
