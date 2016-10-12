<?php

namespace Dogma\Tools\Dumper;

use Dogma\Tools\Colors as C;
use Dogma\Tools\Console;
use Dogma\Tools\Dumper\Output\OutputAdapter;

class DataDumper
{

    const MODE_GREEDY = 'greedy';
    const MODE_DEPENDENCIES = 'dependencies';
    const MODE_CONSISTENT = 'consistent';
    const MODE_ISOLATED = 'isolated';
    const MODE_IGNORE = 'ignore';

    const SELECT_BATCH_SIZE = 1000;

    /** @var \Dogma\Tools\Dumper\MysqlAdapter */
    private $db;

    /** @var \Dogma\Tools\Dumper\DatabaseInfo */
    private $dbInfo;

    /** @var \Dogma\Tools\Dumper\Output\OutputAdapter */
    private $output;

    /** @var \Dogma\Tools\Console */
    private $console;

    /** @var string[][] $database => $table => [$mode, $selector] */
    private $selectors = [];

    /** @var string[][] $referencedDatabase => $referencedTable => [$tables] */
    private $dependencies = [];

    /** @var mixed[][][][] $mode => $database => $table => $key => $values */
    private $todo = [];

    /** @var mixed[][][][] $mode => $database => $table => $key => $values */
    private $done = [];

    public function __construct(MysqlAdapter $db, OutputAdapter $output, Console $console)
    {
        $this->db = $db;
        $this->dbInfo = new DatabaseInfo($db);
        $this->output = $output;
        $this->console = $console;
    }

    public function readDataConfig(array $dataConfig): array
    {
        $dependencies = false;
        $dep = 0;
        foreach ($dataConfig as $database => $config) {
            $dbTables = $this->db->getTables($database);

            foreach ($config as $tablePattern => $selector) {
                $mode = self::MODE_CONSISTENT;
                if (substr($tablePattern, -1) === '!') {
                    $tablePattern = substr($tablePattern, 0, -1);
                    $mode = self::MODE_DEPENDENCIES;
                    $dependencies = true;
                } elseif (substr($tablePattern, -1) === '+') {
                    $mode = self::MODE_GREEDY;
                    $tablePattern = substr($tablePattern, 0, -1);
                    $dependencies = true;
                } elseif (substr($tablePattern, -1) === '-') {
                    $mode = self::MODE_ISOLATED;
                    $tablePattern = substr($tablePattern, 0, -1);
                } else {
                    $dependencies = true;
                }

                if ($tablePattern[0] !== '/' && strstr($tablePattern, '*')) {
                    $tablePattern = '/^' . str_replace('*', '.*', $tablePattern) . '$/';
                }
                if (preg_match('#^/.*/$#', $tablePattern)) {
                    $tables = [];
                    foreach ($dbTables as $table) {
                        if (preg_match($tablePattern, $table)) {
                            $tables[] = $table;
                        }
                    }
                } else {
                    $tables = [$tablePattern];
                }

                foreach ($tables as $table) {
                    if ($mode === self::MODE_DEPENDENCIES) {
                        $this->dependencies[$database][$table] = $selector;
                        $dep += count($selector);
                    } else {
                        $this->selectors[$database][$table] = [$mode, $selector];
                    }
                }
            }
        }
        return [$dependencies, $dep];
    }

    public function scanStructure(array $databases): array
    {
        return $this->dbInfo->scanStructure($databases);
    }

    public function dumpData() {
        foreach ([self::MODE_ISOLATED, self::MODE_CONSISTENT, self::MODE_GREEDY] as $runMode) {
            foreach ($this->selectors as $database => $selectors) {
                foreach ($selectors as $table => list($mode, $selector)) {
                    if ($mode !== $runMode) {
                        continue;
                    }
                    if (is_array($selector)) {
                        foreach ($selector as $value) {
                            $key = is_array($value) ? implode('|', $value) : $value;
                            $this->todo[$mode][$database][$table][$key] = $value;
                        }
                    } elseif ($selector === true) {
                        $this->enqueueByQuery($database, $table, '', $mode);
                    } elseif (is_string($selector)) {
                        $this->enqueueByQuery($database, $table, $selector, $mode);
                    }
                }
            }
        }

        $total = 0;
        foreach ([self::MODE_GREEDY, self::MODE_CONSISTENT, self::MODE_ISOLATED] as $mode) {
            if (!empty($this->todo[$mode])) {
                $this->console->writeLn(C::lyellow('mode: '), $mode);
            }
            $round = 0;
            while (!empty($this->todo[$mode])) {
                $round++;
                foreach ($this->todo[$mode] as $database => $dbKeys) {
                    $this->console->writeLn(C::lyellow('  database: '), $database, C::gray(" (round $round)"));
                    $roundTotal = 0;
                    foreach ($this->todo[$mode][$database] as $table => $tableKeys) {
                        $this->console->write('    ', $table);
                        $prev = isset($this->done[$mode][$database][$table]) ? count($this->done[$mode][$database][$table]) : 0;

                        $this->dumpKeys($mode, $database, $table);

                        $rowCount = count($this->done[$mode][$database][$table]) - $prev;
                        $this->console->writeLn(C::gray(' (' . $rowCount . ($rowCount > 1 ? ' rows)' : ' row)')));
                        $roundTotal += $rowCount;
                    }
                    $this->console->writeLn(C::gray('    (' . $roundTotal . ($roundTotal > 1 ? ' rows in round)' : ' row in round)')));
                    $total += $roundTotal;
                }
            }
        }
        $this->console->ln()->writeLn('Total ' . $total . ($total > 1 ? ' rows exported' : ' row exported'));
    }

    private function enqueueReferrerItems(string $database, string $table, array $columns, array $values)
    {
        $primaryColumns = $this->dbInfo->getPrimaryColumns($database, $table);
        $primary = implode(',', array_map([$this->db, 'quoteName'], $primaryColumns));

        if (count($columns) === 1) {
            $columnsSql = $this->db->quoteName(reset($columns));
            $valuesSql = implode(',', array_map(function ($value) {
                return $this->db->quote(reset($value));
            }, $values));
        } else {
            $columnsSql = '(' . implode(',', array_map([$this->db, 'quoteName'], $columns)) . ')';
            $valuesSql = implode(',', array_map(function ($value) {
                return '(' . implode(',', array_map([$this->db, 'quote'], $value)) . ')';
            }, $values));
        }

        $query = "SELECT " . $primary . " FROM %.% WHERE " . $columnsSql . " IN (" . $valuesSql . ")";

        $result = $this->db->query($query, $database, $table);
        foreach ($result as $row) {
            $this->enqueueItem($database, $table, self::MODE_CONSISTENT, $row);
        }
    }

    private function enqueueByQuery(string $database, string $table, string $where, string $mode)
    {
        $primaryColumns = $this->dbInfo->getPrimaryColumns($database, $table);
        $primary = implode(',', array_map([$this->db, 'quoteName'], $primaryColumns));
        $query = $where
            ? "SELECT " . $primary . " FROM %.% WHERE " . $where
            : "SELECT " . $primary . " FROM %.%";

        $result = $this->db->query($query, $database, $table);
        foreach ($result as $row) {
            $this->enqueueItem($database, $table, $mode, $row);
        }
    }

    private function enqueueItem(string $database, string $table, string $mode, $data)
    {
        $key = count($data) > 1 ? implode('|', $data) : reset($data);
        $value = count($data) > 1 ? array_values($data) : reset($data);

        if ($value === null || isset($this->done[$mode][$database][$table][$key])) {
            return;
        } elseif ($mode === self::MODE_CONSISTENT && isset($this->done[self::MODE_GREEDY][$database][$table][$key])) {
            return;
        }
        $this->todo[$mode][$database][$table][$key] = $value;
    }

    private function dumpKeys(string $mode, string $database, string $table)
    {

        $primaryColumns = $this->dbInfo->getPrimaryColumns($database, $table);
        $primary = count($primaryColumns) > 1
            ? '(' . implode(',', array_map([$this->db, 'quoteName'], $primaryColumns)) . ')'
            : $this->db->quoteName(reset($primaryColumns));

        $references = $this->dbInfo->getForeignKeys($database, $table);

        if ($mode === self::MODE_GREEDY) {
            $backReferences = $this->dbInfo->getReferencesTo($database, $table);
        } elseif ($mode === self::MODE_CONSISTENT) {
            $backReferences = $this->dbInfo->getPrimaryKeyDependencies($database, $table);
            if (isset($this->dependencies[$database][$table])) {
                $dependencies = $this->dbInfo->getReferencesToByTables($database, $table, $this->dependencies[$database][$table]);
                $backReferences += $dependencies;
            }
        } else {
            $backReferences = [];
        }

        $query = sprintf("SELECT * FROM %%.%% WHERE %s IN ", $primary);

        $todo = &$this->todo[$mode][$database][$table];
        $done = &$this->done[$mode][$database][$table];
        if ($done === null) {
            $done = [];
        }

        while (!empty($todo)) {
            $items = $this->take($todo, $done, self::SELECT_BATCH_SIZE);
            $keys = implode(',', array_map(function ($item) {
                return is_array($item) ? '(' . implode(',', array_map([$this->db, 'quote'], $item)) . ')' : $this->db->quote($item);
            }, $items));

            $result = $this->db->query($query . '(' . $keys . ')', $database, $table)->fetchAll();
            $this->writeResult($database, $table, $result);

            $referrers = [];
            foreach ($result as $row) {
                if ($mode === self::MODE_CONSISTENT) {
                    foreach ($references as $fkey => $reference) {
                        list($referencedDatabase, $referencedTable, $referencedColumns, $columns) = $reference;
                        $fkValues = [];
                        foreach ($columns as $i => $column) {
                            $fkValues[$referencedColumns[$i]] = $row[$column];
                        }
                        /// assuming, that foreign key points to primary key, which doesn't have to be true
                        $this->enqueueItem($referencedDatabase, $referencedTable, $mode, $fkValues);
                    }
                }
                foreach ($backReferences as $fkey => $reference) {
                    list(, , , $referencedColumns) = $reference;
                    $fkValues = [];
                    foreach ($referencedColumns as $i => $column) {
                        $fkValues[] = $row[$column];
                    }
                    $referrers[$fkey][] = $fkValues;
                }
            }
            if ($backReferences) {
                foreach ($referrers as $fkey => $values) {
                    list($referencedByDatabase, $referencedByTable, $columns, ) = $backReferences[$fkey];
                    /// assuming, that referenced column cannot contain null, which doesn't have to be true
                    $this->enqueueReferrerItems($referencedByDatabase, $referencedByTable, $columns, $values);
                }
            }
        }
        unset($this->todo[$mode][$database][$table]);
        if (empty($this->todo[$mode][$database])) {
            unset($this->todo[$mode][$database]);
        }
    }

    private function writeResult(string $database, string $table, array $result)
    {
        $columns = $this->dbInfo->getColumns($database, $table);

        $query = "\nINSERT INTO " . $this->db->quoteName($table);
        $columnsSql = implode(',', array_map([$this->db, 'quoteName'], array_keys($columns)));
        $query .= ' (' . $columnsSql . ') VALUES ' . "\n";

        foreach ($result as $row) {
            $values = [];
            foreach ($row as $column => $value) {
                $values[] = $this->db->quote($value, $columns[$column]['type']);
            }
            $valuesSql = implode(',', $values);
            $query .= '  (' . $valuesSql . "),\n";
        }

        $query = substr($query, 0, -2) . ";\n";

        $this->output->write($query, $database, 'data', $table);
    }

    private function take(array &$from, array &$to, int $items): array
    {
        $taken = [];
        $i = 0;
        foreach ($from as $key => $value) {
            $to[$key] = $value;
            unset($from[$key]);
            $taken[$key] = $value;
            $i++;
            if ($i === $items) {
                break;
            }
        }
        return $taken;
    }

}
