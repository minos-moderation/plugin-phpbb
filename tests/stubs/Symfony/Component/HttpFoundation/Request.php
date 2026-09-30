<?php

namespace Symfony\Component\HttpFoundation;

/**
 * Stub of Symfony's request: the raw body and the headers.
 */
class Request
{
	/** @var HeaderBag */
	public $headers;

	/** @var string */
	protected $content;

	/**
	 * @param array<string,string> $headers Header name => value.
	 * @param string               $content The raw body.
	 */
	public function __construct(array $headers = array(), $content = '')
	{
		$this->headers = new HeaderBag($headers);
		$this->content = (string) $content;
	}

	public function getContent($asResource = false)
	{
		return $this->content;
	}
}
