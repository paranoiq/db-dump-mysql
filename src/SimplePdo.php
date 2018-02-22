<?php declare(strict_types = 1);

namespace Dogma\Tools;

/**
 * Provides simple query argument binding:
 * - use ? for automatic value binding
 * - use % for automatic name binding with escaping
 */
class SimplePdo extends \PDO
{

    private static $typeShortcuts = [
        'b' => self::PARAM_BOOL,
        'n' => self::PARAM_NULL,
        'i' => self::PARAM_INT,
        's' => self::PARAM_STR,
        'l' => self::PARAM_LOB,
    ];

    private static $nativeTypes = [
        'bool' => self::PARAM_BOOL,
        'null' => self::PARAM_NULL,
        'int' => self::PARAM_INT,
        'float' => self::PARAM_STR,
        'string' => self::PARAM_STR,
    ];

    public function __construct($dsn, $username, $password, $options = [])
    {
        parent::__construct($dsn, $username, $password, $options);

        // prevents accidental multi-queries
        //$this->setAttribute(self::ATTR_EMULATE_PREPARES, false);
        //$this->setAttribute(self::MYSQL_ATTR_USE_BUFFERED_QUERY, true);

        $this->setAttribute(self::ATTR_ERRMODE, self::ERRMODE_EXCEPTION);
    }

    public function query(string $query, ...$args): SimplePdoResult
    {
        $types = [];
        $counter = 0;
        $query = preg_replace_callback('~\'[^\']*+\'|"[^"]*+"|\?[a-z]?|%~i', function ($match) use (&$counter, &$types, &$args) {
            $match = $match[0];
            $firstChar = substr($match, 0, 1);
            if ($firstChar === '"' || $firstChar === '\'') {
                return $match;
            } elseif ($firstChar === '%') {
                $name = $this->quoteName($args[$counter]);
                unset($args[$counter]);
                $args = array_values($args);
                return $name;
            } elseif (strlen($match) > 1) {
                $types[$counter] = self::$typeShortcuts[substr($match, 1, 1)];
                return ':arg_' . $counter++;
            } else {
                return ':arg_' . $counter++;
            }
        }, $query);

        try {
            if ($counter > 0) {
                $args = array_values($args);
                $statement = $this->prepare($query);
                foreach (array_values($args) as $i => $arg) {
                    $type = $types[$i] ?? self::$nativeTypes[gettype($arg)];
                    $statement->bindParam(':arg_' . $i, $args[$i], $type);
                }
                $statement->execute();
            } else {
                $statement = parent::query($query);
            }
        } catch (\PDOException $e) {
            throw new \PDOException(
                str_replace(
                    'You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use near',
                    'SQL syntax error near',
                    $e->getMessage()
                ) . "; query: $query",
                (int) $e->getCode(),
                $e
            );
        }

        return new SimplePdoResult($statement);
    }

    /**
     * @param string $query
     * @param ...mixed $args
     */
    public function exec($query)
    {
        $args = func_get_args();
        array_shift($args);
        $statement = $this->query($query, ...$args);
        $statement->close();
    }

    public function quote($value, $parameterType = null): string
    {
        if (is_null($value)) {
            return 'NULL';
        } elseif (is_int($value) || is_float($value)) {
            return (string) $value;
        } elseif (is_bool($value)) {
            return $value ? '1' : '0';
        } elseif ($parameterType !== null && $parameterType === 'binary') {
            return 'UNHEX(\'' . bin2hex($value) . '\')';
        } else {
            return parent::quote($value);
        }
    }

    public function quoteName(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

}

class SimplePdoResult implements \Iterator
{
    /** @var \PDOStatement */
    private $statement;

    /** @var int */
    private $key;

    /** @var mixed[] */
    private $current;

    public function __construct(\PDOStatement $statement)
    {
        $this->statement = $statement;
    }

    public function rowCount(): int
    {
        return $this->statement->rowCount();
    }

    public function columnCount(): int
    {
        return $this->statement->columnCount();
    }

    /**
     * @param int $mode
     * @return mixed[]|bool
     */
    public function fetch(int $mode = \PDO::FETCH_ASSOC)
    {
        return $this->statement->fetch($mode);
    }

    /**
     * @param int $mode
     * @return mixed[][]
     */
    public function fetchAll(int $mode = \PDO::FETCH_ASSOC): array
    {
        $result = $this->statement->fetchAll($mode);
        $this->close();
        unset($this->statement);
        return $result;
    }

    /**
     * @param string $column
     * @return mixed|bool
     */
    public function fetchColumn($column)
    {
        return $this->statement->fetch()[$column];
    }

    /**
     * @param int|string $column
     * @return mixed[]
     */
    public function fetchColumnAll($column): array
    {
        $result = $this->statement->fetchAll(is_int($column) ? \PDO::FETCH_NUM : \PDO::FETCH_ASSOC);
        $rows = [];
        foreach ($result as $row) {
            $rows[] = $row[$column];
        }
        return $rows;
    }

    public function close()
    {
        $this->statement->closeCursor();
    }

    public function rewind()
    {
        $this->key = 0;
        $this->current = $this->fetch();
    }

    public function next()
    {
        $this->key++;
        $this->current = $this->fetch();

        return $this->current !== false;
    }

    public function valid(): bool
    {
        if ($this->current === false) {
            $this->statement->closeCursor();
        }
        return $this->current !== false;
    }

    public function current()
    {
        return $this->current;
    }

    public function key()
    {
        return $this->key;
    }

}
