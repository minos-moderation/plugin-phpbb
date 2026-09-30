<?php

namespace phpbb\cron\task;

/**
 * Stub of phpBB's cron task base class.
 */
abstract class base
{
	/** @var string */
	private $name;

	public function get_name()
	{
		return $this->name;
	}

	public function set_name($name)
	{
		$this->name = $name;
	}

	public function is_runnable()
	{
		return true;
	}

	public function should_run()
	{
		return true;
	}

	abstract public function run();
}
