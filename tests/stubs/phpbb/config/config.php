<?php

namespace phpbb\config;

/**
 * Stub of phpBB's configuration: an array with `set()`.
 */
class config implements \ArrayAccess
{
	/** @var array<string,mixed> */
	protected $values;

	/** @param array<string,mixed> $values */
	public function __construct(array $values = array())
	{
		$this->values = $values;
	}

	#[\ReturnTypeWillChange]
	public function offsetExists($key)
	{
		return isset($this->values[$key]);
	}

	#[\ReturnTypeWillChange]
	public function offsetGet($key)
	{
		return isset($this->values[$key]) ? $this->values[$key] : '';
	}

	#[\ReturnTypeWillChange]
	public function offsetSet($key, $value)
	{
		$this->values[$key] = $value;
	}

	#[\ReturnTypeWillChange]
	public function offsetUnset($key)
	{
		unset($this->values[$key]);
	}

	/**
	 * @param string $key
	 * @param mixed  $value
	 * @param bool   $use_cache
	 * @return void
	 */
	public function set($key, $value, $use_cache = true)
	{
		$this->values[$key] = $value;
	}
}
