<?php declare(strict_types = 1);

namespace Dogma\Tools;

class ConfigurationProfile extends \stdClass
{

    /** @var mixed[] */
    private $values;

    public function __construct(array $values)
    {
        $this->values = $values;
    }

    /**
     * @param string $name
     * @return mixed|null
     */
    public function __get(string $name)
    {
        if (isset($this->values[$name])) {
            return $this->values[$name];
        } else {
            return null;
        }
    }

}
