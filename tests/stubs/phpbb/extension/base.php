<?php

namespace phpbb\extension;

/**
 * Stub of phpBB's extension base class.
 */
class base
{
	/** @var mixed The service container. */
	protected $container;

	public function __construct($container)
	{
		$this->container = $container;
	}

	public function is_enableable()
	{
		return true;
	}
}
