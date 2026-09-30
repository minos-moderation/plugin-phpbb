<?php

namespace phpbb\config;

/**
 * Stub of phpBB's `config_text`, in memory.
 */
class db_text
{
	/** @var array<string,string> */
	public $values = array();

	/** @var int How many reads reached the "database". */
	public $reads = 0;

	/**
	 * @param string $key
	 * @param string $value
	 * @return void
	 */
	public function set($key, $value)
	{
		$this->values[$key] = (string) $value;
	}

	/**
	 * @param string $key
	 * @return string|null
	 */
	public function get($key)
	{
		$this->reads++;
		return isset($this->values[$key]) ? $this->values[$key] : null;
	}
}
