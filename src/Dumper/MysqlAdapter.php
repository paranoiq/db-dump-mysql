<?php declare(strict_types = 1);

namespace Dogma\Tools\Dumper;

use Dogma\Tools\SimplePdo;
use Dogma\Tools\SimplePdoResult;

class MysqlAdapter
{

    /** @var \Dogma\Tools\SimplePdo */
    private $db;

    /** @var int[] */
    private $defaultIntSizes = [
        'tinyint' => 4,
        'smallint' => 6,
        'mediumint' => 9,
        'int' => 11,
        'bigint' => 20,
    ];

    public function __construct(SimplePdo $db)
    {
        $this->db = $db;
    }

    public function quote(string $value, ?string $type = null): string
    {
        return $this->db->quote($value, $type);
    }

    public function quoteName(string $name): string
    {
        return $this->db->quoteName($name);
    }

    /**
     * @param string $query
     * @param mixed ...$args
     * @return \Dogma\Tools\SimplePdoResult
     */
    public function query(string $query, ...$args): SimplePdoResult
    {
        return $this->db->query($query, ...$args);
    }

    /**
     * @param string|null $pattern
     * @return string[]
     */
    public function getDatabases(?string $pattern = null): array
    {
        if ($pattern) {
            $query = $this->db->query('SHOW DATABASES LIKE ?', $pattern);
        } else {
            $query = $this->db->query('SHOW DATABASES');
        }
        return $query->fetchColumnAll('Database');
    }

    public function use(string $database): void
    {
        $this->db->exec('USE %', $database);
    }

    /**
     * @param string $database
     * @param string $table
     * @return string[]
     */
    public function getColumnsInfo(string $database, string $table): array
    {
        $query = $this->db->query(
            'SELECT
                COLUMN_NAME AS `name`,
                DATA_TYPE AS type,
                COLUMN_TYPE AS fullType,
                IS_NULLABLE AS nullable,
                CHARACTER_MAXIMUM_LENGTH AS maxLength,
                COLLATION_NAME AS `collation`,
                NUMERIC_PRECISION AS `precision`,
                NUMERIC_SCALE AS scale
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = ?
                AND TABLE_NAME = ?
            ORDER BY ORDINAL_POSITION', $database, $table);

        $columns = [];
        foreach ($query as $row) {
            $columns[$row['name']] = $row;
        }
        return $columns;
    }

    /**
     * @param string $database
     * @param string $table
     * @return string[]
     */
    public function getPrimaryColumns(string $database, string $table): array
    {
        return $this->db->query(
            'SELECT COLUMN_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE CONSTRAINT_NAME = \'PRIMARY\'
                AND TABLE_SCHEMA = ?
                AND TABLE_NAME = ?
            ORDER BY ORDINAL_POSITION', $database, $table)
        ->fetchColumnAll('COLUMN_NAME');
    }

    /**
     * @param string $database
     * @param string $table
     * @return string[][] [$targetSchema, $targetTable, [$targetColumns], [$sourceColumns]]
     */
    public function getForeignKeys(string $database, ?string $table): array
    {
        $result = $this->db->query(
            'SELECT
                CONSTRAINT_NAME AS fkey,
                REFERENCED_TABLE_SCHEMA AS ref_db,
                REFERENCED_TABLE_NAME AS ref_table,
                GROUP_CONCAT(REFERENCED_COLUMN_NAME ORDER BY ORDINAL_POSITION) AS ref_cols,
                GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION) AS cols
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE CONSTRAINT_NAME != \'PRIMARY\'
                AND REFERENCED_TABLE_SCHEMA IS NOT NULL
                AND REFERENCED_TABLE_NAME IS NOT NULL
                AND TABLE_SCHEMA = ?
                AND TABLE_NAME = ?
            GROUP BY CONSTRAINT_NAME, REFERENCED_TABLE_SCHEMA, REFERENCED_TABLE_NAME', $database, $table)
            ->fetchAll();

        $fkeys = [];
        foreach ($result as $row) {
            $fkeys[$row['fkey']] = [$row['ref_db'], $row['ref_table'], explode(',', $row['ref_cols']), explode(',', $row['cols'])];
        }
        return $fkeys;
    }

    /**
     * @param string $database
     * @param string|null $pattern
     * @return string[]
     */
    public function getTables(string $database, ?string $pattern = null): array
    {
        if ($pattern) {
            $query = $this->db->query(
                'SELECT TABLE_NAME FROM information_schema.TABLES
                WHERE TABLE_TYPE = \'BASE TABLE\'
                    AND TABLE_SCHEMA = ?
                    AND TABLE_NAME LIKE ?', $database, $pattern);
        } else {
            $query = $this->db->query(
                'SELECT TABLE_NAME FROM information_schema.TABLES
                WHERE TABLE_TYPE = \'BASE TABLE\'
                    AND TABLE_SCHEMA = ?', $database);
        }

        $list = $query->fetchColumnAll('TABLE_NAME');

        // fallback for system views (information_schema etc.)
        if (count($list) === 0) {
            return $this->db->query('SHOW TABLES FROM %', $database)
                ->fetchColumnAll(0);
        }

        return $list;
    }

    /**
     * @param string $database
     * @param string|null $pattern
     * @return string[]
     */
    public function getViews(string $database, ?string $pattern = null): array
    {
        if ($pattern) {
            $query = $this->db->query(
                'SELECT TABLE_NAME
                FROM information_schema.VIEWS
                WHERE TABLE_SCHEMA = ?
                    AND TABLE_NAME LIKE ?', $database, $pattern);
        } else {
            $query = $this->db->query(
                'SELECT TABLE_NAME
                FROM information_schema.VIEWS
                WHERE TABLE_SCHEMA = ?', $database);
        }
        return $query->fetchColumnAll('TABLE_NAME');
    }

    /**
     * @param string $database
     * @param string|null $pattern
     * @return string[]
     */
    public function getFunctions(string $database, ?string $pattern = null): array
    {
        if ($pattern) {
            $query = $this->db->query(
                'SELECT SPECIFIC_NAME
                FROM information_schema.ROUTINES
                WHERE ROUTINE_TYPE = \'FUNCTION\'
                    AND ROUTINE_SCHEMA = ?
                    AND ROUTINE_NAME LIKE ?', $database, $pattern);
        } else {
            $query = $this->db->query(
                'SELECT SPECIFIC_NAME
                FROM information_schema.ROUTINES
                WHERE ROUTINE_TYPE = \'FUNCTION\'
                    AND ROUTINE_SCHEMA = ?', $database);
        }
        return $query->fetchColumnAll('SPECIFIC_NAME');
    }

    /**
     * @param string $database
     * @param string|null $pattern
     * @return string[]
     */
    public function getProcedures(string $database, ?string $pattern = null): array
    {
        if ($pattern) {
            $query = $this->db->query(
                'SELECT ROUTINE_NAME
                FROM information_schema.ROUTINES
                WHERE ROUTINE_TYPE = \'PROCEDURE\'
                    AND ROUTINE_SCHEMA = ?
                    AND ROUTINE_NAME = ?', $database, $pattern);
        } else {
            $query = $this->db->query(
                'SELECT ROUTINE_NAME
                FROM information_schema.ROUTINES
                WHERE ROUTINE_TYPE = \'PROCEDURE\'
                    AND ROUTINE_SCHEMA = ?', $database);
        }
        return $query->fetchColumnAll('ROUTINE_NAME');
    }

    /**
     * @param string $database
     * @param string|null $pattern
     * @return string[]
     */
    public function getTriggers(string $database, ?string $pattern = null): array
    {
        if ($pattern) {
            $query = $this->db->query(
                'SELECT TRIGGER_NAME
                FROM information_schema.TRIGGERS
                WHERE TRIGGER_SCHEMA = ?
                    AND TRIGGER_NAME LIKE ?', $database, $pattern);
        } else {
            $query = $this->db->query(
                'SELECT TRIGGER_NAME
                FROM information_schema.TRIGGERS
                WHERE TRIGGER_SCHEMA = ?', $database);
        }
        return $query->fetchColumnAll('TRIGGER_NAME');
    }

    /**
     * @param string $database
     * @param string|null $pattern
     * @return string[]
     */
    public function getEvents(string $database, ?string $pattern = null): array
    {
        if ($pattern) {
            $query = $this->db->query(
                'SELECT EVENT_NAME
                FROM information_schema.EVENTS
                WHERE EVENT_SCHEMA = ?
                    AND EVENT_NAME LIKE ?', $database, $pattern);
        } else {
            $query = $this->db->query(
                'SELECT EVENT_NAME
                FROM information_schema.EVENTS
                WHERE EVENT_SCHEMA = ?', $database);
        }
        return $query->fetchColumnAll('EVENT_NAME');
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

    /**
     * @param string $sql
     * @param string[] $charsets
     * @return string
     */
    public function removeCharsets(string $sql, array $charsets): string
    {
        foreach ($charsets as $charset) {
            $sql = preg_replace('/ DEFAULT CHARSET=' . $charset . '/', '', $sql);
        }
        return $sql;
    }

    /**
     * @param string $sql
     * @param string[] $collations
     * @return string
     */
    public function removeCollations(string $sql, array $collations): string
    {
        foreach ($collations as $collation) {
            $sql = preg_replace('/ COLLATE[= ]?' . $collation . '/', '', $sql);
        }
        return $sql;
    }

    public function removeSizes(string $sql, bool $all = false): string
    {
        $sql = preg_replace_callback('/ (tiny|small|medium|big)?int\\((\\d+)\\) /', function ($match) use ($all) {
            $type = $match[1] . 'int';
            if ($all || $this->defaultIntSizes[$type] === (int) $match[2]) {
                return ' ' . $type . ' ';
            } else {
                return $match[0];
            }
        }, $sql);

        return $sql;
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
        return DumpFormatter::formatView($sql);
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

    /**
     * Formats SQL CREATE EVENT statement
     */
    public function formatEvent(string $sql): string
    {
        return DumpFormatter::formatEvent($sql);
    }

}
