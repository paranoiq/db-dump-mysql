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

    /** @var \Dogma\Tools\SimplePdo */
    private $db;

    /** @var string[][] */
    private $config;

    public function __construct(SimplePdo $db, Configurator $config)
    {
        $this->db = $db;
        $this->config = $config;
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
            $result = $this->db->query($query);
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
        $dbs = $this->getDatabaseList();
        foreach ($this->config->databases as $database) {
            $this->output(C::lyellow("\ndatabase: " . C::yellow($database) . "\n"));
            if (!in_array($database, $dbs)) {
                $this->output(C::lred("  not found. skipped!\n"));
                continue;
            }
            $this->dumpDatabase($database);
        }
    }

    private function dumpDatabase(string $database)
    {
        $this->db->exec('USE %', $database);
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

            $method = sprintf('get%sList', $type);
            $newItems = call_user_func([$this, $method], $database);
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
                    $method = sprintf('get%sDump', $type);
                    $sql = "\n" . call_user_func([$this, $method], $item);
                    if ($type === 'table' && !empty($this->config->data[$database][$item])) {
                        list($data, $count) = $this->getDataDump($item);
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
     * Return list of databases
     *
     * @return string[]
     */
    public function getDatabaseList(): array
    {
        $list = $this->db->query('SHOW DATABASES')->fetchAll();

        foreach ($list as &$database) {
            $database = $database['Database'];
        }

        return $list;
    }

    /**
     * Returns list of database tables
     *
     * @param string $database
     * @return string[]
     */
    public function getTableList(string $database): array
    {
        $list = $this->db->query(
            'SELECT TABLE_NAME FROM information_schema.TABLES
            WHERE TABLE_TYPE = \'BASE TABLE\'
                AND TABLE_SCHEMA = ?', $database)
            ->fetchColumnAll('TABLE_NAME');

        // fallback for system views (information_schema etc.)
        if (count($list) === 0) {
            $list = $this->db->query('SHOW TABLES FROM %', $database)->fetchColumnAll(0);
        }

        return $list;
    }

    /**
     * Returns list of database views
     *
     * @param string $database
     * @return string[]
     */
    public function getViewList(string $database): array
    {
        $list = $this->db->query(
            'SELECT TABLE_NAME
            FROM information_schema.VIEWS
            WHERE TABLE_SCHEMA = ?', $database
        )->fetchAll();

        foreach ($list as &$view) {
            $view = $view['TABLE_NAME'];
        }

        return $list;
    }

    /**
     * Returns list of database functions
     *
     * @param string $database
     * @return string[]
     */
    public function getFunctionList(string $database): array
    {
        $list = $this->db->query(
            'SELECT SPECIFIC_NAME
            FROM information_schema.ROUTINES
            WHERE ROUTINE_TYPE = \'FUNCTION\'
                AND ROUTINE_SCHEMA = ?', $database
        )->fetchAll();

        foreach ($list as &$function) {
            $function = $function['SPECIFIC_NAME'];
        }

        return $list;
    }

    /**
     * Returns list of database procedures
     *
     * @param string $database
     * @return string[]
     */
    public function getProcedureList(string $database): array
    {
        $list = $this->db->query(
            'SELECT ROUTINE_NAME
            FROM information_schema.ROUTINES
            WHERE ROUTINE_TYPE = \'PROCEDURE\'
                AND ROUTINE_SCHEMA = ?', $database
        )->fetchAll();

        foreach ($list as &$procedure) {
            $procedure = $procedure['ROUTINE_NAME'];
        }

        return $list;
    }

    /**
     * Returns list of database triggers
     *
     * @param string $database
     * @return string[]
     */
    public function getTriggerList(string $database): array
    {
        $list = $this->db->query(
            'SELECT TRIGGER_NAME
            FROM information_schema.TRIGGERS
            WHERE TRIGGER_SCHEMA = ?', $database
        )->fetchAll();

        foreach ($list as &$trigger) {
            $trigger = $trigger['TRIGGER_NAME'];
        }

        return $list;
    }

    /**
     * Returns list of database events
     *
     * @param string $database
     * @return string
     */
    public function getEventList(string $database): array
    {
        $list = $this->db->query(
            'SELECT EVENT_NAME
            FROM information_schema.EVENTS
            WHERE EVENT_SCHEMA = ?', $database
        )->fetchAll();

        foreach ($list as &$event) {
            $event = $event['EVENT_NAME'];
        }

        return $list;
    }

    /**
     * Creates SQL CREATE TABLE statement
     */
    private function getTableDump(string $tableName): string
    {
        $data = $this->db->query('SHOW CREATE TABLE %', $tableName)->fetch();

        $data = preg_replace('~AUTO_INCREMENT=\\d+ ~i', '', $data['Create Table'], 1);

        if ($this->config->removeCharsets) {
            foreach ($this->config->removeCharsets as $charset) {
                $data = preg_replace('/ DEFAULT CHARSET=' . $charset . '/', '', $data);
            }
        }
        if ($this->config->removeCollations) {
            foreach ($this->config->removeCollations as $collation) {
                $data = preg_replace('/ COLLATE[= ]?' . $collation . '/', '', $data);
            }
        }

        return $data . ';';
    }

    /**
     * Creates SQL INSERT statement for table data
     */
    private function getDataDump(string $tableName): string
    {
        $sql = 'INSERT INTO ' . $this->db->quoteName($tableName) . ' VALUES\n';
        $result = $this->db->query('SELECT * FROM %', $tableName);

        $r = 1;
        foreach ($result as $row) {
            $sql .= '(';
            $v = 1;
            foreach ($row as $value) {
                $sql .= is_null($value) ? 'NULL' : is_numeric($value) ? $value : $this->db->quote($value);
                if ($v < $row->count()) {
                    $sql .= ', ';
                }
                ++$v;
            }
            $sql .= ')';
            if ($r < $result->rowCount()) {
                $sql .= ",\n";
            }
            ++$r;
        }
        $sql .= ';';

        return [$sql, $result->rowCount()];
    }

    /**
     * Creates pretty formatted SQL CREATE VIEW statement
     */
    private function getViewDump(string $viewName): string
    {
        $data = $this->db->query('SHOW CREATE VIEW %', $viewName)->fetch();

        $view = $data['Create View'];
        if ($this->config->formatViews) {
            $view = ViewFormatter::format($view);
        }

        return $view . ';';
    }

    /**
     * @param string
     * @return string SQL CREATE FUNCTION statement
     */
    private function getFunctionDump($functionName)
    {
        $data = $this->db->query('SHOW CREATE FUNCTION %', $functionName)->fetch();

        return $data['Create Function'] . ';';
    }
    
    /**
     * Creates SQL CREATE PROCEDURE statement
     */
    private function getProcedureDump(string $procedureName): string
    {
        $data = $this->db->query('SHOW CREATE PROCEDURE %', $procedureName)->fetch();

        return $data['Create Procedure'] . ';';
    }

    /**
     * Creates SQL CREATE TRIGGER statement
     */
    private function getTriggerDump(string $triggerName): string
    {
        $data = $this->db->query('SHOW CREATE TRIGGER %', $triggerName)->fetch();

        return $data['SQL Original Statement'] . ';';
    }

    /**
     * Creates SQL CREATE EVENT statement
     */
    private function getEventDump(string $eventName): string
    {
        $data = $this->db->query('SHOW CREATE EVENT %', $eventName)->fetchColumn();

        return $data['Create Event'] . ';';
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
