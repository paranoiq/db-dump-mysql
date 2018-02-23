<?php declare(strict_types = 1);

namespace Dogma\Tools\Dumper;

use Dogma\Tools\Colors as C;
use Dogma\Tools\Configurator;
use Dogma\Tools\Console;
use Dogma\Tools\Dumper\Input\FileInputAdapter;
use Dogma\Tools\Dumper\Output\FileOutputAdapter;
use Dogma\Tools\SimplePdoResult;
use Dogma\Tools\TableFormatter;

/**
 * Dumps tables, views, functions, procedures, triggers and events from database
 */
final class DatabaseDumper
{

    public const LINE_ENDINGS = [
        'LF' => "\n",
        'CRLF' => "\r\n",
        'CR' => "\r",
    ];

    public const INDENTATION = [
        2 => '  ',
        4 => '    ',
        'tab' => "\t",
    ];

    public const TYPES = [
        'tables',
        'views',
        'functions',
        'procedures',
        'triggers',
        'events',
    ];

    public const STATUS_NEW     = ' (new)';
    public const STATUS_REMOVED = ' (deleted)';
    public const STATUS_CHANGED = ' (changed)';
    public const STATUS_SAME    = '';

    /** @var \Dogma\Tools\Configurator */
    private $config;

    /** @var \Dogma\Tools\Dumper\Input\InputAdapter */
    private $input;

    /** @var \Dogma\Tools\Dumper\Output\OutputAdapter */
    private $output;

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

        $this->input = new FileInputAdapter($config);
        $this->output = new FileOutputAdapter($config);
        $this->dataDumper = new DataDumper($db, $this->output, $console);
    }

    public function run(): void
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

    private function query(string $query): void
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
        $this->console->write(C::gray('Time: ') . $this->formatTime($time) . C::gray(', rows: ') . $result->rowCount());
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

    private function displayResult(SimplePdoResult $result): void
    {
        $columns = Console::getTerminalWidth();

        $formatter = new TableFormatter($columns);
        $formatter->render($result);
    }

    private function export(): void
    {
        if ($this->config->write) {
            $this->console->writeLn('Exporting database structure');
        } else {
            $this->console->writeLn('Running in read-only mode');
        }

        $init = function (): void {
            $this->output->write("SET NAMES 'utf8';\n");
            $this->output->write("SET sql_mode= '';\n");
            $this->output->write("SET foreign_key_checks=OFF;\n");
        };
        $databaseInit = function (string $database): void {
            $this->output->write('USE ' . $this->db->quoteName($database) . ";\n");
        };
        $this->output->init($init, $databaseInit);

        foreach ($this->getDatabases() as $database) {
            $this->console->ln()->writeLn(C::lyellow('database: '), $database);
            $this->dumpStructure($database);
        }

        if ($this->config->write && $this->config->data) {
            [$exportDependencies, $dependencyCount] = $this->dataDumper->readDataConfig($this->config->data);
            if ($exportDependencies) {
                $this->console->ln()->writeLn('Scanning for dependencies');
                list($fk, $primary) = $this->dataDumper->scanStructure($this->config->databases);
                $this->console->writeLn('  ' . $fk . ' foreign keys found');
                if ($primary) {
                    $this->console->writeLn('  ' . $primary . ' primary key dependencies found');
                }
                if ($dependencyCount) {
                    $this->console->writeLn('  ' . $dependencyCount . ' dependencies configured');
                }
            }
            $this->console->ln()->writeLn('Exporting database data')->ln();
            $this->dataDumper->dumpData();
        }
    }

    /**
     * @param bool $alert
     * @return string[]
     */
    private function getDatabases(bool $alert = true): array
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

    private function dumpStructure(string $database): void
    {
        $this->db->use($database);

        $skip = $this->config->skip ?: [];
        foreach (self::TYPES as $type) {
            if (in_array($type, $skip)) {
                continue;
            }

            $method = sprintf('get%s', ucfirst($type));
            $newItems = call_user_func([$this->db, $method], $database);
            $oldItems = $this->input->scanItems($database, $type);
            if (!$newItems && !$oldItems) {
                continue;
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
                (!$this->config->short ? "\n    " : ', '),
                array_map([C::class, 'lgray'], $scannedItems)
            ))->ln();
        }
    }

    public function process(string $database, string $type, string $item, string $sql): string
    {
        $sql = $this->normalizeOutput($sql, $this->config->lineEndings, $this->config->indentation);

        $message = '';
        $previousSql = $this->input->read($database, $type, $item);
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

        $this->output->write($sql, $database, $type, $item);

        return $message;
    }

    private function normalizeOutput(string $sql, string $lineEnding, string $indent): string
    {
        if ($indent === 'tab') {
            $indent = "\t";
        }
        $sql = str_replace("\n", self::LINE_ENDINGS[$lineEnding], str_replace("\r", "\n", str_replace("\r\n", "\n", $sql)));
        $sql = str_replace("\t", $indent, str_replace('  ', "\t", $sql));

        return $sql;
    }

}
