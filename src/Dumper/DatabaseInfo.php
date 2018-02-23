<?php declare(strict_types = 1);

namespace Dogma\Tools\Dumper;

class DatabaseInfo
{

    /** @var string[][][] $database => $table => [$columns] */
    private $columns;

    /** @var string[][][] $database => $table => [$primaryKeyColumns] */
    private $primary;

    /** @var string[][][][] $database => $table => $fkey => [$referencedDatabase, $referencedTable, [$referencedColumns], [$columns]] */
    private $foreignKeys;

    /** @var string[][][][] $referencedDatabase => $referencedTable => $fkey => [$database, $table, [$columns], [$referencedColumns]] */
    private $referencesTo;

    /** @var string[][][][] $referencedDatabase => $referencedTable => $fkey => [$database, $table, [$columns], [$referencedColumns]] */
    private $dependencies;

    /** @var \Dogma\Tools\Dumper\MysqlAdapter */
    private $adapter;

    public function __construct(MysqlAdapter $adapter)
    {
        $this->adapter = $adapter;
    }

    /**
     * @param string[] $configDatabases
     * @return int[]
     */
    public function scanStructure(array $configDatabases): array
    {
        $databases = $this->adapter->getDatabases();
        foreach ($configDatabases as $database) {
            if (!in_array($database, $databases)) {
                continue;
            }
            foreach ($this->adapter->getTables($database) as $table) {
                $this->getForeignKeys($database, $table);
            }
        }
        $foreignKeysCount = 0;
        foreach ($this->foreignKeys as $db) {
            foreach ($db as $table) {
                $foreignKeysCount += count($table);
            }
        }
        $dependenciesCount = 0;
        foreach ($this->dependencies as $db) {
            foreach ($db as $table) {
                $dependenciesCount += count($table);
            }
        }
        return [$foreignKeysCount, $dependenciesCount];
    }

    /**
     * @param string $database
     * @param string $table
     * @return string[]
     */
    public function getColumns(string $database, string $table): array
    {
        if (!isset($this->columns[$database][$table])) {
            $this->columns[$database][$table] = $this->adapter->getColumnsInfo($database, $table);
        }
        return $this->columns[$database][$table];
    }

    /**
     * @param string $database
     * @param string $table
     * @return string[]
     */
    public function getPrimaryColumns(string $database, string $table): array
    {
        if (!isset($this->primary[$database][$table])) {
            $this->primary[$database][$table] = $this->adapter->getPrimaryColumns($database, $table);
        }
        return $this->primary[$database][$table];
    }

    /**
     * @param string $database
     * @param string $table
     * @return string[][]
     */
    public function getForeignKeys(string $database, string $table): array
    {
        if (!isset($this->foreignKeys[$database][$table])) {
            $this->foreignKeys[$database][$table] = $this->adapter->getForeignKeys($database, $table);
            foreach ($this->foreignKeys[$database][$table] as $fkey => $data) {
                list($referencedDatabase, $referencedTable, $referencedColumns, $columns) = $data;
                $this->referencesTo[$referencedDatabase][$referencedTable][$fkey] = [$database, $table, $columns, $referencedColumns];
                // 1:1 dependency (multiple table inheritance or something like that)
                if ($this->getPrimaryColumns($database, $table) === $columns) {
                    $this->dependencies[$referencedDatabase][$referencedTable][$fkey] = [$database, $table, $columns, $referencedColumns];
                }
            }
        }
        return $this->foreignKeys[$database][$table];
    }

    /**
     * @param string $database
     * @param string $table
     * @return string[][]
     */
    public function getReferencesTo(string $database, string $table): array
    {
        if (!isset($this->referencesTo[$database][$table])) {
            return [];
        }
        return $this->referencesTo[$database][$table];
    }

    /**
     * @param string $database
     * @param string $table
     * @param string[] $referencingTables
     * @return string[][]
     */
    public function getReferencesToByTables(string $database, string $table, array $referencingTables): array
    {
        if (!isset($this->referencesTo[$database][$table])) {
            return [];
        }
        $references = [];
        foreach ($this->referencesTo[$database][$table] as $fkey => $reference) {
            list ($referencingDatabase, $referencingTable, ) = $reference;
            if ($referencingDatabase === $database && in_array($referencingTable, $referencingTables)) {
                $references[$fkey] = $reference;
            }
        }
        return $references;
    }

    /**
     * @param string $database
     * @param string $table
     * @return string[][]
     */
    public function getPrimaryKeyDependencies(string $database, string $table): array
    {
        if (!isset($this->dependencies[$database][$table])) {
            return [];
        }
        return $this->dependencies[$database][$table];
    }

}
