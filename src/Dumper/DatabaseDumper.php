<?php declare(strict_types = 1);

namespace Dogma\Tools\Dumper;

use Dogma\Tools\Colors as C;
use Dogma\Tools\Configurator;
use Dogma\Tools\Console;
use Dogma\Tools\SimplePdoResult;

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
        'tables',
        'views',
        'functions',
        'procedures',
        'triggers',
        'events',
    ];

    const STATUS_NEW     = ' (new)';
    const STATUS_REMOVED = ' (deleted)';
    const STATUS_CHANGED = ' (changed)';
    const STATUS_SAME    = '';

    /** @var string[][] */
    private $config;

    /** @var \Dogma\Tools\Dumper\IoAdapter */
    private $io;

    /** @var \Dogma\Tools\Dumper\MysqlAdapter */
    private $db;

    /** @var \Dogma\Tools\Console */
    private $console;

    /** @var \Dogma\Tools\Dumper\DataDumper */
    private $dataDumper;

    public function __construct(Configurator $config, MysqlAdapter $db, Console $console)
    {
        $this->config = $config;
        $this->db = $db;
        $this->console = $console;

        $fileInit = function () {
            $this->io->write("SET NAMES 'utf8';\n");
            $this->io->write("SET sql_mode= '';\n");
            $this->io->write('SET foreign_key_checks=OFF;');
        };
        $databaseInit = function (string $database) {
            $this->io->write("USE " . $this->db->quoteName($database) . ";\n");
        };

        $this->io = new IoAdapter($config, $fileInit, $databaseInit);
        $this->dataDumper = new DataDumper($db, $this->io, $console);
    }

    public function run()
    {
        $time = microtime(true);

        $this->console->write(
            C::gray('Server: '), $this->config->host, ':', $this->config->port,
            C::gray(', user: '), $this->config->user,
            C::gray(', database: '), $this->config->query ? $this->config->databases[0] : implode(', ', $this->config->databases)
        )->ln(2);

        if ($this->config->query) {
            $this->query($this->config->query);
        } else {
            $this->export();
        }

        $time = microtime(true) - $time;
        $this->console->ln()->write(C::gray('Finished in: ') . $this->formatTime($time));
    }

    private function query(string $query)
    {
        $this->console->write(C::gray('Query: '), C::black(' ' . $this->config->query . ' ', C::YELLOW))->ln(2);

        $calcFoundRows = false;
        if (substr($query, -1) === '+') {
            $query = preg_replace('/^(select) /i', '\\1 SQL_CALC_FOUND_ROWS ', substr($query, 0, -1));
            $calcFoundRows = true;
        }

        try {
            if ($this->config->databases) {
                $this->db->query('USE ' . $this->config->databases[0]);
            }
            $time = microtime(true);
            $result = $this->db->query($query);
        } catch (\Throwable $e) {
            $this->console->ln(2)->write(C::white($e->getMessage(), C::RED));
            return;
        }

        $time = microtime(true) - $time;
        $this->console->write(C::gray("Time: ") . $this->formatTime($time) . C::gray(', rows: ') . $result->rowCount());
        if ($calcFoundRows) {
            $totalRows = $this->db->query('SELECT FOUND_ROWS() AS rows')->fetchColumn('rows');
            $this->console->write(C::gray(', total rows: ') . $totalRows);
        }

        $this->console->ln();
        if ($result->rowCount() > 0) {
            $this->displayResult($result);
        }
    }

    private function formatTime(float $time): string
    {
        return $time > 1 ? round($time, 3) . ' s' : round($time * 1000, 3) . ' ms';
    }

    private function displayResult(SimplePdoResult $result)
    {
        $columns = Console::getTerminalWidth();

        $formatter = new TableFormatter($columns);
        $formatter->render($result);
    }

    private function export()
    {
        if ($this->config->write) {
            $this->console->writeLn('Exporting database structure');
        } else {
            $this->console->writeLn('Running in read-only mode');
        }

        $this->io->cleanOutputDirectory();

        if ($this->config->singleFile) {
            $this->io->write('SET foreign_key_checks=OFF;');
        }
        foreach ($this->getDatabases() as $database) {
            $this->console->ln()->writeLn(C::lyellow('database: '), $database);
            $this->dumpStructure($database);
        }

        if ($this->config->write && $this->config->data) {
            list($exportDependencies, $dep) = $this->dataDumper->readDataConfig($this->config->data);
            if ($exportDependencies) {
                $this->console->ln()->writeLn('Scanning for dependencies');
                list($fk, $primary) = $this->dataDumper->scanStructure($this->config->databases);
                $this->console->writeLn('  ' . $fk . ' foreign keys found');
                if ($primary) {
                    $this->console->writeLn('  ' . $primary . ' primary key dependencies found');
                }
                if ($dep) {
                    $this->console->writeLn('  ' . $dep . ' dependencies configured');
                }
            }
            $this->console->ln()->writeLn('Exporting database data')->ln();
            $this->dataDumper->dumpData();
        }
    }

    private function getDatabases(bool $alert = true)
    {
        $config = $this->config->databases;
        $real = $this->db->getDatabases();
        $missing = array_diff($config, $real);
        if ($missing && $alert) {
            foreach ($missing as $database) {
                $this->console->write(C::lred(sprintf('Database `%s` not found. Skipped!', $database)))->ln();
            }
        }

        return array_intersect($config, $real);
    }

    private function dumpStructure(string $database)
    {
        $this->db->use($database);

        $skip = $this->config->skip ?: [];
        foreach (self::TYPES as $type) {
            if (in_array($type, $skip)) {
                continue;
            }

            $method = sprintf('get%s', ucfirst($type));
            $newItems = call_user_func([$this->db, $method], $database);
            $oldItems = $this->io->scanInputTypeDirectory($database, $type);
            if (!$newItems && !$oldItems) {
                continue;
            }
            if ($newItems) {
                $this->io->createOutputTypeDirectory($database, $type);
            }

            $allItems = array_unique(array_merge($oldItems, $newItems));
            sort($allItems);

            $scannedItems = [];
            foreach ($allItems as $i => $item) {
                if (in_array($item, $newItems)) {
                    $method = sprintf('dump%sStructure', ucfirst(rtrim($type, 's')));
                    $sql = "\n" . call_user_func([$this->db, $method], $item);

                    if ($type === 'table') {
                        if ($this->config->removeCharsets) {
                            $sql = $this->db->removeCharsets($sql, $this->config->removeCharsets);
                        }
                        if ($this->config->removeCollations) {
                            $sql = $this->db->removeCollations($sql, $this->config->removeCollations);
                        }
                        if ($this->config->removeSizes === 'all') {
                            $sql = $this->db->removeSizes($sql, true);
                        } elseif ($this->config->removeSizes === 'default') {
                            $sql = $this->db->removeSizes($sql, false);
                        }
                    }
                    if ($type === 'view' && $this->config->prettyFormat) {
                        $sql = $this->db->formatView($sql);
                    }
                    if ($type === 'event' && $this->config->prettyFormat) {
                        $sql = $this->db->formatEvent($sql);
                    }

                    $result = $this->process($database, $type, $item, $sql . "\n");
                } else {
                    $result = C::lred(self::STATUS_REMOVED);
                    $item = C::gray($item);
                }
                $scannedItems[$i] = $item . $result;
            }
            $this->console->write(C::lyellow(sprintf("  %s (%d):\n    ", $type, count($newItems))));
            $this->console->write(implode(
                (!$this->config->short ? "\n    " : ", "),
                array_map([C::class, 'lgray'], $scannedItems)
            ))->ln();
        }
    }

    public function process(string $database, string $type, string $item, string $sql)
    {
        $sql = $this->normalizeOutput($sql, $this->config->lineEndings, $this->config->indentation);

        $message = '';
        $previousSql = $this->io->read($database, $type, $item);
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

        $this->io->write($sql, $database, $type, $item);

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

}
