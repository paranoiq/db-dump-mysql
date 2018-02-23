<?php declare(strict_types = 1);

namespace Dogma\Tools;

use Dogma\Tools\Colors as C;
use Nette\Neon\Neon;

final class Configurator extends \stdClass
{

    public const FLAG = 'bool';
    public const VALUE = 'value';
    public const VALUES = 'values';
    public const ENUM = 'enum';
    public const SET = 'set';

    /** @var string[]|string[][] */
    private $arguments;

    /** @var mixed[] */
    private $defaults;

    /** @var mixed[] */
    private $values = [];

    public function __construct(array $arguments, array $defaults = [])
    {
        $this->arguments = $arguments;
        $this->defaults = $defaults;
    }

    public function hasValues(): bool
    {
        foreach ($this->values as $value) {
            if ($value !== null) {
                return true;
            }
        }
        return false;
    }

    public function renderHelp(): string
    {
        $guide = '';
        foreach ($this->arguments as $name => $config) {
            if (is_string($config)) {
                $guide .= "$config\n";
                continue;
            }
            $row = '';
            @list($short, $type, $info, $hint, $values) = $config;
            $row .= $short ? C::white("  -$short") : '    ';
            $row .= C::white(" --$name");
            if ($type === self::VALUE || $type === self::VALUES || $type === self::ENUM || $type === self::SET) {
                $row .= C::gray($hint ? " <$hint>" : ' <value>');
            }
            $row = C::padString($row, 23);
            $row .= ' ' . $info;
            if ($type === self::ENUM || $type === self::SET) {
                $row .= '; values: ' . implode('|', array_map([C::class, 'lyellow'], $values));
            }
            if (isset($this->defaults[$name])) {
                if ($type === self::VALUES || $type === self::SET) {
                    $row .= '; default: ' . implode(',', array_map(function ($value) {
                        return C::lyellow($this->format($value));
                    }, $this->defaults[$name]));
                } else {
                    $row .= '; default: ' . C::lyellow($this->format($this->defaults[$name]));
                }
            }
            $guide .= $row . "\n";
        }

        return $guide . "\n";
    }

    public function loadCliArguments(): void
    {
        $short = [];
        $long = [];
        foreach ($this->arguments as $name => $config) {
            if (is_string($config)) {
                continue;
            }
            list($shortcut, $type, ) = $config;
            if ($shortcut) {
                $short[] = $shortcut . ($type === self::FLAG ? '' : ':');
            }
            $long[] = $name . ($type === self::FLAG ? '' : ':');
        }

        $values = getopt(implode('', $short), $long);
        foreach ($this->arguments as $name => list($shortcut, $type, )) {
            if (is_numeric($name)) {
                continue;
            }
            if (isset($values[$name])) {
                $value = $values[$name];
            } else {
                $value = null;
                if (isset($values[$shortcut])) {
                    $value = $values[$shortcut];
                }
            }
            if ($value === false) {
                $value = true;
            }
            $value = $this->normalize($value, $type);
            $values[$name] = $value;
            unset($values[$shortcut]);
        }
        $this->values = $values;
    }

    public function loadConfig(string $filePath): void
    {
        if (is_file($filePath)) {
            if (substr($filePath, -5) === '.neon') {
                $config = Neon::decode(file_get_contents($filePath));
            } elseif (substr($filePath, -4) === '.ini') {
                $config = parse_ini_file($filePath);
            } else {
                die("Error: Only .neon and .ini files are supported!\n\n");
            }
        } elseif ($filePath) {
            die(sprintf("Configuration file %s not found.\n\n", $filePath));
        } else {
            $config = [];
        }
        foreach ($this->arguments as $name => list(, $type, )) {
            if (isset($config[$name]) && !isset($this->values[$name])) {
                $this->values[$name] = $this->normalize($config[$name]);
            }
        }
    }

    /**
     * @param mixed $value
     * @param string|null $type
     * @return mixed
     */
    private function normalize($value, ?string $type = null)
    {
        if (($type === self::VALUES || $type === self::SET) && is_string($value)) {
            $value = explode(',', $value);
            foreach ($value as &$item) {
                $item = $this->normalize($item);
            }
        } elseif (is_numeric($value)) {
            $value = (float) $value;
            if ($value === (float) (int) $value) {
                $value = (int) $value;
            }
        }

        return $value;
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function format($value): string
    {
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }
        return (string) $value;
    }

    /**
     * @param string $name
     * @return mixed|null
     */
    public function __get(string $name)
    {
        if (!array_key_exists($name, $this->values)) {
            trigger_error(sprintf('Value "%s" not found.', $name));
            return null;
        }
        if (!isset($this->values[$name]) && isset($this->defaults[$name])) {
            return $this->defaults[$name];
        } else {
            return $this->values[$name];
        }
    }

}
