<?php

namespace Symfony\Component\HttpFoundation;

/**
 * Stub of Symfony's request: the raw body, the headers, the path and the method.
 */
class Request
{
	/** @var HeaderBag */
	public $headers;

	/** @var string */
	protected $content;

	/** @var string */
	protected $path_info;

	/** @var string */
	protected $method;

	/**
	 * @param array<string,string> $headers   Header name => value.
	 * @param string               $content   The raw body.
	 * @param string               $path_info The path after the front controller (`app.php`).
	 * @param string               $method    The HTTP method.
	 */
	public function __construct(array $headers = array(), $content = '', $path_info = '/minos/webhook', $method = 'POST')
	{
		$this->headers = new HeaderBag($headers);
		$this->content = (string) $content;
		$this->path_info = (string) $path_info;
		$this->method = strtoupper((string) $method);
	}

	public function getPathInfo()
	{
		return $this->path_info;
	}

	public function isMethod($method)
	{
		return $this->method === strtoupper((string) $method);
	}

	public function getContent($asResource = false)
	{
		return $this->content;
	}
}
