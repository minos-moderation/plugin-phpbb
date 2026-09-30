<?php

namespace phpbb\event;

/**
 * Stub of phpBB's event data: the variables of an event, readable and writable by key.
 */
class data implements \ArrayAccess
{
	/** @var array<string,mixed> */
	protected $data;

	/** @param array<string,mixed> $data */
	public function __construct(array $data = array())
	{
		$this->data = $data;
	}

	/** @return array<string,mixed> */
	public function get_data()
	{
		return $this->data;
	}

	#[\ReturnTypeWillChange]
	public function offsetExists($offset)
	{
		return isset($this->data[$offset]);
	}

	#[\ReturnTypeWillChange]
	public function offsetGet($offset)
	{
		return isset($this->data[$offset]) ? $this->data[$offset] : null;
	}

	#[\ReturnTypeWillChange]
	public function offsetSet($offset, $value)
	{
		$this->data[$offset] = $value;
	}

	#[\ReturnTypeWillChange]
	public function offsetUnset($offset)
	{
		unset($this->data[$offset]);
	}
}
