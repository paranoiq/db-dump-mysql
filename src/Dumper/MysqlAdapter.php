<?php

namespace Dogma\Tools\Dumper;

use Dogma\Tools\SimplePdo;
use Dogma\Tools\SimplePdoResult;

class MysqlAdapter
{

    /** @var \Dogma\Tools\SimplePdo */
    private $db;

    public function __construct(SimplePdo $db)
    {
        $this->db = $db;
    }

    public function query(string $query, ...$args): SimplePdoResult
    {
        return $this->db->query($query, ...$args);
    }

    public function getDatabaseList(): array
    {
        return $this->db->query('SHOW DATABASES')
            ->fetchColumnAll('Database');
    }

    public function use(string $database) {
        $this->db->exec('USE %', $database);
    }

    public function getTableList(string $database): array
    {
        $list = $this->db->query(
            'SELECT TABLE_NAME FROM information_schema.TABLES
            WHERE TABLE_TYPE = \'BASE TABLE\'
                AND TABLE_SCHEMA = ?', $database)
            ->fetchColumnAll('TABLE_NAME');

        // fallback for system views (information_schema etc.)
        if (count($list) === 0) {
            return $this->db->query('SHOW TABLES FROM %', $database)
                ->fetchColumnAll(0);
        }

        return $list;
    }

    public function getViewList(string $database): array
    {
        return $this->db->query(
            'SELECT TABLE_NAME
            FROM information_schema.VIEWS
            WHERE TABLE_SCHEMA = ?', $database
        )->fetchColumnAll('TABLE_NAME');
    }

    public function getFunctionList(string $database): array
    {
        return $this->db->query(
            'SELECT SPECIFIC_NAME
            FROM information_schema.ROUTINES
            WHERE ROUTINE_TYPE = \'FUNCTION\'
                AND ROUTINE_SCHEMA = ?', $database
        )->fetchColumnAll('SPECIFIC_NAME');
    }

    public function getProcedureList(string $database): array
    {
        return $this->db->query(
            'SELECT ROUTINE_NAME
            FROM information_schema.ROUTINES
            WHERE ROUTINE_TYPE = \'PROCEDURE\'
                AND ROUTINE_SCHEMA = ?', $database
        )->fetchColumnAll('ROUTINE_NAME');
    }

    public function getTriggerList(string $database): array
    {
        return $this->db->query(
            'SELECT TRIGGER_NAME
            FROM information_schema.TRIGGERS
            WHERE TRIGGER_SCHEMA = ?', $database
        )->fetchColumnAll('TRIGGER_NAME');
    }

    public function getEventList(string $database): array
    {
        return $this->db->query(
            'SELECT EVENT_NAME
            FROM information_schema.EVENTS
            WHERE EVENT_SCHEMA = ?', $database
        )->fetchColumnAll('EVENT_NAME');
    }

    /**
     * Creates SQL CREATE TABLE statement
     */
    public function dumpTableStructure(string $tableName): string
    {
        $sql = $this->db->query('SHOW CREATE TABLE %', $tableName)
            ->fetchColumn('Create Table');

        $sql = preg_replace('~AUTO_INCREMENT=\\d+ ~i', '', $sql, 1);

        return $sql . ';';
    }

    public function removeCharsets(string $sql, array $charsets): string
    {
        foreach ($charsets as $charset) {
            $sql = preg_replace('/ DEFAULT CHARSET=' . $charset . '/', '', $sql);
        }
        return $sql;
    }

    public function removeCollations(string $sql, array $collations): string
    {
        foreach ($collations as $collation) {
            $sql = preg_replace('/ COLLATE[= ]?' . $collation . '/', '', $sql);
        }
        return $sql;
    }

    /**
     * Creates SQL INSERT statement for table data
     */
    public function dumpTableData(string $tableName): string
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
     * Creates SQL CREATE VIEW statement
     */
    public function dumpViewStructure(string $viewName): string
    {
        return $this->db->query('SHOW CREATE VIEW %', $viewName)
            ->fetchColumn('Create View') . ';';
    }

    /**
     * Formats SQL CREATE VIEW statement
     */
    public function formatView(string $sql): string
    {
        return ViewFormatter::format($sql);
    }

    /**
     * Creates SQL CREATE FUNCTION statement
     */
    public function dumpFunctionStructure(string $functionName): string
    {
        return $this->db->query('SHOW CREATE FUNCTION %', $functionName)
            ->fetchColumn('Create Function') . ';';
    }

    /**
     * Creates SQL CREATE PROCEDURE statement
     */
    public function dumpProcedureStructure(string $procedureName): string
    {
        return $this->db->query('SHOW CREATE PROCEDURE %', $procedureName)
            ->fetchColumn('Create Procedure') . ';';
    }

    /**
     * Creates SQL CREATE TRIGGER statement
     */
    public function dumpTriggerStructure(string $triggerName): string
    {
        return $this->db->query('SHOW CREATE TRIGGER %', $triggerName)
            ->fetchColumn('SQL Original Statement') . ';';
    }

    /**
     * Creates SQL CREATE EVENT statement
     */
    public function dumpEventStructure(string $eventName): string
    {
        return $this->db->query('SHOW CREATE EVENT %', $eventName)
            ->fetchColumn('Create Event') . ';';
    }

}
