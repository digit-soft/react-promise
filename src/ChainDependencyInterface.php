<?php

namespace DigitSoft\Promise;


interface ChainDependencyInterface
{
    const SCENARIO_WRITE_ONCE   = 'write_once';
    const SCENARIO_OVERWRITE    = 'write_overwrite';
    const SCENARIO_WRITE_MERGE  = 'write_merge';

    const TYPE_DEFINED_ONLY     = 'defined';
    const TYPE_ARBITRARY        = 'arbitrary';

    /**
     * Add dependency
     * @param mixed  $value
     * @param string $key
     * @return ChainDependency
     */
    public function addDependency($value, $key = null);

    /**
     * Get dependency by key
     * @param string $key
     * @param null   $defaultValue
     * @return mixed|null
     */
    public function getDependency($key, $defaultValue = null);

    /**
     * Check that dependency is set
     * @param string $key
     * @return bool
     */
    public function hasDependency($key);

    /**
     * Set dependencies map.
     * That creates mapped dependency with type static::TYPE_DEFINED_ONLY
     * @param array $map
     * @return $this
     */
    public function setMap($map = []);

    /**
     * Flush dependencies
     * @return $this
     */
    public function flush();
}