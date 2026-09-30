<?php

namespace minos\moderation\tests\Fake;

/**
 * phpBB's template, keeping what was assigned.
 */
class MemoryTemplate implements \phpbb\template\template
{
	/** @var array<string,mixed> */
	public $vars = array();

	/** @var array<string,array<int,array<string,mixed>>> */
	public $blocks = array();

	public function assign_vars(array $vararray)
	{
		$this->vars = array_merge($this->vars, $vararray);
		return $this;
	}

	public function assign_block_vars($blockname, array $vararray)
	{
		$this->blocks[$blockname][] = $vararray;
		return $this;
	}

	/**
	 * Everything assigned, as one string, for "never shown" checks.
	 *
	 * @return string
	 */
	public function everything()
	{
		return json_encode(array($this->vars, $this->blocks), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}
}
