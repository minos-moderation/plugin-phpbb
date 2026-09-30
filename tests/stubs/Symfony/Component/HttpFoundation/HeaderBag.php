<?php

namespace Symfony\Component\HttpFoundation;

/**
 * Stub of Symfony's header bag: case-insensitive names.
 */
class HeaderBag
{
	/** @var array<string,string> */
	protected $headers = array();

	/** @param array<string,string> $headers */
	public function __construct(array $headers = array())
	{
		foreach ($headers as $name => $value)
		{
			$this->headers[strtolower($name)] = (string) $value;
		}
	}

	public function get($key, $default = null)
	{
		$key = strtolower($key);
		return isset($this->headers[$key]) ? $this->headers[$key] : $default;
	}
}
