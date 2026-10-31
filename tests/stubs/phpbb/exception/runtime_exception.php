<?php

namespace phpbb\exception;

/**
 * Stub of phpBB's runtime exception (a language key as its message).
 */
class runtime_exception extends \RuntimeException
{
	/** @var array<int,mixed> */
	private $parameters;

	public function __construct($message = "", array $parameters = array(), ?\Exception $previous = null, $code = 0)
	{
		$this->parameters = $parameters;
		parent::__construct($message, $code, $previous);
	}

	public function get_parameters()
	{
		return $this->parameters;
	}
}
