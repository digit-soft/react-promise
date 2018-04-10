<?php

namespace DigitSoft\Promise;

/**
 * Class ChainDependency
 * @package DigitSoft\Promise
 */
class ChainDependency implements ChainDependencyInterface
{
    public $scenario            = self::SCENARIO_OVERWRITE;
    public $type                = self::TYPE_ARBITRARY;

    protected $dependencies = [];

    /**
     * Add dependency
     * @param mixed  $value
     * @param string $key
     * @return ChainDependency
     */
    public function addDependency($value, $key = null) {
        if(!isset($key) && is_object($value)) {
            $key = get_class($value);
        }
        return $this->addDependencyInternal($value, $key);
    }

    /**
     * Get dependency by key
     * @param string $key
     * @param null   $defaultValue
     * @return mixed|null
     */
    public function getDependency($key, $defaultValue = null) {
        $key = $this->processKey($key);
        if(!$this->hasDependency($key)) return $defaultValue;
        return $this->dependencies[$key];
    }

    /**
     * Check that dependency is set
     * @param string $key
     * @return bool
     */
    public function hasDependency($key) {
        $key = $this->processKey($key);
        return isset($this->dependencies[$key]);
    }

    /**
     * Set dependencies map.
     * That creates mapped dependency with type static::TYPE_DEFINED_ONLY
     * @param array $map
     * @return $this
     */
    public function setMap($map = []) {
        $this->flush();
        $this->dependencies = array_fill_keys($map, null);
        $this->type = static::TYPE_DEFINED_ONLY;
        return $this;
    }

    /**
     * Flush dependencies
     * @return $this
     */
    public function flush() {
        $this->dependencies = [];
        return $this;
    }

    /**
     * Stringify dependency key
     * @param mixed $key
     * @return mixed|string
     */
    protected function processKey($key) {
        if(is_string($key)) return $key;
        return md5(json_encode($key));
    }

    /**
     * Add dependency
     * @param mixed  $value
     * @param string $key
     * @return $this
     * @internal
     */
    protected function addDependencyInternal($value, $key) {
        $key = $this->processKey($key);
        if($this->type === static::TYPE_DEFINED_ONLY && !array_key_exists($key, $this->dependencies)) return $this;
        switch ($this->scenario) {
            case static::SCENARIO_OVERWRITE:
                $this->dependencies[$key] = $value;
                break;
            case static::SCENARIO_WRITE_ONCE:
                if(!isset($this->dependencies[$key])) $this->dependencies[$key] = $value;
                break;
            case static::SCENARIO_WRITE_MERGE:
                if(!isset($this->dependencies[$key])) {
                    $this->dependencies[$key] = $value;
                } elseif(isset($this->dependencies[$key]['#merged'])) {
                    $this->dependencies[$key]['#value'][] = $value;
                } else {
                    $oldValue = $this->dependencies[$key];
                    $this->dependencies[$key] = [
                        '#merged' => true,
                        '#value' => [$oldValue, $value]
                    ];
                }
                break;
        }
        return $this;
    }
}