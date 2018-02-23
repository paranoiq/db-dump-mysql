<?php declare(strict_types = 1);

namespace Dogma\Tools;

/**
 * Provides simple query argument binding:
 * - use ? for automatic value binding
 * - use % for automatic name binding with escaping
 */
class SimplePdo extends \PDO
{

	/** @var int[] */
	private static $typeShortcuts = [
		'b' => self::PARAM_BOOL,
		'n' => self::PARAM_NULL,
		'i' => self::PARAM_INT,
		's' => self::PARAM_STR,
		'l' => self::PARAM_LOB,
	];

	/** @var int[] */
	private static $nativeTypes = [
		'bool' => self::PARAM_BOOL,
		'null' => self::PARAM_NULL,
		'int' => self::PARAM_INT,
		'float' => self::PARAM_STR,
		'string' => self::PARAM_STR,
	];

	public function __construct(string $dsn, ?string $username, ?string $password, array $options = [])
	{
		parent::__construct($dsn, $username, $password, $options);

		$this->setAttribute(self::MYSQL_ATTR_USE_BUFFERED_QUERY, true); // does not work with EMULATE_PREPARES = OFF
		//$this->setAttribute(self::ATTR_EMULATE_PREPARES, false); // want this, but it seriously fucks up exec()
		$this->setAttribute(self::ATTR_ERRMODE, self::ERRMODE_EXCEPTION);
	}

	/**
	 * @param string $query
	 * @param mixed ...$args
	 * @return \Dogma\Tools\SimplePdoResult
	 */
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
			$code = $e->getCode();
			// change SQLSTATE to error code
			if (strlen($code) === 5 && preg_match('/\\s[0-9]{4}\\s/', $e->getMessage(), $m)) {
				$code = (int) $m[0];
			} else {
				$code = (int) $code;
			}
			throw new \PDOException(
				str_replace(
					'You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use near',
					'SQL syntax error near',
					$e->getMessage()
				) . '; query: ' . $query,
				$code,
				$e
			);
		}

		return new SimplePdoResult($statement);
	}

	/**
	 * @param string|mixed $query
	 * @param mixed ...$args
	 * @return void
	 */
	public function exec($query, ...$args): void
	{
		$args = func_get_args();
		array_shift($args);
		$statement = $this->query($query, ...$args);
		$statement->close();
	}

	/**
	 * @param mixed $value
	 * @param string|null $parameterType
	 * @return string
	 */
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
